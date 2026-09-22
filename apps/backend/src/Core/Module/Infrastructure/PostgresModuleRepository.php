<?php

declare(strict_types=1);

namespace App\Core\Module\Infrastructure;

use App\Core\Module\Application\ModuleRepository;
use App\Core\Module\Application\ModuleStateChange;
use App\Core\Module\Application\ModuleView;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresModuleRepository implements ModuleRepository
{
    private const string SELECT_COLUMNS = <<<'SQL'
        SELECT
            id::text AS id,
            code,
            name,
            description,
            version,
            settings_schema_version,
            enabled,
            to_char(installed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS installed_at,
            to_char(enabled_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS enabled_at,
            to_char(disabled_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS disabled_at
        SQL;

    public function __construct(private ConnectionInterface $db) {}

    public function findAll(): array
    {
        $rows = $this->db->createCommand(
            self::SELECT_COLUMNS . ' FROM core.modules ORDER BY code, id',
        )->queryAll();

        /** @var list<array<array-key, mixed>> $rows */
        return array_map(self::map(...), $rows);
    }

    public function findByCode(string $code): ?ModuleView
    {
        $row = $this->db->createCommand(
            self::SELECT_COLUMNS . ' FROM core.modules WHERE code = :code',
            [':code' => $code],
        )->queryOne();

        return is_array($row) ? self::map($row) : null;
    }

    public function setEnabled(string $code, bool $enabled): ?ModuleStateChange
    {
        $row = $this->db->createCommand(
            self::SELECT_COLUMNS . ' FROM core.modules WHERE code = :code FOR UPDATE',
            [':code' => $code],
        )->queryOne();
        $before = is_array($row) ? self::map($row) : null;
        if ($before === null) {
            return null;
        }

        if ($before->enabled === $enabled) {
            return new ModuleStateChange($before, $before, false);
        }

        $this->db->createCommand(
            <<<'SQL'
            UPDATE core.modules
            SET
                enabled = :enabled_value,
                enabled_at = CASE WHEN :enabled_at_switch THEN now() ELSE enabled_at END,
                disabled_at = CASE WHEN :disabled_at_switch THEN NULL ELSE now() END
            WHERE code = :code
              AND enabled <> :enabled_filter
            SQL,
            [
                ':code' => $code,
                ':enabled_value' => $enabled,
                ':enabled_at_switch' => $enabled,
                ':disabled_at_switch' => $enabled,
                ':enabled_filter' => $enabled,
            ],
        )->execute();

        $after = $this->findByCode($code);
        if ($after === null) {
            return null;
        }

        return new ModuleStateChange($before, $after, true);
    }

    /** @param array<array-key, mixed> $row */
    private static function map(array $row): ModuleView
    {
        return new ModuleView(
            id: (string) $row['id'],
            code: (string) $row['code'],
            name: (string) $row['name'],
            description: self::nullableString($row['description']),
            version: (string) $row['version'],
            settingsSchemaVersion: (int) $row['settings_schema_version'],
            enabled: self::boolean($row['enabled']),
            installedAt: (string) $row['installed_at'],
            enabledAt: self::nullableString($row['enabled_at']),
            disabledAt: self::nullableString($row['disabled_at']),
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
