<?php

declare(strict_types=1);

namespace ControlPlane\Fiscalization\Application;

use ControlPlane\Billing\Domain\Money;
use ControlPlane\Payment\Provider\FiscalReceiptItem;
use ControlPlane\Payment\Provider\FiscalReceiptRequest;
use ControlPlane\Payment\Provider\ProviderAccount;
use Doctrine\DBAL\Connection;

final readonly class ReceiptSnapshot
{
    /** @return array{array<string, mixed>, ProviderAccount, FiscalReceiptRequest} */
    public function load(Connection $connection, string $receiptId): array
    {
        $row = $connection->fetchAssociative(<<<'SQL'
SELECT r.*, pa.provider_payment_id, rf.provider_refund_id, ppa.provider_code, ppa.external_account_id, ppa.secret_ref, ppa.receipt_mode,
       ppa.receipt_vat_code, ppa.receipt_payment_subject, ppa.receipt_payment_mode
FROM commercial.receipts r
LEFT JOIN commercial.payment_attempts pa ON pa.id = r.payment_attempt_id
LEFT JOIN commercial.refunds rf ON rf.id = r.refund_id
JOIN commercial.payment_provider_accounts ppa ON ppa.id = r.provider_account_id
WHERE r.id = ?
SQL, [$receiptId]);
        if ($row === false || ($row['receipt_type'] === 'payment' && !is_string($row['provider_payment_id'])) || ($row['receipt_type'] === 'refund' && !is_string($row['provider_refund_id']))) {
            throw new \DomainException('Receipt was not found.');
        }
        $itemRows = $connection->fetchAllAssociative('SELECT description, quantity, unit_amount_minor, total_minor, currency, vat_code, payment_subject, payment_mode FROM commercial.receipt_items WHERE receipt_id = ? ORDER BY created_at, id', [$receiptId]);
        $items = array_map(static fn (array $item): FiscalReceiptItem => new FiscalReceiptItem(
            (string) $item['description'],
            (int) $item['quantity'],
            Money::nonNegativeBigInt($item['unit_amount_minor']),
            Money::nonNegativeBigInt($item['total_minor']),
            (string) $item['currency'],
            (int) $item['vat_code'],
            (string) $item['payment_subject'],
            (string) $item['payment_mode'],
        ), $itemRows);
        $vat = $row['receipt_vat_code'];
        $account = new ProviderAccount((string) $row['provider_account_id'], (string) $row['provider_code'], (string) $row['external_account_id'], (string) $row['secret_ref'], (string) $row['receipt_mode'], $vat === null ? null : (int) $vat, is_string($row['receipt_payment_subject']) ? $row['receipt_payment_subject'] : null, is_string($row['receipt_payment_mode']) ? $row['receipt_payment_mode'] : null);
        $request = new FiscalReceiptRequest((string) $row['provider_request_key'], is_string($row['provider_payment_id']) ? $row['provider_payment_id'] : '', (string) $row['customer_email'], is_string($row['customer_name']) ? $row['customer_name'] : null, Money::nonNegativeBigInt($row['amount_minor']), (string) $row['currency'], $items, (string) $row['receipt_type'], is_string($row['provider_refund_id']) ? $row['provider_refund_id'] : null);

        return [$row, $account, $request];
    }
}
