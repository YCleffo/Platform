<?php

declare(strict_types=1);

namespace ControlPlane\Privacy;

use Doctrine\DBAL\Connection;

final readonly class ControlPersonalDataExporter
{
    /** @param iterable<PersonalDataContributor> $contributors */
    public function __construct(private Connection $connection, private iterable $contributors)
    {
    }

    /** @return array{schema_version:int, subject_id:string, data:array<string, list<array<string, mixed>>|array{sessions:list<array<string,mixed>>,mfa:list<array<string,mixed>>}>} */
    public function exportSelf(string $actorId, string $subjectId): array
    {
        if ($actorId !== $subjectId) {
            throw new \DomainException('Control Plane export requires self authorization.');
        }
        return $this->connection->transactional(function (Connection $connection) use ($actorId, $subjectId): array {
            if ($connection->fetchOne('SELECT 1 FROM commercial.control_users WHERE id = ?', [$subjectId]) === false) {
                throw new \DomainException('Control User not found.');
            }
            $data = [];
            $count = 0;
            foreach ($this->contributors as $contributor) {
                if (isset($data[$contributor->code()])) {
                    throw new \LogicException('Duplicate personal data contributor.');
                }
                $data[$contributor->code()] = $contributor->export($subjectId);
                $count += count($data[$contributor->code()]);
            }
            $connection->executeStatement('INSERT INTO commercial.privacy_export_events (control_user_id, actor_id, item_count) VALUES (?, ?, ?)', [$subjectId, $actorId, $count]);

            return ['schema_version' => 1, 'subject_id' => $subjectId, 'data' => $data];
        });
    }
}
