<?php

declare(strict_types=1);

namespace ControlPlane\Refund\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Billing\Domain\Money;
use ControlPlane\Shared\Domain\IdentifierPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class CreateRefund
{
    public function __construct(private Connection $connection, private AccountAuthorization $authorization)
    {
    }

    /** @param list<array{invoice_line_id: string, quantity: int, unit_amount_minor: int}> $allocations
     * @return array<string, mixed>
     */
    public function create(string $userId, string $accountId, string $paymentId, array $allocations, string $idempotencyKey, string $correlationId): array
    {
        if (!Uuid::isValid($userId) || !Uuid::isValid($accountId) || !Uuid::isValid($paymentId) || !Uuid::isValid($correlationId) || $allocations === []) {
            throw new \InvalidArgumentException('Invalid refund request.');
        }
        $idempotencyKey = IdentifierPolicy::opaqueKey($idempotencyKey);
        $canonical = [];
        foreach ($allocations as $allocation) {
            $keys = array_keys($allocation);
            sort($keys);
            if ($keys !== ['invoice_line_id', 'quantity', 'unit_amount_minor']
                || !Uuid::isValid($allocation['invoice_line_id']) || $allocation['quantity'] < 1 || $allocation['unit_amount_minor'] < 0) {
                throw new \InvalidArgumentException('Invalid refund item allocation.');
            }
            if (isset($canonical[strtolower($allocation['invoice_line_id'])])) {
                throw new \InvalidArgumentException('A refund invoice line may only appear once.');
            }
            $canonical[strtolower($allocation['invoice_line_id'])] = ['invoice_line_id' => strtolower($allocation['invoice_line_id']), 'quantity' => $allocation['quantity'], 'unit_amount_minor' => $allocation['unit_amount_minor']];
        }
        ksort($canonical);
        $allocations = array_values($canonical);
        $hash = hash('sha256', json_encode(['payment_id' => strtolower($paymentId), 'items' => $allocations], JSON_THROW_ON_ERROR));

        return $this->connection->transactional(function (Connection $connection) use ($userId, $accountId, $paymentId, $allocations, $idempotencyKey, $correlationId, $hash): array {
            $this->authorization->requirePermission($userId, $accountId, 'billing.manage');
            $payment = $connection->fetchAssociative(<<<'SQL'
SELECT p.id, p.invoice_id, p.commercial_account_id, p.amount_minor, p.currency, p.status,
       pa.provider_account_id, pa.provider_payment_id,
       ppa.receipt_mode, ppa.receipt_vat_code, ppa.receipt_payment_subject, ppa.receipt_payment_mode
FROM commercial.payments p
JOIN commercial.payment_attempts pa ON pa.payment_id = p.id AND pa.status = 'succeeded'
JOIN commercial.payment_provider_accounts ppa ON ppa.id = pa.provider_account_id
WHERE p.id = ? AND p.commercial_account_id = ?
FOR UPDATE OF p, pa, ppa
SQL, [$paymentId, $accountId]);
            if ($payment === false || $payment['status'] !== 'succeeded' || !is_string($payment['provider_payment_id'])) {
                throw new \DomainException('Refund requires a succeeded provider payment.');
            }
            $existing = $connection->fetchAssociative("SELECT *, encode(request_sha256, 'hex') AS request_hash FROM commercial.refunds WHERE payment_id = ? AND client_request_key = ? FOR UPDATE", [$paymentId, $idempotencyKey]);
            if ($existing !== false) {
                if (!is_string($existing['request_hash']) || !hash_equals($existing['request_hash'], $hash)) {
                    throw new \DomainException('Idempotency key was reused with a different request.');
                }

                return self::normalize($existing);
            }
            if ($connection->fetchOne("SELECT 1 FROM commercial.external_compensation_attestations WHERE payment_id=? AND status IN ('proposed','approved') LIMIT 1", [$paymentId]) !== false) {
                throw new \DomainException('External compensation already claims this payment.');
            }
            if ($payment['receipt_mode'] !== 'yookassa' || $payment['receipt_vat_code'] === null || !is_string($payment['receipt_payment_subject']) || !is_string($payment['receipt_payment_mode'])) {
                throw new \DomainException('Refund fiscal configuration is incomplete.');
            }
            $lines = [];
            $total = 0;
            foreach ($allocations as $allocation) {
                $line = $connection->fetchAssociative('SELECT id, description, quantity, unit_amount_minor, total_minor FROM commercial.invoice_lines WHERE id = ? AND invoice_id = ? FOR UPDATE', [$allocation['invoice_line_id'], $payment['invoice_id']]);
                if ($line === false || (int) $line['quantity'] < $allocation['quantity'] || Money::nonNegativeBigInt($line['unit_amount_minor']) < $allocation['unit_amount_minor']) {
                    throw new \DomainException('Refund item does not match the immutable invoice line.');
                }
                $lineTotal = Money::multiply($allocation['unit_amount_minor'], $allocation['quantity']);
                if ($lineTotal <= 0) {
                    throw new \DomainException('Refund item total must be positive.');
                }
                $already = Money::nonNegativeBigInt($connection->fetchOne("SELECT COALESCE(sum(ri.total_minor), 0) FROM commercial.refund_items ri JOIN commercial.refunds r ON r.id = ri.refund_id WHERE r.payment_id = ? AND ri.invoice_line_id = ? AND r.status IN ('pending','succeeded')", [$paymentId, $line['id']]));
                if (Money::add($already, $lineTotal) > Money::nonNegativeBigInt($line['total_minor'])) {
                    throw new \DomainException('Refund amount exceeds the refundable invoice line balance.');
                }
                $total = Money::add($total, $lineTotal);
                $lines[] = [$line, $allocation['quantity'], $allocation['unit_amount_minor'], $lineTotal];
            }
            $refunded = Money::nonNegativeBigInt($connection->fetchOne("SELECT COALESCE(sum(amount_minor), 0) FROM commercial.refunds WHERE payment_id = ? AND status IN ('pending','succeeded')", [$paymentId]));
            if ($total <= 0 || Money::add($refunded, $total) > Money::nonNegativeBigInt($payment['amount_minor'])) {
                throw new \DomainException('Refund exceeds the refundable payment balance.');
            }
            $refundId = Uuid::v7()->toRfc4122();
            $providerKey = Uuid::v7()->toRfc4122();
            $row = $connection->fetchAssociative(<<<'SQL'
INSERT INTO commercial.refunds
 (id, payment_id, commercial_account_id, provider_account_id, amount_minor, currency, client_request_key, request_sha256, provider_request_key)
VALUES (?, ?, ?, ?, ?, ?, ?, decode(?, 'hex'), ?)
RETURNING *
SQL, [$refundId, $paymentId, $accountId, $payment['provider_account_id'], $total, $payment['currency'], $idempotencyKey, $hash, $providerKey]);
            if ($row === false) {
                throw new \LogicException('Created refund state is unavailable.');
            }
            foreach ($lines as [$line, $quantity, $unit, $lineTotal]) {
                $connection->executeStatement(<<<'SQL'
INSERT INTO commercial.refund_items
 (refund_id, invoice_line_id, description, quantity, unit_amount_minor, total_minor, currency, vat_code, payment_subject, payment_mode)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL, [$refundId, $line['id'], $line['description'], $quantity, $unit, $lineTotal, $payment['currency'], $payment['receipt_vat_code'], $payment['receipt_payment_subject'], $payment['receipt_payment_mode']]);
            }
            $connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, actor_id, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('control_user', ?, ?, 'refund.created', 'refund', ?, ?, 'success')", [$userId, $accountId, $refundId, $correlationId]);
            $connection->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('refund.created', 'refund', ?, jsonb_build_object('refund_id', ?::text, 'payment_id', ?::text, 'commercial_account_id', ?::text, 'amount_minor', ?::bigint, 'currency', ?::text))", [$refundId, $refundId, $paymentId, $accountId, $total, $payment['currency']]);

            return self::normalize($row);
        });
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function normalize(array $row): array
    {
        return ['id' => $row['id'], 'payment_id' => $row['payment_id'], 'amount_minor' => Money::nonNegativeBigInt($row['amount_minor']), 'currency' => $row['currency'], 'status' => $row['status'], 'provider_refund_id' => $row['provider_refund_id'], 'created_at' => $row['created_at'], 'completed_at' => $row['completed_at']];
    }
}
