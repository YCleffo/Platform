<?php

declare(strict_types=1);

namespace App\Core\Settings\Infrastructure;

use App\Core\Settings\Application\FeatureFlag;
use App\Core\Settings\Application\FeatureFlagRepository;
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresFeatureFlagRepository implements FeatureFlagRepository
{
    private const string SELECT_COLUMNS = <<<'SQL'
        SELECT
            flag.id::text AS id,
            flag.module_id::text AS module_id,
            module.code AS module_code,
            flag.code,
            flag.enabled,
            flag.rollout_percent,
            flag.config::text AS config,
            flag.description,
            flag.updated_by_account_id::text AS updated_by_account_id,
            to_char(flag.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
            to_char(flag.updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS updated_at
        FROM core.feature_flags flag
        LEFT JOIN core.modules module ON module.id = flag.module_id
        SQL;

    public function __construct(private ConnectionInterface $db) {}

    public function findAll(): array
    {
        $rows = $this->db->createCommand(
            self::SELECT_COLUMNS . ' ORDER BY flag.code, flag.id',
        )->queryAll();

        /** @var list<array<array-key, mixed>> $rows */
        return array_map(self::map(...), $rows);
    }

    public function findByCode(string $code): ?FeatureFlag
    {
        $row = $this->db->createCommand(
            self::SELECT_COLUMNS . ' WHERE flag.code = :code',
            [':code' => $code],
        )->queryOne();

        return is_array($row) ? self::map($row) : null;
    }

    public function upsert(
        ?string $moduleId,
        string $code,
        bool $enabled,
        int $rolloutPercent,
        array $config,
        ?string $description,
        string $updatedByAccountId,
    ): FeatureFlag {
        $this->db->createCommand(
            <<<'SQL'
            INSERT INTO core.feature_flags (
                module_id, code, enabled, rollout_percent, config, description,
                updated_by_account_id
            )
            VALUES (
                CAST(:module_id AS uuid), :code, :enabled, :rollout_percent,
                CAST(:config AS jsonb), :description, CAST(:updated_by_account_id AS uuid)
            )
            ON CONFLICT (code) DO UPDATE
            SET
                module_id = EXCLUDED.module_id,
                enabled = EXCLUDED.enabled,
                rollout_percent = EXCLUDED.rollout_percent,
                config = EXCLUDED.config,
                description = EXCLUDED.description,
                updated_by_account_id = EXCLUDED.updated_by_account_id,
                updated_at = now()
            SQL,
            [
                ':module_id' => $moduleId,
                ':code' => $code,
                ':enabled' => $enabled,
                ':rollout_percent' => $rolloutPercent,
                ':config' => json_encode($config, JSON_THROW_ON_ERROR),
                ':description' => $description,
                ':updated_by_account_id' => $updatedByAccountId,
            ],
        )->execute();

        return $this->findByCode($code)
            ?? throw new RuntimeException('The feature flag upsert did not return a record.');
    }

    public function delete(string $code): ?FeatureFlag
    {
        $row = $this->db->createCommand(
            self::SELECT_COLUMNS . ' WHERE flag.code = :code FOR UPDATE OF flag',
            [':code' => $code],
        )->queryOne();
        if (!is_array($row)) {
            return null;
        }

        $this->db->createCommand(
            'DELETE FROM core.feature_flags WHERE code = :code',
            [':code' => $code],
        )->execute();

        return self::map($row);
    }

    /** @param array<array-key, mixed> $row */
    private static function map(array $row): FeatureFlag
    {
        return new FeatureFlag(
            id: (string) $row['id'],
            moduleId: self::nullableString($row['module_id']),
            moduleCode: self::nullableString($row['module_code']),
            code: (string) $row['code'],
            enabled: self::boolean($row['enabled']),
            rolloutPercent: (int) $row['rollout_percent'],
            config: PostgresJsonValue::decodeObject((string) $row['config']),
            description: self::nullableString($row['description']),
            updatedByAccountId: self::nullableString($row['updated_by_account_id']),
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }

    private static function boolean(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 1;
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
