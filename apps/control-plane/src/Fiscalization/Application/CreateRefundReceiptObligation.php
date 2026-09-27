<?php

declare(strict_types=1);

namespace ControlPlane\Fiscalization\Application;

use ControlPlane\Identity\Domain\EmailPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class CreateRefundReceiptObligation
{
    public function createInTransaction(Connection $connection, string $refundId, string $correlationId): ?string
    {
        $row = $connection->fetchAssociative(<<<'SQL'
SELECT r.*, p.invoice_id, i.bill_to_contact_email, i.bill_to_contact_name,
       ppa.receipt_mode, ppa.receipt_vat_code, ppa.receipt_payment_subject, ppa.receipt_payment_mode
FROM commercial.refunds r
JOIN commercial.payments p ON p.id = r.payment_id
JOIN commercial.invoices i ON i.id = p.invoice_id
JOIN commercial.payment_provider_accounts ppa ON ppa.id = r.provider_account_id
WHERE r.id = ?
SQL, [$refundId]);
        if ($row === false || $row['status'] !== 'succeeded' || !is_string($row['provider_refund_id'])) {
            throw new \DomainException('Refund receipt requires an authoritative succeeded refund.');
        }
        if ($row['receipt_mode'] === 'disabled') {
            return null;
        }
        if ($row['receipt_mode'] !== 'yookassa' || $row['receipt_vat_code'] === null || !is_string($row['receipt_payment_subject']) || !is_string($row['receipt_payment_mode']) || !is_string($row['bill_to_contact_email'])) {
            throw new \DomainException('Refund receipt fiscal configuration is incomplete.');
        }
        $existing = $connection->fetchOne("SELECT id FROM commercial.receipts WHERE refund_id = ? AND receipt_type = 'refund'", [$refundId]);
        if (is_string($existing)) {
            return $existing;
        }
        $receiptId = Uuid::v7()->toRfc4122();
        $connection->executeStatement(<<<'SQL'
INSERT INTO commercial.receipts
 (id, payment_id, invoice_id, commercial_account_id, payment_attempt_id, refund_id, provider_account_id,
  receipt_type, provider_request_key, amount_minor, currency, customer_email, customer_name, vat_code, payment_subject, payment_mode)
VALUES (?, ?, ?, ?, NULL, ?, ?, 'refund', ?, ?, ?, ?, ?, ?, ?, ?)
SQL, [$receiptId, $row['payment_id'], $row['invoice_id'], $row['commercial_account_id'], $refundId, $row['provider_account_id'], Uuid::v7()->toRfc4122(), $row['amount_minor'], $row['currency'], EmailPolicy::normalize($row['bill_to_contact_email'])['email'], is_string($row['bill_to_contact_name']) ? trim($row['bill_to_contact_name']) : null, $row['receipt_vat_code'], $row['receipt_payment_subject'], $row['receipt_payment_mode']]);
        $count = $connection->executeStatement(<<<'SQL'
INSERT INTO commercial.receipt_items
 (receipt_id, invoice_line_id, description, quantity, unit_amount_minor, total_minor, currency, vat_code, payment_subject, payment_mode)
SELECT ?, invoice_line_id, description, quantity, unit_amount_minor, total_minor, currency, vat_code, payment_subject, payment_mode
FROM commercial.refund_items WHERE refund_id = ? ORDER BY created_at, id
SQL, [$receiptId, $refundId]);
        if ($count < 1) {
            throw new \LogicException('Refund receipt item snapshot is empty.');
        }
        $connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('system', ?, 'receipt.refund_created', 'receipt', ?, ?, 'success')", [$row['commercial_account_id'], $receiptId, $correlationId]);
        $connection->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('receipt.refund_created', 'receipt', ?, jsonb_build_object('receipt_id', ?::text, 'refund_id', ?::text, 'payment_id', ?::text))", [$receiptId, $receiptId, $refundId, $row['payment_id']]);

        return $receiptId;
    }
}
