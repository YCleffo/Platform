<?php

declare(strict_types=1);

namespace App\Education\Application;

use App\Core\Person\Application\ListPersonContacts;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Called after the existing teacher student-card authorization. */
final readonly class StudentFamily
{
    public function __construct(
        private ConnectionInterface $db,
        private EducationAccess $access,
        private ListPersonContacts $contacts,
    ) {}

    public function guardians(Scope $scope, string $student): array
    {
        $rows = $this->db->createCommand('SELECT guardian_person_id::text AS "personId",relationship
            FROM education.student_guardians WHERE student_id=CAST(:student AS uuid) AND organization_id=CAST(:org AS uuid)
            ORDER BY created_at,guardian_person_id', [':student' => $student, ':org' => $scope->organizationId])->queryAll();
        $result = [];
        foreach ($rows as $row) {
            if (!$this->access->member($row['personId'], $scope->organizationId)) {
                continue;
            }
            $name = $this->access->personName($row['personId']);
            if ($name === null) {
                continue;
            }
            $result[] = [...$row, 'name' => $name, 'contacts' => array_map(
                static fn($contact): array =>
                ['type' => $contact->contactType, 'value' => $contact->value, 'label' => $contact->label],
                $this->contacts->execute($row['personId']) ?? [],
            )];
        }
        return $result;
    }
}
