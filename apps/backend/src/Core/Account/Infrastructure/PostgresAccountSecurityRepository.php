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
                id::text AS account_id,
                person_id::text AS person_id,
                account_type::text AS account_type,
                status::text AS status,
                security_stamp::text AS security_stamp,
                to_char(locked_until AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS locked_until,
                locale,
                timezone,
                to_char(disabled_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS disabled_at,
                to_char(archived_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS archived_at
            FROM core.accounts
            WHERE id = CAST(:account_id AS uuid)
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
