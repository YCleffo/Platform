<?php

declare(strict_types=1);

namespace App\Activity\Infrastructure;

use App\Activity\Application\ActivityDomainPolicyInterface;
use App\Activity\Application\ActivityPayload;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Rbac\Application\PermissionService;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Default domain policy used when no product-specific adapter (such as Education) is bound.
 * Contains zero product-specific SQL.
 */
final readonly class DefaultActivityDomainPolicy implements ActivityDomainPolicyInterface
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

        if ($visibility === 'private') {
            return $activityId !== '' && $this->hasActiveAssignmentForActivity($identity, $organizationId, $activityId);
        }

        if ($visibility === 'group') {
            return $this->permissions->hasPermission($identity->accountId, 'activity.create')
                || $this->permissions->hasPermission($identity->accountId, 'activity.assign')
                || ($activityId !== '' && $this->hasActiveAssignmentForActivity($identity, $organizationId, $activityId));
        }

        $config = ActivityPayload::assoc($activityRow['config'] ?? null);
        if (!empty($config['examOnly']) || (array_key_exists('openPractice', $config) && $config['openPractice'] === false)) {
            if ($this->permissions->hasPermission($identity->accountId, 'activity.create')
                || $this->permissions->hasPermission($identity->accountId, 'activity.assign')
                || $this->permissions->hasPermission($identity->accountId, 'activity.review')) {
                return true;
            }
            return $activityId !== '' && $this->hasActiveAssignmentForActivity($identity, $organizationId, $activityId);
        }

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
            'person' => $targetId === $personId || (bool) $this->db->createCommand(
                "SELECT EXISTS(
                    SELECT 1 FROM core.organization_memberships
                    WHERE organization_id = CAST(:org AS uuid) AND person_id = CAST(:pid AS uuid) AND status = 'active'
                )",
                [':org' => $organizationId, ':pid' => $targetId],
            )->queryScalar(),
            'room' => (bool) $this->db->createCommand(
                'SELECT EXISTS(
                    SELECT 1 FROM chat.members
                    WHERE organization_id = CAST(:org AS uuid) AND room_id = CAST(:rid AS uuid)
                      AND person_id = CAST(:pid AS uuid) AND left_at IS NULL
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
        if ($targetType === 'room') {
            $rows = $this->db->createCommand(
                'SELECT person_id::text FROM chat.members
                 WHERE organization_id = CAST(:org AS uuid) AND room_id = CAST(:tid AS uuid)
                   AND left_at IS NULL AND person_id <> CAST(:by AS uuid)',
                [':org' => $organizationId, ':tid' => $targetId, ':by' => $assignerPersonId],
            )->queryColumn();
            return ActivityPayload::stringList($rows);
        }
        if ($targetType === 'organization') {
            $rows = $this->db->createCommand(
                "SELECT person_id::text FROM core.organization_memberships
                 WHERE organization_id = CAST(:org AS uuid) AND status = 'active' AND person_id <> CAST(:by AS uuid)",
                [':org' => $organizationId, ':by' => $assignerPersonId],
            )->queryColumn();
            return ActivityPayload::stringList($rows);
        }

        return [];
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
        $targetType = (string) ($assignmentRow['targetType'] ?? $assignmentRow['target_type'] ?? '');
        $targetId = (string) ($assignmentRow['targetId'] ?? $assignmentRow['target_id'] ?? '');

        return match ($targetType) {
            'person' => $targetId === $personId,
            'organization' => true,
            'room' => (bool) $this->db->createCommand(
                'SELECT EXISTS(
                    SELECT 1 FROM chat.members
                    WHERE organization_id = CAST(:org AS uuid) AND room_id = CAST(:tid AS uuid)
                      AND person_id = CAST(:pid AS uuid) AND left_at IS NULL
                )',
                [':org' => $organizationId, ':tid' => $targetId, ':pid' => $personId],
            )->queryScalar(),
            default => false,
        };
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
        $assignedBy = (string) ($assignmentRow['assignedByPersonId'] ?? $assignmentRow['assigned_by_person_id'] ?? '');
        if ($assignedBy === $personId) {
            return true;
        }
        return $this->isAssignmentRecipient($identity, $organizationId, $assignmentRow);
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
            return $activityAuthorPersonId === null || $activityAuthorPersonId === $personId;
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
        return $this->permissions->hasPermission($identity->accountId, 'activity.review');
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
}
