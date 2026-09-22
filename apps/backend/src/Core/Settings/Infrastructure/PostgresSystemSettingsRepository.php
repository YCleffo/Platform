<?php

declare(strict_types=1);

namespace App\Core\Settings\Infrastructure;

use App\Core\Settings\Application\SystemSetting;
use App\Core\Settings\Application\SystemSettingsRepository;
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresSystemSettingsRepository implements SystemSettingsRepository
{
    public function __construct(private ConnectionInterface $db) {}

    public function findAll(): array
    {
        $rows = $this->db->createCommand(
            self::SELECT_COLUMNS . ' FROM core.system_settings ORDER BY setting_key, id',
        )->queryAll();

        /** @var list<array<array-key, mixed>> $rows */
        return array_map(self::map(...), $rows);
    }

    public function findByKey(string $key): ?SystemSetting
    {
        $row = $this->db->createCommand(
            self::SELECT_COLUMNS . ' FROM core.system_settings WHERE setting_key = :setting_key',
            [':setting_key' => $key],
        )->queryOne();

        return is_array($row) ? self::map($row) : null;
    }

    public function findPublic(): array
    {
        $rows = $this->db->createCommand(
            self::SELECT_COLUMNS . ' FROM core.system_settings WHERE is_public = true ORDER BY setting_key, id',
        )->queryAll();

        /** @var list<array<array-key, mixed>> $rows */
        return array_map(self::map(...), $rows);
    }

    public function upsert(
        string $key,
        mixed $value,
        bool $isPublic,
        ?string $description,
        string $updatedByAccountId,
    ): SystemSetting {
        $this->db->createCommand(
            <<<'SQL'
            INSERT INTO core.system_settings (
                setting_key, value, is_public, description, updated_by_account_id
            )
            VALUES (
                :setting_key, CAST(:value AS jsonb), :is_public, :description,
                CAST(:updated_by_account_id AS uuid)
            )
            ON CONFLICT (setting_key) DO UPDATE
            SET
                value = EXCLUDED.value,
                is_public = EXCLUDED.is_public,
                description = EXCLUDED.description,
                updated_by_account_id = EXCLUDED.updated_by_account_id,
                updated_at = now()
            SQL,
            [
                ':setting_key' => $key,
                ':value' => json_encode($value, JSON_THROW_ON_ERROR),
                ':is_public' => $isPublic,
                ':description' => $description,
                ':updated_by_account_id' => $updatedByAccountId,
            ],
        )->execute();

        return $this->findByKey($key)
            ?? throw new RuntimeException('The system setting upsert did not return a record.');
    }

    public function delete(string $key): ?SystemSetting
    {
        $row = $this->db->createCommand(
            self::SELECT_COLUMNS . <<<'SQL'
                 FROM core.system_settings
                 WHERE setting_key = :setting_key
                 FOR UPDATE
                SQL,
            [':setting_key' => $key],
        )->queryOne();
        if (!is_array($row)) {
            return null;
        }

        $this->db->createCommand(
            'DELETE FROM core.system_settings WHERE setting_key = :setting_key',
            [':setting_key' => $key],
        )->execute();

        return self::map($row);
    }

    private const string SELECT_COLUMNS = <<<'SQL'
        SELECT
            id::text AS id,
            setting_key,
            value::text AS value,
            is_public,
            description,
            updated_by_account_id::text AS updated_by_account_id,
            to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
            to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS updated_at
        SQL;

    /** @param array<array-key, mixed> $row */
    private static function map(array $row): SystemSetting
    {
        return new SystemSetting(
            id: (string) $row['id'],
            key: (string) $row['setting_key'],
            value: PostgresJsonValue::decode((string) $row['value']),
            isPublic: $row['is_public'] === true || $row['is_public'] === 't' || $row['is_public'] === 1,
            description: self::nullableString($row['description']),
            updatedByAccountId: self::nullableString($row['updated_by_account_id']),
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
