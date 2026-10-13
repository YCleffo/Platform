<?php

declare(strict_types=1);

namespace App\Core\Rbac\Infrastructure;

use App\Core\Rbac\Application\RolePage;
use App\Core\Rbac\Application\RoleRepository;
use App\Core\Rbac\Application\RoleView;
use App\Core\Shared\Application\ConflictException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresRoleRepository implements RoleRepository
{
    public function __construct(private ConnectionInterface $db) {}

    public function findPage(int $limit, int $offset): RolePage
    {
        $rows = $this->db->createCommand(
            self::SELECT_COLUMNS . <<<'SQL'

            FROM core.roles r
            ORDER BY r.code, r.id
            LIMIT CAST(:limit AS integer)
            OFFSET CAST(:offset AS integer)
            SQL,
            [':limit' => $limit, ':offset' => $offset],
        )->queryAll();
        $total = (int) $this->db->createCommand('SELECT COUNT(*) FROM core.roles')->queryScalar();

        /** @var list<array<array-key, mixed>> $rows */
        return new RolePage(array_map(self::map(...), $rows), $limit, $offset, $total);
    }

    public function findById(string $id): ?RoleView
    {
        $row = $this->db->createCommand(
            self::SELECT_COLUMNS . ' FROM core.roles r WHERE r.id = CAST(:id AS uuid)',
            [':id' => $id],
        )->queryOne();

        return is_array($row) ? self::map($row) : null;
    }

    public function findByAccountId(string $accountId): array
    {
        $rows = $this->db->createCommand(
            self::SELECT_COLUMNS . <<<'SQL'

            FROM core.roles r
            INNER JOIN core.account_roles ar ON ar.role_id = r.id
            WHERE ar.account_id = CAST(:account_id AS uuid)
                AND (ar.expires_at IS NULL OR ar.expires_at > now())
            ORDER BY r.code, r.id
            SQL,
            [':account_id' => $accountId],
        )->queryAll();

        /** @var list<array<array-key, mixed>> $rows */
        return array_map(self::map(...), $rows);
    }

    public function accountHasRoleCode(string $accountId, string $roleCode): bool
    {
        return self::boolean($this->db->createCommand(
            <<<'SQL'
            SELECT EXISTS (
                SELECT 1
                FROM core.account_roles ar
                INNER JOIN core.roles r ON r.id = ar.role_id
                WHERE ar.account_id = CAST(:account_id AS uuid)
                    AND r.code = :role_code
                    AND (ar.expires_at IS NULL OR ar.expires_at > now())
            )
            SQL,
            [':account_id' => $accountId, ':role_code' => $roleCode],
        )->queryScalar());
    }

    public function assign(string $accountId, string $roleId, string $assignedByAccountId): bool
    {
        return $this->db->createCommand(
            <<<'SQL'
            INSERT INTO core.account_roles (account_id, role_id, assigned_by_account_id)
            VALUES (
                CAST(:account_id AS uuid),
                CAST(:role_id AS uuid),
                CAST(:actor_id AS uuid)
            )
            ON CONFLICT (account_id, role_id) DO NOTHING
            SQL,
            [
                ':account_id' => $accountId,
                ':role_id' => $roleId,
                ':actor_id' => $assignedByAccountId,
            ],
        )->execute() === 1;
    }

    public function revoke(string $accountId, string $roleId): bool
    {
        return $this->db->transaction(function () use ($accountId, $roleId): bool {
            $role = $this->db->createCommand(
                'SELECT code FROM core.roles WHERE id = CAST(:role_id AS uuid) FOR UPDATE',
                [':role_id' => $roleId],
            )->queryScalar();
            if ($role === 'core.superadmin') {
                $targetHasRole = self::boolean($this->db->createCommand(
                    <<<'SQL'
                    SELECT EXISTS (
                        SELECT 1 FROM core.account_roles
                        WHERE account_id = CAST(:account_id AS uuid)
                          AND role_id = CAST(:role_id AS uuid)
                          AND (expires_at IS NULL OR expires_at > now())
                    )
                    SQL,
                    [':account_id' => $accountId, ':role_id' => $roleId],
                )->queryScalar());
                $usable = (int) $this->db->createCommand(
                    <<<'SQL'
                    SELECT COUNT(*)
                    FROM core.account_roles ar
                    INNER JOIN core.accounts a ON a.id = ar.account_id
                    INNER JOIN core.persons p ON p.id = a.person_id
                    WHERE ar.role_id = CAST(:role_id AS uuid)
                      AND (ar.expires_at IS NULL OR ar.expires_at > now())
                      AND a.account_type = 'human'
                      AND a.status = 'active'
                      AND a.disabled_at IS NULL
                      AND a.archived_at IS NULL
                      AND p.status = 'active'
                      AND p.archived_at IS NULL
                    SQL,
                    [':role_id' => $roleId],
                )->queryScalar();
                if ($targetHasRole && $usable <= 1) {
                    throw new ConflictException('The last active super administrator role cannot be revoked.');
                }
            }

            return $this->db->createCommand(
                <<<'SQL'
                DELETE FROM core.account_roles
                WHERE account_id = CAST(:account_id AS uuid)
                  AND role_id = CAST(:role_id AS uuid)
                SQL,
                [':account_id' => $accountId, ':role_id' => $roleId],
            )->execute() === 1;
        });
    }

    private const string SELECT_COLUMNS = <<<'SQL'
        SELECT
            r.id::text AS id,
            r.code,
            r.name,
            r.description,
            r.is_system,
            r.is_protected,
            to_char(r.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
            to_char(r.updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS updated_at
        SQL;

    /** @param array<array-key, mixed> $row */
    private static function map(array $row): RoleView
    {
        return new RoleView(
            id: (string) $row['id'],
            code: (string) $row['code'],
            name: (string) $row['name'],
            description: $row['description'] === null ? null : (string) $row['description'],
            isSystem: self::boolean($row['is_system']),
            isProtected: self::boolean($row['is_protected']),
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }

    private static function boolean(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 1;
    }
}
