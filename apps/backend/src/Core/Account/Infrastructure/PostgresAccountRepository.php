<?php

declare(strict_types=1);

namespace App\Core\Account\Infrastructure;

use App\Core\Account\Application\AccountPage;
use App\Core\Account\Application\AccountRepository;
use App\Core\Account\Application\AccountView;
use App\Core\Account\Application\CreateAccountCommand;
use App\Core\Shared\Application\ConflictException;
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;

final readonly class PostgresAccountRepository implements AccountRepository
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function findPage(int $limit, int $offset): AccountPage
    {
        $rows = $this->db->createCommand(
            self::SELECT_COLUMNS . <<<'SQL'

            FROM core.accounts
            WHERE archived_at IS NULL
            ORDER BY created_at, id
            LIMIT CAST(:limit AS integer)
            OFFSET CAST(:offset AS integer)
            SQL,
            [':limit' => $limit, ':offset' => $offset],
        )->queryAll();

        $total = (int) $this->db
            ->createCommand('SELECT COUNT(*) FROM core.accounts WHERE archived_at IS NULL')
            ->queryScalar();

        /** @var list<array<array-key, mixed>> $rows */
        return new AccountPage(array_map(self::map(...), $rows), $limit, $offset, $total);
    }

    public function findById(string $id): ?AccountView
    {
        $row = $this->db->createCommand(
            self::SELECT_COLUMNS . ' FROM core.accounts WHERE id = CAST(:id AS uuid)',
            [':id' => $id],
        )->queryOne();

        return is_array($row) ? self::map($row) : null;
    }

    public function create(CreateAccountCommand $command): AccountView
    {
        try {
            $row = $this->db->createCommand(
                <<<'SQL'
                INSERT INTO core.accounts (person_id, account_type, label)
                VALUES (
                    CAST(:person_id AS uuid),
                    CAST(:account_type AS core.account_type),
                    :label
                )
                RETURNING
                    id::text AS id,
                    person_id::text AS person_id,
                    account_type::text AS account_type,
                    status::text AS status,
                    label,
                    to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
                    to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS updated_at,
                    to_char(disabled_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS disabled_at,
                    to_char(archived_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS archived_at
                SQL,
                [
                    ':person_id' => $command->personId,
                    ':account_type' => $command->accountType,
                    ':label' => $command->label,
                ],
            )->queryOne();
        } catch (IntegrityException $exception) {
            throw new ConflictException(
                'The account conflicts with existing account data.',
                previous: $exception,
            );
        }

        if (!is_array($row)) {
            throw new RuntimeException('PostgreSQL did not return the created account.');
        }

        return self::map($row);
    }

    private const string SELECT_COLUMNS = <<<'SQL'
        SELECT
            id::text AS id,
            person_id::text AS person_id,
            account_type::text AS account_type,
            status::text AS status,
            label,
            to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
            to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS updated_at,
            to_char(disabled_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS disabled_at,
            to_char(archived_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS archived_at
        SQL;

    /**
     * @param array<array-key, mixed> $row
     */
    private static function map(array $row): AccountView
    {
        return new AccountView(
            id: (string) $row['id'],
            personId: self::nullableString($row['person_id']),
            accountType: (string) $row['account_type'],
            status: (string) $row['status'],
            label: self::nullableString($row['label']),
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
            disabledAt: self::nullableString($row['disabled_at']),
            archivedAt: self::nullableString($row['archived_at']),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
