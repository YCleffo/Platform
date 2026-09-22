<?php

declare(strict_types=1);

namespace App\Core\Rbac\Infrastructure;

use App\Core\Rbac\Application\PermissionPage;
use App\Core\Rbac\Application\PermissionRepository;
use App\Core\Rbac\Application\PermissionView;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresPermissionRepository implements PermissionRepository
{
    public function __construct(private ConnectionInterface $db) {}

    public function findPage(int $limit, int $offset): PermissionPage
    {
        $rows = $this->db->createCommand(
            self::SELECT_COLUMNS . <<<'SQL'

            FROM core.permissions p
            ORDER BY p.code, p.id
            LIMIT CAST(:limit AS integer)
            OFFSET CAST(:offset AS integer)
            SQL,
            [':limit' => $limit, ':offset' => $offset],
        )->queryAll();
        $total = (int) $this->db->createCommand('SELECT COUNT(*) FROM core.permissions')->queryScalar();

        /** @var list<array<array-key, mixed>> $rows */
        return new PermissionPage(array_map(self::map(...), $rows), $limit, $offset, $total);
    }

    public function findByAccountId(string $accountId): array
    {
        $rows = $this->db->createCommand(
            self::SELECT_COLUMNS . <<<'SQL'

            FROM core.permissions p
            INNER JOIN core.role_permissions rp ON rp.permission_id = p.id
            INNER JOIN core.account_roles ar ON ar.role_id = rp.role_id
            WHERE ar.account_id = CAST(:account_id AS uuid)
                AND (ar.expires_at IS NULL OR ar.expires_at > now())
            GROUP BY p.id
            ORDER BY p.code, p.id
            SQL,
            [':account_id' => $accountId],
        )->queryAll();

        /** @var list<array<array-key, mixed>> $rows */
        return array_map(self::map(...), $rows);
    }

    public function findAll(): array
    {
        $rows = $this->db->createCommand(
            self::SELECT_COLUMNS . ' FROM core.permissions p ORDER BY p.code, p.id',
        )->queryAll();

        /** @var list<array<array-key, mixed>> $rows */
        return array_map(self::map(...), $rows);
    }

    public function accountHasPermission(string $accountId, string $permissionCode): bool
    {
        return self::boolean($this->db->createCommand(
            <<<'SQL'
            SELECT EXISTS (
                SELECT 1
                FROM core.permissions p
                INNER JOIN core.role_permissions rp ON rp.permission_id = p.id
                INNER JOIN core.account_roles ar ON ar.role_id = rp.role_id
                WHERE ar.account_id = CAST(:account_id AS uuid)
                    AND p.code = :permission_code
                    AND (ar.expires_at IS NULL OR ar.expires_at > now())
            )
            SQL,
            [':account_id' => $accountId, ':permission_code' => $permissionCode],
        )->queryScalar());
    }

    private const string SELECT_COLUMNS = <<<'SQL'
        SELECT
            p.id::text AS id,
            p.module_id::text AS module_id,
            p.code,
            p.name,
            p.description,
            p.is_system,
            to_char(p.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
            to_char(p.updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS updated_at
        SQL;

    /** @param array<array-key, mixed> $row */
    private static function map(array $row): PermissionView
    {
        return new PermissionView(
            id: (string) $row['id'],
            moduleId: $row['module_id'] === null ? null : (string) $row['module_id'],
            code: (string) $row['code'],
            name: (string) $row['name'],
            description: $row['description'] === null ? null : (string) $row['description'],
            isSystem: self::boolean($row['is_system']),
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }

    private static function boolean(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 1;
    }
}
