<?php

declare(strict_types=1);

namespace App\Core\Account\Infrastructure;

use App\Core\Account\Application\AccountInternationalPreferences;
use App\Core\Account\Application\AccountPreferencesRepository;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresAccountPreferencesRepository implements AccountPreferencesRepository
{
    public function __construct(private ConnectionInterface $db) {}

    public function find(string $accountId): ?AccountInternationalPreferences
    {
        $row = $this->db->createCommand(
            <<<'SQL'
            SELECT id::text AS account_id, locale, timezone
            FROM core.accounts
            WHERE id = CAST(:account_id AS uuid)
            SQL,
            [':account_id' => $accountId],
        )->queryOne();

        return is_array($row) ? self::map($row) : null;
    }

    public function save(
        string $accountId,
        ?string $locale,
        ?string $timezone,
        bool $writeLocale = true,
        bool $writeTimezone = true,
    ): ?AccountInternationalPreferences {
        $row = $this->db->createCommand(
            <<<'SQL'
            UPDATE core.accounts
            SET locale = CASE WHEN CAST(:write_locale AS boolean) THEN :locale ELSE locale END,
                timezone = CASE WHEN CAST(:write_timezone AS boolean) THEN :timezone ELSE timezone END,
                updated_at = now()
            WHERE id = CAST(:account_id AS uuid)
            RETURNING id::text AS account_id, locale, timezone
            SQL,
            [
                ':account_id' => $accountId,
                ':locale' => $locale,
                ':timezone' => $timezone,
                ':write_locale' => $writeLocale,
                ':write_timezone' => $writeTimezone,
            ],
        )->queryOne();

        return is_array($row) ? self::map($row) : null;
    }

    /** @param array<array-key, mixed> $row */
    private static function map(array $row): AccountInternationalPreferences
    {
        return new AccountInternationalPreferences(
            accountId: (string) $row['account_id'],
            locale: self::nullableString($row['locale']),
            timezone: self::nullableString($row['timezone']),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
