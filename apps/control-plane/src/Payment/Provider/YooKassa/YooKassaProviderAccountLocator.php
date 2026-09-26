<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Provider\YooKassa;

use ControlPlane\Payment\Provider\ProviderAccount;
use Doctrine\DBAL\Connection;

final readonly class YooKassaProviderAccountLocator
{
    public function __construct(private Connection $connection)
    {
    }

    public function active(): ProviderAccount
    {
        $rows = $this->connection->fetchAllAssociative("SELECT id, provider_code, external_account_id, secret_ref, receipt_mode, receipt_vat_code, receipt_payment_subject, receipt_payment_mode FROM commercial.payment_provider_accounts WHERE provider_code = 'yookassa' AND status = 'active' ORDER BY id LIMIT 2");
        if ($rows === []) {
            throw new \DomainException('YooKassa provider is not configured.');
        }
        if (count($rows) !== 1) {
            throw new \DomainException('YooKassa provider configuration is ambiguous.');
        }

        return self::fromRow($rows[0]);
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): ProviderAccount
    {
        if (!is_string($row['id'] ?? null) || !is_string($row['provider_code'] ?? null)
            || !is_string($row['external_account_id'] ?? null) || !is_string($row['secret_ref'] ?? null)) {
            throw new \LogicException('Payment provider account configuration is incomplete.');
        }

        $vatCode = $row['receipt_vat_code'] ?? null;

        return new ProviderAccount(
            $row['id'],
            $row['provider_code'],
            $row['external_account_id'],
            $row['secret_ref'],
            is_string($row['receipt_mode'] ?? null) ? $row['receipt_mode'] : 'disabled',
            $vatCode === null ? null : (int) $vatCode,
            is_string($row['receipt_payment_subject'] ?? null) ? $row['receipt_payment_subject'] : null,
            is_string($row['receipt_payment_mode'] ?? null) ? $row['receipt_payment_mode'] : null,
        );
    }
}
