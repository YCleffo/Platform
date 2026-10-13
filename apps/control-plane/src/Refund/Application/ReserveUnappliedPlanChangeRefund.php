<?php

declare(strict_types=1);

namespace ControlPlane\Refund\Application;

use ControlPlane\Billing\Domain\Money;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Reserve a full refund with the existing provider reconciliation workflow. */
final readonly class ReserveUnappliedPlanChangeRefund
{
    public function reserveInTransaction(Connection $db, string $changeId, string $invoiceId,
        string $accountId, string $correlationId): ?string
    {
        $payment = $db->fetchAssociative(<<<'SQL'
SELECT p.id, p.amount_minor, p.currency, pa.provider_account_id, ppa.receipt_mode,
       ppa.receipt_vat_code, ppa.receipt_payment_subject, ppa.receipt_payment_mode
FROM commercial.payments p
JOIN commercial.payment_attempts pa ON pa.payment_id = p.id AND pa.status = 'succeeded'
JOIN commercial.payment_provider_accounts ppa ON ppa.id = pa.provider_account_id
WHERE p.invoice_id = ? AND p.commercial_account_id = ? AND p.status = 'succeeded'
  AND ppa.provider_code = 'yookassa'
FOR UPDATE OF p, pa, ppa
SQL, [$invoiceId, $accountId]);
        if ($payment === false || $payment['receipt_mode'] !== 'yookassa'
            || $payment['receipt_vat_code'] === null
            || !is_string($payment['receipt_payment_subject'])
            || !is_string($payment['receipt_payment_mode'])) {
            return null;
        }
        $line = $db->fetchAssociative('SELECT id, description, total_minor FROM commercial.invoice_lines WHERE invoice_id = ? FOR UPDATE', [$invoiceId]);
        $amount = Money::nonNegativeBigInt($payment['amount_minor']);
        if ($line === false || !is_string($line['id']) || !is_string($line['description'])
            || Money::nonNegativeBigInt($line['total_minor']) !== $amount || $amount === 0) {
            throw new \LogicException('Unapplied plan change refund does not match its invoice.');
        }
        $key = 'plan-change-unapplied-' . $changeId;
        $existing = $db->fetchOne('SELECT id FROM commercial.refunds WHERE payment_id = ? AND client_request_key = ? FOR UPDATE', [$payment['id'], $key]);
        if (is_string($existing)) {
            return $existing;
        }
        $refundId = Uuid::v7()->toRfc4122();
        $hash = hash('sha256', json_encode([$changeId, $payment['id'], $line['id'], $amount], JSON_THROW_ON_ERROR));
        $db->executeStatement(<<<'SQL'
INSERT INTO commercial.refunds
 (id, payment_id, commercial_account_id, provider_account_id, amount_minor, currency,
  client_request_key, request_sha256, provider_request_key)
VALUES (?, ?, ?, ?, ?, ?, ?, decode(?, 'hex'), ?)
SQL, [$refundId, $payment['id'], $accountId, $payment['provider_account_id'], $amount,
            $payment['currency'], $key, $hash, Uuid::v7()->toRfc4122()]);
        $db->executeStatement(<<<'SQL'
INSERT INTO commercial.refund_items
 (refund_id, invoice_line_id, description, quantity, unit_amount_minor, total_minor,
  currency, vat_code, payment_subject, payment_mode)
VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?)
SQL, [$refundId, $line['id'], $line['description'], $amount, $amount, $payment['currency'],
            $payment['receipt_vat_code'], $payment['receipt_payment_subject'], $payment['receipt_payment_mode']]);
        $db->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, 'refund.plan_change_reserved', 'refund', ?, ?, 'success')", [$accountId, $refundId, $correlationId]);
        $db->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('refund.created', 'refund', ?, jsonb_build_object('refund_id', ?::text, 'payment_id', ?::text, 'commercial_account_id', ?::text, 'amount_minor', ?::bigint, 'currency', ?::text))", [$refundId, $refundId, $payment['id'], $accountId, $amount, $payment['currency']]);

        return $refundId;
    }
}
