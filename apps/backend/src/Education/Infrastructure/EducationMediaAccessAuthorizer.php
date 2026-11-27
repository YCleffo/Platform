<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use App\Media\Application\MediaAccessAuthorizer;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Authorizes access to Education-linked media via explicit domain memberships
 * (homework assignment materials and homework submission files).
 * Chat-linked media is authorized exclusively by ChatMediaAccessAuthorizer.
 */
final readonly class EducationMediaAccessAuthorizer implements MediaAccessAuthorizer
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function canRead(string $organizationId, string $personId, string $assetId): bool
    {
        $inHomeworkFile = $this->db->createCommand(
            'SELECT 1 FROM education.homework_files hf
             JOIN education.homework_assignments ha
               ON ha.id = hf.assignment_id
              AND ha.organization_id = hf.organization_id
             JOIN media.assets ma
               ON ma.id = CAST(:id AS uuid)
              AND ma.organization_id = hf.organization_id
             WHERE hf.organization_id = CAST(:org AS uuid)
               AND (hf.id = ma.id OR hf.storage_key = ma.storage_key)
               AND (
                   ha.author_person_id = CAST(:person AS uuid)
                   OR EXISTS (
                       SELECT 1 FROM education.group_teachers gt
                       JOIN core.accounts acc ON acc.id = gt.account_id
                       WHERE gt.group_id = ha.group_id
                         AND gt.organization_id = ha.organization_id
                         AND acc.person_id = CAST(:person AS uuid)
                   )
                   OR (
                       hf.purpose = \'material\'
                       AND EXISTS (
                           SELECT 1 FROM education.homework_recipients hr
                           JOIN education.students s ON s.id = hr.student_id
                           WHERE hr.assignment_id = ha.id
                             AND (
                                 s.person_id = CAST(:person AS uuid)
                                 OR EXISTS (
                                     SELECT 1 FROM education.student_guardians sg
                                     JOIN education.parent_accounts pa
                                       ON pa.organization_id = sg.organization_id
                                      AND pa.guardian_person_id = sg.guardian_person_id
                                     WHERE sg.student_id = s.id
                                       AND sg.organization_id = s.organization_id
                                       AND sg.guardian_person_id = CAST(:person AS uuid)
                                       AND sg.status = \'confirmed\'
                                       AND pa.revoked_at IS NULL
                                 )
                             )
                       )
                   )
                   OR (
                       hf.purpose = \'submission\'
                       AND hf.recipient_id IS NOT NULL
                       AND EXISTS (
                           SELECT 1 FROM education.homework_recipients hr
                           JOIN education.students s ON s.id = hr.student_id
                           WHERE hr.id = hf.recipient_id
                             AND (
                                 s.person_id = CAST(:person AS uuid)
                                 OR EXISTS (
                                     SELECT 1 FROM education.student_guardians sg
                                     JOIN education.parent_accounts pa
                                       ON pa.organization_id = sg.organization_id
                                      AND pa.guardian_person_id = sg.guardian_person_id
                                     WHERE sg.student_id = s.id
                                       AND sg.organization_id = s.organization_id
                                       AND sg.guardian_person_id = CAST(:person AS uuid)
                                       AND sg.status = \'confirmed\'
                                       AND pa.revoked_at IS NULL
                                 )
                             )
                       )
                   )
               )
             LIMIT 1',
            [':id' => $assetId, ':org' => $organizationId, ':person' => $personId],
        )->queryScalar();

        return $inHomeworkFile !== false;
    }
}
