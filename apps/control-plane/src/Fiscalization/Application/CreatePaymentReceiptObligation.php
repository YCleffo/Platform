<?php

declare(strict_types=1);

namespace ControlPlane\Fiscalization\Application;

use ControlPlane\Billing\Domain\Money;
use ControlPlane\Identity\Domain\EmailPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class CreatePaymentReceiptObligation
{
    public function createInTransaction(Connection $connection, string $attemptId): ?string
    {
        $row = $connection->fetchAssociative(<<<'SQL'
SELECT pa.id AS attempt_id, pa.provider_payment_id, pa.status AS attempt_status, pa.provider_account_id,
       p.id AS payment_id, p.invoice_id, p.commercial_account_id, p.amount_minor, p.currency, p.status AS payment_status,
       i.status AS invoice_status, i.bill_to_contact_email, i.bill_to_contact_name,
       ppa.receipt_mode, ppa.receipt_vat_code, ppa.receipt_payment_subject, ppa.receipt_payment_mode
FROM commercial.payment_attempts pa
JOIN commercial.payments p ON p.id = pa.payment_id
JOIN commercial.invoices i ON i.id = p.invoice_id
JOIN commercial.payment_provider_accounts ppa ON ppa.id = pa.provider_account_id
WHERE pa.id = ?
SQL, [$attemptId]);
        if ($row === false) {
            throw new \LogicException('Receipt obligation source is unavailable.');
        }
        if (Money::nonNegativeBigInt($row['amount_minor']) === 0 || $row['receipt_mode'] === 'disabled') {
            return null;
        }
        if ($row['attempt_status'] !== 'succeeded' || !is_string($row['provider_payment_id'])
            || $row['payment_status'] !== 'succeeded' || $row['invoice_status'] !== 'paid'
            || $row['receipt_mode'] !== 'yookassa' || $row['receipt_vat_code'] === null
            || !is_string($row['receipt_payment_subject']) || !is_string($row['receipt_payment_mode'])) {
            throw new \DomainException('A paid provider payment requires complete fiscal configuration.');
        }
        if (!is_string($row['bill_to_contact_email'])) {
            throw new \DomainException('A fiscal receipt requires invoice customer email.');
        }
        $email = EmailPolicy::normalize($row['bill_to_contact_email'])['email'];
        $name = is_string($row['bill_to_contact_name']) && trim($row['bill_to_contact_name']) !== '' ? trim($row['bill_to_contact_name']) : null;
        $existing = $connection->fetchOne('SELECT id FROM commercial.receipts WHERE payment_id = ?', [$row['payment_id']]);
        if (is_string($existing)) {
            return $existing;
        }
        $receiptId = Uuid::v7()->toRfc4122();
        $requestKey = Uuid::v7()->toRfc4122();
        $connection->executeStatement(<<<'SQL'
INSERT INTO commercial.receipts
 (id, payment_id, invoice_id, commercial_account_id, payment_attempt_id, provider_account_id, provider_request_key, amount_minor, currency, customer_email, customer_name, vat_code, payment_subject, payment_mode)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL, [$receiptId, $row['payment_id'], $row['invoice_id'], $row['commercial_account_id'], $row['attempt_id'], $row['provider_account_id'], $requestKey, $row['amount_minor'], $row['currency'], $email, $name, $row['receipt_vat_code'], $row['receipt_payment_subject'], $row['receipt_payment_mode']]);
        $inserted = $connection->executeStatement(<<<'SQL'
INSERT INTO commercial.receipt_items
 (receipt_id, invoice_line_id, description, quantity, unit_amount_minor, total_minor, currency, vat_code, payment_subject, payment_mode)
SELECT ?, il.id, il.description, il.quantity, il.unit_amount_minor, il.total_minor, ?, ?, ?, ?
FROM commercial.invoice_lines il WHERE il.invoice_id = ? ORDER BY il.created_at, il.id
SQL, [$receiptId, $row['currency'], $row['receipt_vat_code'], $row['receipt_payment_subject'], $row['receipt_payment_mode'], $row['invoice_id']]);
        if ($inserted < 1) {
            throw new \LogicException('Receipt item snapshot is empty.');
        }

        return $receiptId;
    }
}
