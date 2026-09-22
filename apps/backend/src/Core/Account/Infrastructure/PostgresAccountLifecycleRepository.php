<?php

declare(strict_types=1);

namespace App\Core\Account\Infrastructure;

use App\Core\Account\Application\AccountLifecycleChange;
use App\Core\Account\Application\AccountLifecycleRepository;
use App\Core\Shared\Application\ConflictException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresAccountLifecycleRepository implements AccountLifecycleRepository
{
    public function __construct(private ConnectionInterface $db) {}

    public function approve(string $accountId, string $actorAccountId): ?AccountLifecycleChange
    {
        return $this->db->transaction(function () use ($accountId, $actorAccountId): ?AccountLifecycleChange {
            $before = $this->statusForUpdate($accountId);
            if ($before === null) {
                return null;
            }
            if ($before !== 'pending_approval') {
                throw new ConflictException('Only an account pending approval can be approved.');
            }
            $this->db->createCommand(
                <<<'SQL'
                UPDATE core.accounts
                SET status = 'active', approved_by_account_id = CAST(:actor_id AS uuid),
                    approved_at = now(), updated_at = now()
                WHERE id = CAST(:account_id AS uuid)
                SQL,
                [':account_id' => $accountId, ':actor_id' => $actorAccountId],
            )->execute();

            return new AccountLifecycleChange($accountId, $before, 'active', 0);
        });
    }

    public function disable(string $accountId): ?AccountLifecycleChange
    {
        return $this->securityTransition($accountId, 'disabled');
    }

    public function enable(string $accountId): ?AccountLifecycleChange
    {
        return $this->db->transaction(function () use ($accountId): ?AccountLifecycleChange {
            $row = $this->db->createCommand(
                <<<'SQL'
                SELECT a.status::text AS status, a.account_type::text AS account_type,
                       p.status::text AS person_status, p.archived_at AS person_archived_at
                FROM core.accounts a
                LEFT JOIN core.persons p ON p.id = a.person_id
                WHERE a.id = CAST(:account_id AS uuid)
                FOR UPDATE OF a
                SQL,
                [':account_id' => $accountId],
            )->queryOne();
            if (!is_array($row)) {
                return null;
            }
            $before = (string) $row['status'];
            if ($before !== 'disabled') {
                throw new ConflictException('Only a disabled account can be enabled.');
            }
            if ($row['account_type'] === 'human'
                && ($row['person_status'] !== 'active' || $row['person_archived_at'] !== null)
            ) {
                throw new ConflictException('The linked person is not active.');
            }
            $this->db->createCommand(
                <<<'SQL'
                UPDATE core.accounts
                SET status = 'active', disabled_at = NULL, security_stamp = uuidv4(), updated_at = now()
                WHERE id = CAST(:account_id AS uuid)
                SQL,
                [':account_id' => $accountId],
            )->execute();

            return new AccountLifecycleChange($accountId, $before, 'active', 0);
        });
    }

    public function archive(string $accountId): ?AccountLifecycleChange
    {
        return $this->securityTransition($accountId, 'archived');
    }

    private function securityTransition(string $accountId, string $target): ?AccountLifecycleChange
    {
        return $this->db->transaction(function () use ($accountId, $target): ?AccountLifecycleChange {
            $before = $this->statusForUpdate($accountId);
            if ($before === null) {
                return null;
            }
            if ($before === 'archived' || $before === $target) {
                throw new ConflictException(sprintf('Account is already %s.', $before));
            }
            $this->assertNotLastSuperadmin($accountId);
            $disabledAt = $target === 'disabled' ? 'now()' : 'disabled_at';
            $archivedAt = $target === 'archived' ? 'now()' : 'archived_at';
            $this->db->createCommand(
                sprintf(
                    "UPDATE core.accounts SET status = CAST(:status AS core.account_status), disabled_at = %s, archived_at = %s, security_stamp = uuidv4(), updated_at = now() WHERE id = CAST(:account_id AS uuid)",
                    $disabledAt,
                    $archivedAt,
                ),
                [':status' => $target, ':account_id' => $accountId],
            )->execute();
            $revoked = $this->db->createCommand(
                <<<'SQL'
                UPDATE core.auth_sessions
                SET revoked_at = now(), revoke_reason = :reason
                WHERE account_id = CAST(:account_id AS uuid) AND revoked_at IS NULL
                SQL,
                [':account_id' => $accountId, ':reason' => 'account ' . $target],
            )->execute();

            return new AccountLifecycleChange($accountId, $before, $target, $revoked);
        });
    }

    private function statusForUpdate(string $accountId): ?string
    {
        $status = $this->db->createCommand(
            'SELECT status::text FROM core.accounts WHERE id = CAST(:id AS uuid) FOR UPDATE',
            [':id' => $accountId],
        )->queryScalar();

        return is_string($status) ? $status : null;
    }

    private function assertNotLastSuperadmin(string $accountId): void
    {
        $roleId = $this->db->createCommand(
            "SELECT id::text FROM core.roles WHERE code = 'core.superadmin' FOR UPDATE",
        )->queryScalar();
        if (!is_string($roleId)) {
            return;
        }
        $targetIsSuperadmin = self::boolean($this->db->createCommand(
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
        if (!$targetIsSuperadmin) {
            return;
        }
        $usable = (int) $this->db->createCommand(
            <<<'SQL'
            SELECT COUNT(*)
            FROM core.account_roles ar
            INNER JOIN core.accounts a ON a.id = ar.account_id
            WHERE ar.role_id = CAST(:role_id AS uuid)
              AND (ar.expires_at IS NULL OR ar.expires_at > now())
              AND a.status = 'active' AND a.disabled_at IS NULL AND a.archived_at IS NULL
            SQL,
            [':role_id' => $roleId],
        )->queryScalar();
        if ($usable <= 1) {
            throw new ConflictException('The last active super administrator cannot be disabled or archived.');
        }
    }

    private static function boolean(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 1;
    }
}
