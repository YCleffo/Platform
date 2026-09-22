<?php

declare(strict_types=1);

namespace App\Core\Person\Infrastructure;

use App\Core\Person\Application\CreatePersonCommand;
use App\Core\Person\Application\PersonPage;
use App\Core\Person\Application\PersonRepository;
use App\Core\Person\Application\PersonView;
use App\Core\Person\Application\UpdatePersonCommand;
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresPersonRepository implements PersonRepository
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function findPage(int $limit, int $offset): PersonPage
    {
        $rows = $this->db
            ->createCommand(
                self::SELECT_COLUMNS . <<<'SQL'

                FROM core.persons
                WHERE archived_at IS NULL
                ORDER BY family_name, given_name, id
                LIMIT CAST(:limit AS integer)
                OFFSET CAST(:offset AS integer)
                SQL,
                [':limit' => $limit, ':offset' => $offset],
            )
            ->queryAll();

        $total = (int) $this->db
            ->createCommand(
                'SELECT COUNT(*) FROM core.persons WHERE archived_at IS NULL',
            )
            ->queryScalar();

        /** @var list<array<array-key, mixed>> $rows */
        return new PersonPage(
            array_map(self::map(...), $rows),
            $limit,
            $offset,
            $total,
        );
    }

    public function findById(string $id): ?PersonView
    {
        $row = $this->db
            ->createCommand(
                self::SELECT_COLUMNS . ' FROM core.persons WHERE id = CAST(:id AS uuid)',
                [':id' => $id],
            )
            ->queryOne();

        return is_array($row) ? self::map($row) : null;
    }

    public function create(CreatePersonCommand $command): PersonView
    {
        $row = $this->db
            ->createCommand(
                <<<'SQL'
                INSERT INTO core.persons (
                    given_name,
                    family_name,
                    middle_name,
                    display_name,
                    birth_date
                )
                VALUES (
                    :given_name,
                    :family_name,
                    :middle_name,
                    :display_name,
                    CAST(:birth_date AS date)
                )
                RETURNING
                    id::text AS id,
                    status::text AS status,
                    given_name,
                    family_name,
                    middle_name,
                    display_name,
                    birth_date::text AS birth_date,
                    to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
                    to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS updated_at,
                    to_char(archived_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS archived_at
                SQL,
                [
                    ':given_name' => $command->givenName,
                    ':family_name' => $command->familyName,
                    ':middle_name' => $command->middleName,
                    ':display_name' => $command->displayName,
                    ':birth_date' => $command->birthDate,
                ],
            )
            ->queryOne();

        if (!is_array($row)) {
            throw new RuntimeException('PostgreSQL did not return the created person.');
        }

        return self::map($row);
    }

    public function update(UpdatePersonCommand $command): ?PersonView
    {
        $assignments = [];
        $params = [':id' => $command->id];

        self::addUpdate($assignments, $params, 'given_name', $command->givenNameProvided, $command->givenName);
        self::addUpdate($assignments, $params, 'family_name', $command->familyNameProvided, $command->familyName);
        self::addUpdate($assignments, $params, 'middle_name', $command->middleNameProvided, $command->middleName);
        self::addUpdate($assignments, $params, 'display_name', $command->displayNameProvided, $command->displayName);
        if ($command->birthDateProvided) {
            $assignments[] = 'birth_date = CAST(:birth_date AS date)';
            $params[':birth_date'] = $command->birthDate;
        }
        $assignments[] = 'updated_at = now()';

        $row = $this->db
            ->createCommand(
                'UPDATE core.persons SET '
                . implode(', ', $assignments)
                . ' WHERE id = CAST(:id AS uuid) AND archived_at IS NULL RETURNING '
                . self::RETURNING_COLUMNS,
                $params,
            )
            ->queryOne();

        return is_array($row) ? self::map($row) : null;
    }

    public function archive(string $id): ?PersonView
    {
        $row = $this->db
            ->createCommand(
                'UPDATE core.persons '
                . "SET status = 'archived', archived_at = COALESCE(archived_at, now()), updated_at = now() "
                . 'WHERE id = CAST(:id AS uuid) RETURNING '
                . self::RETURNING_COLUMNS,
                [':id' => $id],
            )
            ->queryOne();

        return is_array($row) ? self::map($row) : null;
    }

    private const string SELECT_COLUMNS = <<<'SQL'
        SELECT
            id::text AS id,
            status::text AS status,
            given_name,
            family_name,
            middle_name,
            display_name,
            birth_date::text AS birth_date,
            to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
            to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS updated_at,
            to_char(archived_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS archived_at
        SQL;

    private const string RETURNING_COLUMNS = <<<'SQL'
        id::text AS id,
        status::text AS status,
        given_name,
        family_name,
        middle_name,
        display_name,
        birth_date::text AS birth_date,
        to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
        to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS updated_at,
        to_char(archived_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS archived_at
        SQL;

    /**
     * @param array<array-key, mixed> $row
     */
    private static function map(array $row): PersonView
    {
        return new PersonView(
            id: (string) $row['id'],
            status: (string) $row['status'],
            givenName: (string) $row['given_name'],
            familyName: (string) $row['family_name'],
            middleName: self::nullableString($row['middle_name']),
            displayName: self::nullableString($row['display_name']),
            birthDate: self::nullableString($row['birth_date']),
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
            archivedAt: self::nullableString($row['archived_at']),
        );
    }

    /**
     * @param list<string> $assignments
     * @param array<non-empty-string, mixed> $params
     */
    private static function addUpdate(
        array &$assignments,
        array &$params,
        string $column,
        bool $provided,
        ?string $value,
    ): void {
        if (!$provided) {
            return;
        }

        $parameter = ':' . $column;
        $assignments[] = $column . ' = ' . $parameter;
        $params[$parameter] = $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
