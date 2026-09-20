<?php

declare(strict_types=1);

namespace App\Core\Person\Infrastructure;

use App\Core\Person\Application\PersonRepository;
use App\Core\Person\Application\PersonView;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresPersonRepository implements PersonRepository
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function findAll(): array
    {
        $rows = $this->db
            ->createCommand(
                <<<'SQL'
                SELECT
                    id::text AS id,
                    status::text AS status,
                    given_name,
                    family_name,
                    middle_name,
                    display_name,
                    birth_date::text AS birth_date,
                    created_at::text AS created_at,
                    updated_at::text AS updated_at,
                    archived_at::text AS archived_at
                FROM core.persons
                WHERE archived_at IS NULL
                ORDER BY family_name, given_name, id
                SQL,
            )
            ->queryAll();

        return array_map(
            static fn(array $row): PersonView => new PersonView(
                id: (string) $row['id'],
                status: (string) $row['status'],
                givenName: (string) $row['given_name'],
                familyName: (string) $row['family_name'],
                middleName: $row['middle_name'] !== null
                    ? (string) $row['middle_name']
                    : null,
                displayName: $row['display_name'] !== null
                    ? (string) $row['display_name']
                    : null,
                birthDate: $row['birth_date'] !== null
                    ? (string) $row['birth_date']
                    : null,
                createdAt: (string) $row['created_at'],
                updatedAt: (string) $row['updated_at'],
                archivedAt: $row['archived_at'] !== null
                    ? (string) $row['archived_at']
                    : null,
            ),
            $rows,
        );
    }
}
