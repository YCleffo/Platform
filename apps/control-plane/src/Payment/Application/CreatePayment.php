<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Billing\Domain\Money;
use ControlPlane\Shared\Domain\IdentifierPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class CreatePayment
{
    private PaymentCreator $creator;

    public function __construct(private Connection $connection, private AccountAuthorization $authorization, private SettlePayment $settlement, ?PaymentCreator $creator = null)
    {
        $this->creator = $creator ?? new PaymentCreator($settlement);
    }

    /** @return array<string, mixed> */
    public function create(string $controlUserId, string $commercialAccountId, string $invoiceId, string $idempotencyKey, string $correlationId): array
    {
        if (!Uuid::isValid($controlUserId) || !Uuid::isValid($commercialAccountId) || !Uuid::isValid($invoiceId) || !Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid public identifier.');
        }
        $idempotencyKey = IdentifierPolicy::opaqueKey($idempotencyKey);
        $scope = 'payment.create.' . str_replace('-', '', strtolower($commercialAccountId)) . '.' . str_replace('-', '', strtolower($invoiceId));
        $hash = hash('sha256', json_encode(['operation' => 'payment.create', 'commercial_account_id' => strtolower($commercialAccountId), 'invoice_id' => strtolower($invoiceId)], JSON_THROW_ON_ERROR));

        return $this->connection->transactional(function (Connection $connection) use ($controlUserId, $commercialAccountId, $invoiceId, $idempotencyKey, $correlationId, $scope, $hash): array {
            $this->authorization->requirePermission($controlUserId, $commercialAccountId, 'billing.manage');
            $subscriptionId = $connection->fetchOne('SELECT subscription_id FROM commercial.invoices WHERE id = ? AND commercial_account_id = ?', [$invoiceId, $commercialAccountId]);
            if (!is_string($subscriptionId)) {
                throw new \DomainException('Invoice was not found.');
            }
            if ($connection->fetchOne('SELECT id FROM commercial.subscriptions WHERE id = ? AND commercial_account_id = ? FOR UPDATE', [$subscriptionId, $commercialAccountId]) === false) {
                throw new \DomainException('Invoice subscription was not found.');
            }
            $invoice = $connection->fetchAssociative('SELECT id, subscription_id, commercial_account_id, billing_reason, service_period_start, service_period_end, status, currency, total_minor FROM commercial.invoices WHERE id = ? AND commercial_account_id = ? FOR UPDATE', [$invoiceId, $commercialAccountId]);
            if ($invoice === false) {
                throw new \DomainException('Invoice was not found.');
            }

            $connection->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$idempotencyKey]);
            if ($connection->fetchOne('SELECT scope FROM commercial.idempotency_keys WHERE idempotency_key = ? AND scope <> ? LIMIT 1', [$idempotencyKey, $scope]) !== false) {
                throw new \DomainException('Idempotency key was reused for a different request.');
            }
            $inserted = $connection->executeStatement("INSERT INTO commercial.idempotency_keys (scope, idempotency_key, request_sha256, status, expires_at) VALUES (?, ?, decode(?, 'hex'), 'pending', now() + interval '24 hours') ON CONFLICT (scope, idempotency_key) DO NOTHING", [$scope, $idempotencyKey, $hash]);
            $idempotency = $connection->fetchAssociative("SELECT encode(request_sha256, 'hex') AS request_hash, status, result_reference FROM commercial.idempotency_keys WHERE scope = ? AND idempotency_key = ? FOR UPDATE", [$scope, $idempotencyKey]);
            if ($idempotency === false || !is_string($idempotency['request_hash'])) {
                throw new \LogicException('Idempotency record unavailable.');
            }
            if (!hash_equals($idempotency['request_hash'], $hash)) {
                throw new \DomainException('Idempotency key was reused with a different request.');
            }
            if ($inserted === 0) {
                if ($idempotency['status'] !== 'completed' || !is_string($idempotency['result_reference'])) {
                    throw new \DomainException('Idempotency operation is already in progress.');
                }

                return $this->result($connection, $commercialAccountId, $invoiceId, $idempotency['result_reference']);
            }

            $existing = $connection->fetchAssociative('SELECT id, invoice_id, commercial_account_id, status, currency, amount_minor, created_at, succeeded_at FROM commercial.payments WHERE invoice_id = ? FOR UPDATE', [$invoiceId]);
            if ($existing !== false) {
                if (!is_string($existing['id'])) {
                    throw new \LogicException('Payment identity is unavailable.');
                }
                $connection->executeStatement("UPDATE commercial.idempotency_keys SET status = 'completed', result_reference = ? WHERE scope = ? AND idempotency_key = ?", [$existing['id'], $scope, $idempotencyKey]);

                return $this->normalize($existing);
            }
            $payment = $this->creator->createInTransaction($connection, $invoice, $correlationId, 'control_user', $controlUserId);
            $result = $this->normalize($payment);
            $connection->executeStatement("UPDATE commercial.idempotency_keys SET status = 'completed', result_reference = ? WHERE scope = ? AND idempotency_key = ?", [$payment['id'], $scope, $idempotencyKey]);

            return $result;
        });
    }

    /** @return array<string, mixed> */
    private function result(Connection $connection, string $accountId, string $invoiceId, string $paymentId): array
    {
        $payment = $connection->fetchAssociative('SELECT id, invoice_id, status, currency, amount_minor, created_at, succeeded_at FROM commercial.payments WHERE id = ? AND commercial_account_id = ? AND invoice_id = ?', [$paymentId, $accountId, $invoiceId]);
        if ($payment === false) {
            throw new \DomainException('Idempotent payment result was not found.');
        }

        return $this->normalize($payment);
    }

    /** @param array<string, mixed> $payment
     * @return array<string, mixed>
     */
    private function normalize(array $payment): array
    {
        if (!is_string($payment['id']) || !is_string($payment['invoice_id']) || !is_string($payment['status'])
            || !is_string($payment['currency']) || !is_string($payment['created_at'])) {
            throw new \LogicException('Payment result is invalid.');
        }

        return [
            'id' => $payment['id'],
            'invoice_id' => $payment['invoice_id'],
            'status' => $payment['status'],
            'currency' => $payment['currency'],
            'amount_minor' => Money::nonNegativeBigInt($payment['amount_minor']),
            'created_at' => $payment['created_at'],
            'succeeded_at' => $payment['succeeded_at'],
        ];
    }
}
