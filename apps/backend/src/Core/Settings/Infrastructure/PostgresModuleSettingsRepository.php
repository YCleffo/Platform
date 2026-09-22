<?php

declare(strict_types=1);

namespace App\Core\Settings\Infrastructure;

use App\Core\Settings\Application\ModuleSetting;
use App\Core\Settings\Application\ModuleSettingsRepository;
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresModuleSettingsRepository implements ModuleSettingsRepository
{
    private const string SELECT_COLUMNS = <<<'SQL'
        SELECT
            setting.id::text AS id,
            setting.module_id::text AS module_id,
            module.code AS module_code,
            setting.setting_key,
            setting.value::text AS value,
            setting.description,
            setting.updated_by_account_id::text AS updated_by_account_id,
            to_char(setting.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
            to_char(setting.updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS updated_at
        FROM core.module_settings setting
        JOIN core.modules module ON module.id = setting.module_id
        SQL;

    public function __construct(private ConnectionInterface $db) {}

    public function findAll(string $moduleId): array
    {
        $rows = $this->db->createCommand(
            self::SELECT_COLUMNS . ' WHERE setting.module_id = CAST(:module_id AS uuid) ORDER BY setting.setting_key, setting.id',
            [':module_id' => $moduleId],
        )->queryAll();

        /** @var list<array<array-key, mixed>> $rows */
        return array_map(self::map(...), $rows);
    }

    public function find(string $moduleId, string $key): ?ModuleSetting
    {
        $row = $this->db->createCommand(
            self::SELECT_COLUMNS . ' WHERE setting.module_id = CAST(:module_id AS uuid) AND setting.setting_key = :setting_key',
            [':module_id' => $moduleId, ':setting_key' => $key],
        )->queryOne();

        return is_array($row) ? self::map($row) : null;
    }

    public function upsert(
        string $moduleId,
        string $key,
        mixed $value,
        ?string $description,
        string $updatedByAccountId,
    ): ModuleSetting {
        $this->db->createCommand(
            <<<'SQL'
            INSERT INTO core.module_settings (
                module_id, setting_key, value, description, updated_by_account_id
            )
            VALUES (
                CAST(:module_id AS uuid), :setting_key, CAST(:value AS jsonb),
                :description, CAST(:updated_by_account_id AS uuid)
            )
            ON CONFLICT (module_id, setting_key) DO UPDATE
            SET
                value = EXCLUDED.value,
                description = EXCLUDED.description,
                updated_by_account_id = EXCLUDED.updated_by_account_id,
                updated_at = now()
            SQL,
            [
                ':module_id' => $moduleId,
                ':setting_key' => $key,
                ':value' => json_encode($value, JSON_THROW_ON_ERROR),
                ':description' => $description,
                ':updated_by_account_id' => $updatedByAccountId,
            ],
        )->execute();

        return $this->find($moduleId, $key)
            ?? throw new RuntimeException('The module setting upsert did not return a record.');
    }

    public function delete(string $moduleId, string $key): ?ModuleSetting
    {
        $row = $this->db->createCommand(
            self::SELECT_COLUMNS . <<<'SQL'
                 WHERE setting.module_id = CAST(:module_id AS uuid)
                   AND setting.setting_key = :setting_key
                 FOR UPDATE OF setting
                SQL,
            [':module_id' => $moduleId, ':setting_key' => $key],
        )->queryOne();
        if (!is_array($row)) {
            return null;
        }

        $this->db->createCommand(
            <<<'SQL'
            DELETE FROM core.module_settings
            WHERE module_id = CAST(:module_id AS uuid) AND setting_key = :setting_key
            SQL,
            [':module_id' => $moduleId, ':setting_key' => $key],
        )->execute();

        return self::map($row);
    }

    /** @param array<array-key, mixed> $row */
    private static function map(array $row): ModuleSetting
    {
        return new ModuleSetting(
            id: (string) $row['id'],
            moduleId: (string) $row['module_id'],
            moduleCode: (string) $row['module_code'],
            key: (string) $row['setting_key'],
            value: PostgresJsonValue::decode((string) $row['value']),
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
