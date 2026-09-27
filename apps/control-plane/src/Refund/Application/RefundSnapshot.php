<?php

declare(strict_types=1);

namespace ControlPlane\Refund\Application;

use ControlPlane\Billing\Domain\Money;
use ControlPlane\Payment\Provider\ProviderAccount;
use ControlPlane\Payment\Provider\RefundProviderRequest;
use Doctrine\DBAL\Connection;

final readonly class RefundSnapshot
{
    /** @return array{array<string, mixed>, ProviderAccount, RefundProviderRequest} */
    public function load(Connection $connection, string $refundId): array
    {
        $row = $connection->fetchAssociative(<<<'SQL'
SELECT r.*, pa.provider_payment_id, ppa.provider_code, ppa.external_account_id, ppa.secret_ref, ppa.receipt_mode,
       ppa.receipt_vat_code, ppa.receipt_payment_subject, ppa.receipt_payment_mode
FROM commercial.refunds r
JOIN commercial.payment_attempts pa ON pa.payment_id = r.payment_id AND pa.provider_account_id = r.provider_account_id AND pa.status = 'succeeded'
JOIN commercial.payment_provider_accounts ppa ON ppa.id = r.provider_account_id
WHERE r.id = ?
SQL, [$refundId]);
        if ($row === false || !is_string($row['provider_payment_id'])) {
            throw new \DomainException('Refund was not found.');
        }
        $account = new ProviderAccount((string) $row['provider_account_id'], (string) $row['provider_code'], (string) $row['external_account_id'], (string) $row['secret_ref'], (string) $row['receipt_mode'], $row['receipt_vat_code'] === null ? null : (int) $row['receipt_vat_code'], is_string($row['receipt_payment_subject']) ? $row['receipt_payment_subject'] : null, is_string($row['receipt_payment_mode']) ? $row['receipt_payment_mode'] : null);
        $request = new RefundProviderRequest((string) $row['provider_request_key'], $row['provider_payment_id'], Money::nonNegativeBigInt($row['amount_minor']), (string) $row['currency'], (string) $row['id']);

        return [$row, $account, $request];
    }
}
