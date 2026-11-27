<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use App\Activity\Application\ActivityDomainPolicyInterface;
use App\Activity\Application\ActivityPayload;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Rbac\Application\PermissionService;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Education domain policy adapter for the Universal Activity Engine.
 * Enforces group teaching rights (education.can_teach_group), student enrollment,
 * confirmed parent/guardian representation, and homework recipient scoping.
 */
final readonly class EducationActivityDomainPolicy implements ActivityDomainPolicyInterface
{
    public function __construct(
        private ConnectionInterface $db,
        private PermissionService $permissions,
    ) {}

    public function canAccessActivity(
        AuthenticatedIdentity $identity,
        string $organizationId,
        array $activityRow,
    ): bool {
        $personId = $identity->personId ?? '';
        if ($personId === '') {
            return false;
        }

        $authorId = (string) ($activityRow['authorPersonId'] ?? $activityRow['author_person_id'] ?? '');
        if ($authorId === $personId || $this->isOrgAdmin($identity->accountId)) {
            return true;
        }

        $status = (string) ($activityRow['status'] ?? 'DRAFT');
        if ($status !== 'PUBLISHED') {
            return false;
        }

        $visibility = (string) ($activityRow['visibility'] ?? 'organization');
        $activityId = (string) ($activityRow['id'] ?? '');
        $config = ActivityPayload::assoc($activityRow['config'] ?? null);

        if ($visibility === 'private') {
            return $activityId !== '' && $this->hasActiveAssignmentForActivity($identity, $organizationId, $activityId);
        }

        if ($visibility === 'group') {
            if ($this->isTeacherInOrg($identity, $organizationId)) {
                return true;
            }
            return $activityId !== '' && $this->hasActiveAssignmentForActivity($identity, $organizationId, $activityId);
        }

        if ($visibility === 'organization') {
            if (!empty($config['examOnly']) || (array_key_exists('openPractice', $config) && $config['openPractice'] === false)) {
                if ($this->isTeacherInOrg($identity, $organizationId)) {
                    return true;
                }
                return $activityId !== '' && $this->hasActiveAssignmentForActivity($identity, $organizationId, $activityId);
            }
            return true;
        }

        // public_catalog
        return true;
    }

    public function canStartOpenPractice(
        AuthenticatedIdentity $identity,
        string $organizationId,
        array $activityRow,
    ): bool {
        if (($activityRow['status'] ?? '') !== 'PUBLISHED') {
            return false;
        }

        $config = ActivityPayload::assoc($activityRow['config'] ?? null);
        if (!empty($config['examOnly'])) {
            return false;
        }
        if (array_key_exists('openPractice', $config) && $config['openPractice'] === false) {
            return false;
        }

        $visibility = (string) ($activityRow['visibility'] ?? 'organization');
        if (in_array($visibility, ['private', 'group'], true)) {
            return false;
        }

        return in_array($visibility, ['public_catalog', 'organization'], true);
    }

    public function canAssignTarget(
        AuthenticatedIdentity $identity,
        string $organizationId,
        string $targetType,
        string $targetId,
    ): bool {
        $personId = $identity->personId ?? '';
        if ($personId === '') {
            return false;
        }
        if ($this->isOrgAdmin($identity->accountId)) {
            return true;
        }

        return match ($targetType) {
            'group' => (bool) $this->db->createCommand(
                'SELECT EXISTS(
                    SELECT 1 FROM education.groups g
                    WHERE g.organization_id = CAST(:org AS uuid)
                      AND g.id = CAST(:gid AS uuid)
                      AND education.can_teach_group(g.organization_id, CAST(:actor AS uuid), g.id)
                )',
                [':org' => $organizationId, ':gid' => $targetId, ':actor' => $identity->accountId],
            )->queryScalar(),
            'student' => (bool) $this->db->createCommand(
                'SELECT EXISTS(
                    SELECT 1 FROM education.students s
                    WHERE s.organization_id = CAST(:org AS uuid)
                      AND s.id = CAST(:sid AS uuid)
                      AND (
                          s.owner_account_id = CAST(:actor AS uuid)
                          OR EXISTS(
                              SELECT 1 FROM education.group_students gs
                              WHERE gs.organization_id = s.organization_id
                                AND gs.student_id = s.id
                                AND education.can_teach_group(gs.organization_id, CAST(:actor AS uuid), gs.group_id)
                          )
                      )
                )',
                [':org' => $organizationId, ':sid' => $targetId, ':actor' => $identity->accountId],
            )->queryScalar(),
            'person' => $targetId === $personId || (bool) $this->db->createCommand(
                'SELECT EXISTS(
                    SELECT 1 FROM education.students s
                    WHERE s.organization_id = CAST(:org AS uuid)
                      AND s.person_id = CAST(:pid AS uuid)
                      AND (
                          s.owner_account_id = CAST(:actor AS uuid)
                          OR EXISTS(
                              SELECT 1 FROM education.group_students gs
                              WHERE gs.organization_id = s.organization_id
                                AND gs.student_id = s.id
                                AND education.can_teach_group(gs.organization_id, CAST(:actor AS uuid), gs.group_id)
                          )
                      )
                )',
                [':org' => $organizationId, ':pid' => $targetId, ':actor' => $identity->accountId],
            )->queryScalar(),
            'lesson' => (bool) $this->db->createCommand(
                'SELECT EXISTS(
                    SELECT 1 FROM education.lessons l
                    WHERE l.organization_id = CAST(:org AS uuid)
                      AND l.id = CAST(:lid AS uuid)
                      AND education.can_teach_group(l.organization_id, CAST(:actor AS uuid), l.group_id)
                )',
                [':org' => $organizationId, ':lid' => $targetId, ':actor' => $identity->accountId],
            )->queryScalar(),
            'homework' => (bool) $this->db->createCommand(
                'SELECT EXISTS(
                    SELECT 1 FROM education.homework_assignments h
                    WHERE h.organization_id = CAST(:org AS uuid)
                      AND h.id = CAST(:hid AS uuid)
                      AND (
                          h.author_account_id = CAST(:actor AS uuid)
                          OR (h.group_id IS NOT NULL AND education.can_teach_group(h.organization_id, CAST(:actor AS uuid), h.group_id))
                      )
                )',
                [':org' => $organizationId, ':hid' => $targetId, ':actor' => $identity->accountId],
            )->queryScalar(),
            'room' => (bool) $this->db->createCommand(
                'SELECT EXISTS(
                    SELECT 1 FROM chat.members m
                    WHERE m.organization_id = CAST(:org AS uuid)
                      AND m.room_id = CAST(:rid AS uuid)
                      AND m.person_id = CAST(:pid AS uuid)
                      AND m.left_at IS NULL
                )',
                [':org' => $organizationId, ':rid' => $targetId, ':pid' => $personId],
            )->queryScalar(),
            'organization' => false,
            default => false,
        };
    }

    public function resolveAssignmentRecipients(
        string $organizationId,
        string $targetType,
        string $targetId,
        string $assignerPersonId,
    ): array {
        if ($targetType === 'person') {
            return [$targetId];
        }

        $sql = match ($targetType) {
            'student' => 'SELECT person_id::text FROM education.students WHERE organization_id = CAST(:org AS uuid) AND id = CAST(:tid AS uuid)',
            'group' => 'SELECT s.person_id::text FROM education.group_students gs JOIN education.students s ON s.id = gs.student_id WHERE gs.organization_id = CAST(:org AS uuid) AND gs.group_id = CAST(:tid AS uuid)',
            'lesson' => 'SELECT DISTINCT s.person_id::text FROM education.lessons l JOIN education.group_students gs ON gs.group_id = l.group_id AND gs.organization_id = l.organization_id JOIN education.students s ON s.id = gs.student_id WHERE l.organization_id = CAST(:org AS uuid) AND l.id = CAST(:tid AS uuid)',
            'homework' => 'SELECT DISTINCT hr.person_id::text FROM education.homework_recipients hr WHERE hr.organization_id = CAST(:org AS uuid) AND (hr.assignment_id = CAST(:tid AS uuid) OR hr.activity_assignment_id = CAST(:tid AS uuid))',
            'room' => 'SELECT person_id::text FROM chat.members WHERE organization_id = CAST(:org AS uuid) AND room_id = CAST(:tid AS uuid) AND left_at IS NULL AND person_id <> CAST(:by AS uuid)',
            'organization' => "SELECT person_id::text FROM core.organization_memberships WHERE organization_id = CAST(:org AS uuid) AND status = 'active' AND person_id <> CAST(:by AS uuid)",
            default => null,
        };

        if ($sql === null) {
            return [];
        }

        $params = in_array($targetType, ['room', 'organization'], true)
            ? [':org' => $organizationId, ':tid' => $targetId, ':by' => $assignerPersonId]
            : [':org' => $organizationId, ':tid' => $targetId];

        $rows = $this->db->createCommand($sql, $params)->queryColumn();
        return array_values(array_unique(ActivityPayload::stringList($rows)));
    }

    public function isAssignmentRecipient(
        AuthenticatedIdentity $identity,
        string $organizationId,
        array $assignmentRow,
    ): bool {
        $personId = $identity->personId ?? '';
        if ($personId === '') {
            return false;
        }
        return $this->isPersonAssignmentRecipient($personId, $organizationId, $assignmentRow);
    }

    public function canViewAssignment(
        AuthenticatedIdentity $identity,
        string $organizationId,
        array $assignmentRow,
    ): bool {
        $personId = $identity->personId ?? '';
        if ($personId === '') {
            return false;
        }
        if ($this->isOrgAdmin($identity->accountId)) {
            return true;
        }
        if ($this->isAssignmentRecipient($identity, $organizationId, $assignmentRow)) {
            return true;
        }

        $assignedBy = (string) ($assignmentRow['assignedByPersonId'] ?? $assignmentRow['assigned_by_person_id'] ?? '');
        if ($assignedBy === $personId) {
            return true;
        }

        $targetType = (string) ($assignmentRow['targetType'] ?? $assignmentRow['target_type'] ?? '');
        $targetId = (string) ($assignmentRow['targetId'] ?? $assignmentRow['target_id'] ?? '');

        if ($this->permissions->hasPermission($identity->accountId, 'activity.assign')
            || $this->permissions->hasPermission($identity->accountId, 'activity.review')
            || $this->permissions->hasPermission($identity->accountId, 'education.activities.manage')) {
            if ($targetType !== '' && $targetId !== '' && $this->canAssignTarget($identity, $organizationId, $targetType, $targetId)) {
                return true;
            }
        }

        if ($this->permissions->hasPermission($identity->accountId, 'education.parent.use')) {
            foreach ($this->confirmedChildPersonIds($identity->accountId, $personId, $organizationId) as $childPersonId) {
                if ($this->isPersonAssignmentRecipient($childPersonId, $organizationId, $assignmentRow)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function canAccessParticipantAttempt(
        AuthenticatedIdentity $identity,
        string $organizationId,
        string $participantPersonId,
        ?string $assignmentId,
        ?string $activityAuthorPersonId,
    ): bool {
        $personId = $identity->personId ?? '';
        if ($personId === '') {
            return false;
        }
        if ($participantPersonId === $personId || $this->isOrgAdmin($identity->accountId)) {
            return true;
        }

        if ($this->permissions->hasPermission($identity->accountId, 'activity.review')) {
            if ($activityAuthorPersonId !== null && $activityAuthorPersonId === $personId) {
                return true;
            }
            if ($this->teachesStudentPerson($identity->accountId, $organizationId, $participantPersonId)) {
                return true;
            }
            if ($assignmentId !== null) {
                $asgn = $this->db->createCommand(
                    'SELECT target_type AS "targetType", target_id::text AS "targetId", assigned_by_person_id::text AS "assignedBy"
                     FROM activity.assignments
                     WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)',
                    [':id' => $assignmentId, ':org' => $organizationId],
                )->queryOne();
                if (is_array($asgn)) {
                    if ((string) ($asgn['assignedBy'] ?? '') === $personId) {
                        return true;
                    }
                    if ($this->canAssignTarget($identity, $organizationId, (string) $asgn['targetType'], (string) $asgn['targetId'])) {
                        return true;
                    }
                }
            }
        }

        if ($this->permissions->hasPermission($identity->accountId, 'education.parent.use')) {
            return $this->isConfirmedGuardianOfPerson($identity->accountId, $personId, $organizationId, $participantPersonId);
        }

        return false;
    }

    public function canViewParticipantAnalytics(
        AuthenticatedIdentity $identity,
        string $organizationId,
        string $targetPersonId,
    ): bool {
        $personId = $identity->personId ?? '';
        if ($personId === '') {
            return false;
        }
        if ($targetPersonId === $personId || $this->isOrgAdmin($identity->accountId)) {
            return true;
        }
        if ($this->permissions->hasPermission($identity->accountId, 'activity.review')
            && $this->teachesStudentPerson($identity->accountId, $organizationId, $targetPersonId)) {
            return true;
        }
        if ($this->permissions->hasPermission($identity->accountId, 'education.parent.use')
            && $this->isConfirmedGuardianOfPerson($identity->accountId, $personId, $organizationId, $targetPersonId)) {
            return true;
        }
        return false;
    }

    /**
     * @param array<string, mixed> $assignmentRow
     */
    private function isPersonAssignmentRecipient(
        string $personId,
        string $organizationId,
        array $assignmentRow,
    ): bool {
        $targetType = (string) ($assignmentRow['targetType'] ?? $assignmentRow['target_type'] ?? '');
        $targetId = (string) ($assignmentRow['targetId'] ?? $assignmentRow['target_id'] ?? '');
        $assignId = (string) ($assignmentRow['id'] ?? '');

        $matched = match ($targetType) {
            'person' => $targetId === $personId,
            'student' => (bool) $this->db->createCommand(
                'SELECT EXISTS(
                    SELECT 1 FROM education.students s
                    LEFT JOIN education.student_accounts sa ON sa.student_id = s.id AND sa.organization_id = s.organization_id
                    WHERE s.id = CAST(:tid AS uuid) AND s.organization_id = CAST(:org AS uuid)
                      AND s.person_id = CAST(:person AS uuid) AND sa.revoked_at IS NULL
                )',
                [':tid' => $targetId, ':org' => $organizationId, ':person' => $personId],
            )->queryScalar(),
            'group' => (bool) $this->db->createCommand(
                'SELECT EXISTS(
                    SELECT 1 FROM education.group_students gs
                    JOIN education.students s ON s.id = gs.student_id AND s.organization_id = gs.organization_id
                    LEFT JOIN education.student_accounts sa ON sa.student_id = s.id AND sa.organization_id = s.organization_id
                    WHERE gs.group_id = CAST(:tid AS uuid) AND gs.organization_id = CAST(:org AS uuid)
                      AND s.person_id = CAST(:person AS uuid) AND sa.revoked_at IS NULL
                )',
                [':tid' => $targetId, ':org' => $organizationId, ':person' => $personId],
            )->queryScalar(),
            'lesson' => (bool) $this->db->createCommand(
                'SELECT EXISTS(
                    SELECT 1 FROM education.lessons l
                    JOIN education.group_students gs ON gs.group_id = l.group_id AND gs.organization_id = l.organization_id
                    JOIN education.students s ON s.id = gs.student_id AND s.organization_id = gs.organization_id
                    LEFT JOIN education.student_accounts sa ON sa.student_id = s.id AND sa.organization_id = s.organization_id
                    WHERE l.id = CAST(:tid AS uuid) AND l.organization_id = CAST(:org AS uuid)
                      AND s.person_id = CAST(:person AS uuid) AND sa.revoked_at IS NULL
                )',
                [':tid' => $targetId, ':org' => $organizationId, ':person' => $personId],
            )->queryScalar(),
            'homework' => (bool) $this->db->createCommand(
                "SELECT EXISTS(
                    SELECT 1 FROM education.homework_recipients hr
                    JOIN education.homework_assignments ha ON ha.id = hr.assignment_id AND ha.organization_id = hr.organization_id
                    WHERE hr.organization_id = CAST(:org AS uuid)
                      AND hr.person_id = CAST(:person AS uuid)
                      AND ha.status = 'PUBLISHED'
                      AND (
                          hr.assignment_id = CAST(:tid AS uuid)
                          OR hr.activity_assignment_id = CAST(:tid AS uuid)
                          OR (CAST(:aid AS uuid) IS NOT NULL AND hr.activity_assignment_id = CAST(:aid AS uuid))
                      )
                )",
                [':tid' => $targetId, ':aid' => $assignId !== '' ? $assignId : null, ':org' => $organizationId, ':person' => $personId],
            )->queryScalar(),
            'room' => (bool) $this->db->createCommand(
                'SELECT EXISTS(
                    SELECT 1 FROM chat.members m
                    WHERE m.organization_id = CAST(:org AS uuid) AND m.room_id = CAST(:tid AS uuid)
                      AND m.person_id = CAST(:person AS uuid) AND m.left_at IS NULL
                )',
                [':tid' => $targetId, ':org' => $organizationId, ':person' => $personId],
            )->queryScalar(),
            'organization' => true,
            default => false,
        };

        if ($matched) {
            return true;
        }

        // Also allow homework-linked activity assignments where recipient record explicitly references assignment ID
        if ($assignId !== '') {
            return (bool) $this->db->createCommand(
                "SELECT EXISTS(
                    SELECT 1 FROM education.homework_recipients hr
                    JOIN education.homework_assignments ha ON ha.id = hr.assignment_id AND ha.organization_id = hr.organization_id
                    WHERE hr.organization_id = CAST(:org AS uuid)
                      AND hr.person_id = CAST(:person AS uuid)
                      AND ha.status = 'PUBLISHED'
                      AND hr.activity_assignment_id = CAST(:aid AS uuid)
                )",
                [':org' => $organizationId, ':person' => $personId, ':aid' => $assignId],
            )->queryScalar();
        }

        return false;
    }

    private function hasActiveAssignmentForActivity(
        AuthenticatedIdentity $identity,
        string $organizationId,
        string $activityId,
    ): bool {
        $rows = $this->db->createCommand(
            "SELECT id::text, target_type AS \"targetType\", target_id::text AS \"targetId\"
             FROM activity.assignments
             WHERE organization_id = CAST(:org AS uuid)
               AND activity_id = CAST(:aid AS uuid)
               AND status = 'ACTIVE'
               AND revoked_at IS NULL",
            [':org' => $organizationId, ':aid' => $activityId],
        )->queryAll();

        foreach ($rows as $row) {
            if ($this->isAssignmentRecipient($identity, $organizationId, ActivityPayload::assoc($row))) {
                return true;
            }
        }
        return false;
    }

    private function teachesStudentPerson(string $actorAccountId, string $organizationId, string $studentPersonId): bool
    {
        return (bool) $this->db->createCommand(
            'SELECT EXISTS(
                SELECT 1 FROM education.students s
                WHERE s.organization_id = CAST(:org AS uuid)
                  AND s.person_id = CAST(:pid AS uuid)
                  AND (
                      s.owner_account_id = CAST(:actor AS uuid)
                      OR EXISTS(
                          SELECT 1 FROM education.group_students gs
                          WHERE gs.organization_id = s.organization_id
                            AND gs.student_id = s.id
                            AND education.can_teach_group(gs.organization_id, CAST(:actor AS uuid), gs.group_id)
                      )
                  )
            )',
            [':org' => $organizationId, ':pid' => $studentPersonId, ':actor' => $actorAccountId],
        )->queryScalar();
    }

    private function isConfirmedGuardianOfPerson(
        string $accountId,
        string $guardianPersonId,
        string $organizationId,
        string $studentPersonId,
    ): bool {
        return (bool) $this->db->createCommand(
            "SELECT EXISTS(
                SELECT 1 FROM education.student_guardians sg
                JOIN education.students s ON s.id = sg.student_id AND s.organization_id = sg.organization_id
                JOIN education.parent_accounts pa ON pa.organization_id = sg.organization_id AND pa.guardian_person_id = sg.guardian_person_id
                WHERE sg.organization_id = CAST(:org AS uuid)
                  AND sg.guardian_person_id = CAST(:guardian AS uuid)
                  AND pa.account_id = CAST(:account AS uuid)
                  AND pa.revoked_at IS NULL
                  AND sg.status = 'confirmed'
                  AND s.person_id = CAST(:studentPerson AS uuid)
            )",
            [
                ':org' => $organizationId,
                ':guardian' => $guardianPersonId,
                ':account' => $accountId,
                ':studentPerson' => $studentPersonId,
            ],
        )->queryScalar();
    }

    /** @return list<string> */
    private function confirmedChildPersonIds(string $accountId, string $guardianPersonId, string $organizationId): array
    {
        $rows = $this->db->createCommand(
            "SELECT DISTINCT s.person_id::text
             FROM education.student_guardians sg
             JOIN education.students s ON s.id = sg.student_id AND s.organization_id = sg.organization_id
             JOIN education.parent_accounts pa ON pa.organization_id = sg.organization_id AND pa.guardian_person_id = sg.guardian_person_id
             WHERE sg.organization_id = CAST(:org AS uuid)
               AND sg.guardian_person_id = CAST(:guardian AS uuid)
               AND pa.account_id = CAST(:account AS uuid)
               AND pa.revoked_at IS NULL
               AND sg.status = 'confirmed'",
            [':org' => $organizationId, ':guardian' => $guardianPersonId, ':account' => $accountId],
        )->queryColumn();

        return ActivityPayload::stringList($rows);
    }

    private function isTeacherInOrg(AuthenticatedIdentity $identity, string $organizationId): bool
    {
        if (!$this->permissions->hasPermission($identity->accountId, 'activity.create')
            && !$this->permissions->hasPermission($identity->accountId, 'activity.assign')
            && !$this->permissions->hasPermission($identity->accountId, 'activity.review')
            && !$this->permissions->hasPermission($identity->accountId, 'education.activities.manage')) {
            return false;
        }
        return (bool) $this->db->createCommand(
            'SELECT EXISTS(
                SELECT 1 FROM education.teachers t
                WHERE t.organization_id = CAST(:org AS uuid) AND t.account_id = CAST(:account AS uuid)
            )',
            [':org' => $organizationId, ':account' => $identity->accountId],
        )->queryScalar();
    }

    private function isOrgAdmin(string $accountId): bool
    {
        if ($this->permissions->hasPermission($accountId, 'organizations.manage')) {
            return true;
        }
        foreach ($this->permissions->getRoles($accountId) as $role) {
            if (in_array($role->code, ['core.admin', 'core.superadmin'], true)) {
                return true;
            }
        }
        return false;
    }
}
