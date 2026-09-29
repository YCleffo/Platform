<?php

declare(strict_types=1);

namespace App\Core\Account\Infrastructure;

use App\Core\Account\Application\AccountSecurityRepository;
use App\Core\Account\Application\AccountSecurityState;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresAccountSecurityRepository implements AccountSecurityRepository
{
    public function __construct(private ConnectionInterface $db) {}

    public function findSecurityState(string $accountId): ?AccountSecurityState
    {
        $row = $this->db->createCommand(
            <<<'SQL'
            SELECT
                a.id::text AS account_id,
                a.person_id::text AS person_id,
                a.account_type::text AS account_type,
                a.status::text AS status,
                a.security_stamp::text AS security_stamp,
                to_char(a.locked_until AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS locked_until,
                a.locale,
                a.timezone,
                to_char(a.disabled_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS disabled_at,
                to_char(a.archived_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS archived_at,
                p.status::text AS person_status,
                to_char(p.archived_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS person_archived_at
            FROM core.accounts a
            LEFT JOIN core.persons p ON p.id = a.person_id
            WHERE a.id = CAST(:account_id AS uuid)
            SQL,
            [':account_id' => $accountId],
        )->queryOne();

        return is_array($row) ? new AccountSecurityState(
            accountId: (string) $row['account_id'],
            personId: self::nullable($row['person_id']),
            accountType: (string) $row['account_type'],
            status: (string) $row['status'],
            securityStamp: (string) $row['security_stamp'],
            lockedUntil: self::nullable($row['locked_until']),
            locale: self::nullable($row['locale']),
            timezone: self::nullable($row['timezone']),
            disabledAt: self::nullable($row['disabled_at']),
            archivedAt: self::nullable($row['archived_at']),
            personStatus: self::nullable($row['person_status']),
            personArchivedAt: self::nullable($row['person_archived_at']),
        ) : null;
    }

    public function recordSuccessfulLogin(string $accountId): bool
    {
        return $this->db->createCommand(
            <<<'SQL'
            UPDATE core.accounts
            SET last_login_at = now(), updated_at = now()
            WHERE id = CAST(:account_id AS uuid)
            SQL,
            [':account_id' => $accountId],
        )->execute() === 1;
    }

    public function rotateSecurityStamp(string $accountId): ?string
    {
        $stamp = $this->db->createCommand(
            <<<'SQL'
            UPDATE core.accounts
            SET security_stamp = uuidv4(), updated_at = now()
            WHERE id = CAST(:account_id AS uuid)
            RETURNING security_stamp::text
            SQL,
            [':account_id' => $accountId],
        )->queryScalar();

        return is_string($stamp) ? $stamp : null;
    }

    private static function nullable(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
