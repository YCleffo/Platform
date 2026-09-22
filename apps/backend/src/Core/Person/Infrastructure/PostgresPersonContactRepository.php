<?php

declare(strict_types=1);

namespace App\Core\Person\Infrastructure;

use App\Core\Person\Application\CreatePersonContactCommand;
use App\Core\Person\Application\PersonContactRepository;
use App\Core\Person\Application\PersonContactView;
use App\Core\Shared\Application\ConflictException;
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;

final readonly class PostgresPersonContactRepository implements PersonContactRepository
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function findByPersonId(string $personId): array
    {
        $rows = $this->db
            ->createCommand(
                self::SELECT_COLUMNS . <<<'SQL'

                FROM core.person_contacts
                WHERE person_id = CAST(:person_id AS uuid)
                ORDER BY contact_type, is_primary DESC, created_at, id
                SQL,
                [':person_id' => $personId],
            )
            ->queryAll();

        /** @var list<array<array-key, mixed>> $rows */
        return array_map(self::map(...), $rows);
    }

    public function create(
        CreatePersonContactCommand $command,
        string $normalizedValue,
    ): PersonContactView {
        try {
            /** @var PersonContactView */
            return $this->db->transaction(
                function (ConnectionInterface $db) use ($command, $normalizedValue): PersonContactView {
                    if ($command->isPrimary) {
                        $db->createCommand(
                            <<<'SQL'
                            UPDATE core.person_contacts
                            SET is_primary = false, updated_at = now()
                            WHERE person_id = CAST(:person_id AS uuid)
                                AND contact_type = CAST(:contact_type AS core.contact_type)
                                AND is_primary = true
                            SQL,
                            [
                                ':person_id' => $command->personId,
                                ':contact_type' => $command->contactType,
                            ],
                        )->execute();
                    }

                    $row = $db->createCommand(
                        <<<'SQL'
                        INSERT INTO core.person_contacts (
                            person_id,
                            contact_type,
                            value,
                            normalized_value,
                            label,
                            is_primary
                        )
                        VALUES (
                            CAST(:person_id AS uuid),
                            CAST(:contact_type AS core.contact_type),
                            :value,
                            :normalized_value,
                            :label,
                            :is_primary
                        )
                        RETURNING
                            id::text AS id,
                            person_id::text AS person_id,
                            contact_type::text AS contact_type,
                            value,
                            normalized_value,
                            label,
                            is_primary,
                            to_char(verified_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS verified_at,
                            to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
                            to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS updated_at
                        SQL,
                        [
                            ':person_id' => $command->personId,
                            ':contact_type' => $command->contactType,
                            ':value' => $command->value,
                            ':normalized_value' => $normalizedValue,
                            ':label' => $command->label,
                            ':is_primary' => $command->isPrimary,
                        ],
                    )->queryOne();

                    if (!is_array($row)) {
                        throw new RuntimeException('PostgreSQL did not return the created contact.');
                    }

                    return self::map($row);
                },
            );
        } catch (IntegrityException $exception) {
            throw new ConflictException(
                'The contact conflicts with an existing contact.',
                previous: $exception,
            );
        }
    }

    private const string SELECT_COLUMNS = <<<'SQL'
        SELECT
            id::text AS id,
            person_id::text AS person_id,
            contact_type::text AS contact_type,
            value,
            normalized_value,
            label,
            is_primary,
            to_char(verified_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS verified_at,
            to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS created_at,
            to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS updated_at
        SQL;

    /**
     * @param array<array-key, mixed> $row
     */
    private static function map(array $row): PersonContactView
    {
        return new PersonContactView(
            id: (string) $row['id'],
            personId: (string) $row['person_id'],
            contactType: (string) $row['contact_type'],
            value: (string) $row['value'],
            normalizedValue: (string) $row['normalized_value'],
            label: $row['label'] === null ? null : (string) $row['label'],
            isPrimary: $row['is_primary'] === true || $row['is_primary'] === 't' || $row['is_primary'] === 1,
            verifiedAt: $row['verified_at'] === null ? null : (string) $row['verified_at'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }
}
