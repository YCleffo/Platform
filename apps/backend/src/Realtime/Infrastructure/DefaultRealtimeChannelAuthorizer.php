<?php

declare(strict_types=1);

namespace App\Realtime\Infrastructure;

use App\Core\Organization\Application\OrganizationRepository;
use App\Core\Rbac\Application\PermissionService;
use App\Realtime\Application\RealtimeChannelAuthorizerInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class DefaultRealtimeChannelAuthorizer implements RealtimeChannelAuthorizerInterface
{
    public function __construct(
        private ConnectionInterface $db,
        private OrganizationRepository $organizations,
        private PermissionService $permissions,
    ) {}

    public function canSubscribe(string $accountId, string $personId, string $organizationId, string $channel): bool
    {
        if (!$this->organizations->activeMember($personId, $organizationId)) {
            return false;
        }
        if (!$this->permissions->hasPermission($accountId, 'realtime.connect')) {
            return false;
        }

        $parts = explode(':', $channel, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$prefix, $targetId] = $parts;
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $targetId) !== 1) {
            return false;
        }
        $targetId = strtolower($targetId);

        return match ($prefix) {
            'org' => strcasecmp($targetId, $organizationId) === 0,
            'user' => strcasecmp($targetId, $personId) === 0,
            'room' => $this->isRoomMember($organizationId, $personId, $targetId),
            'assessment' => $this->canAccessAssessment($accountId, $organizationId, $personId, $targetId),
            'activity' => $this->canAccessActivity($accountId, $organizationId, $personId, $targetId),
            default => false,
        };
    }

    private function isRoomMember(string $organizationId, string $personId, string $roomId): bool
    {
        $exists = $this->db->createCommand(
            'SELECT 1 FROM chat.members m
             JOIN chat.rooms r ON r.id = m.room_id AND r.organization_id = m.organization_id
             WHERE m.room_id = CAST(:room AS uuid)
               AND m.organization_id = CAST(:org AS uuid)
               AND m.person_id = CAST(:person AS uuid)
               AND m.left_at IS NULL',
            [':room' => $roomId, ':org' => $organizationId, ':person' => $personId],
        )->queryScalar();
        return $exists !== false;
    }

    private function canAccessActivity(string $accountId, string $organizationId, string $personId, string $activityId): bool
    {
        $author = $this->db->createCommand(
            'SELECT author_person_id::text FROM activity.items
             WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)',
            [':id' => $activityId, ':org' => $organizationId],
        )->queryScalar();

        if (!is_string($author)) {
            return false;
        }
        if (strcasecmp($author, $personId) === 0) {
            return true;
        }
        if (
            $this->permissions->hasPermission($accountId, 'activity.review')
            || $this->permissions->hasPermission($accountId, 'activity.create')
        ) {
            return true;
        }

        $isParticipant = $this->db->createCommand(
            'SELECT 1 FROM activity.attempts
             WHERE activity_id = CAST(:id AS uuid)
               AND organization_id = CAST(:org AS uuid)
               AND participant_person_id = CAST(:person AS uuid)
             LIMIT 1',
            [':id' => $activityId, ':org' => $organizationId, ':person' => $personId],
        )->queryScalar();
        if ($isParticipant !== false) {
            return true;
        }

        $hasAssignment = $this->db->createCommand(
            "SELECT 1 FROM activity.assignments a
             WHERE a.activity_id = CAST(:id AS uuid)
               AND a.organization_id = CAST(:org AS uuid)
               AND a.status = 'ACTIVE'
               AND (
                   a.target_type = 'organization'
                   OR (a.target_type = 'person' AND a.target_id = CAST(:person AS uuid))
                   OR (a.target_type = 'student' AND (
                       a.target_id = CAST(:person AS uuid)
                       OR EXISTS (
                           SELECT 1 FROM education.students s
                           WHERE s.id = a.target_id AND s.organization_id = CAST(:org AS uuid) AND s.person_id = CAST(:person AS uuid)
                       )
                   ))
                   OR (a.target_type = 'group' AND EXISTS (
                       SELECT 1 FROM education.group_students gs
                       JOIN education.students s ON s.id = gs.student_id AND s.organization_id = gs.organization_id
                       WHERE gs.group_id = a.target_id AND gs.organization_id = CAST(:org AS uuid) AND s.person_id = CAST(:person AS uuid)
                   ))
                   OR (a.target_type = 'room' AND EXISTS (
                       SELECT 1 FROM chat.members cm
                       WHERE cm.room_id = a.target_id AND cm.organization_id = CAST(:org AS uuid)
                         AND cm.person_id = CAST(:person AS uuid) AND cm.left_at IS NULL
                   ))
               )
             LIMIT 1",
            [':id' => $activityId, ':org' => $organizationId, ':person' => $personId],
        )->queryScalar();

        return $hasAssignment !== false;
    }

    private function canAccessAssessment(string $accountId, string $organizationId, string $personId, string $assessmentId): bool
    {
        if ($this->canAccessActivity($accountId, $organizationId, $personId, $assessmentId)) {
            return true;
        }

        $author = $this->db->createCommand(
            'SELECT author_person_id::text FROM education.assessments
             WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)',
            [':id' => $assessmentId, ':org' => $organizationId],
        )->queryScalar();

        if (!is_string($author)) {
            return false;
        }
        if (strcasecmp($author, $personId) === 0) {
            return true;
        }
        if (
            $this->permissions->hasPermission($accountId, 'activity.review')
            || $this->permissions->hasPermission($accountId, 'activity.create')
            || $this->permissions->hasPermission($accountId, 'education.activities.manage')
        ) {
            return true;
        }

        $isParticipant = $this->db->createCommand(
            'SELECT 1 FROM education.assessment_attempts aa
             JOIN education.assessment_versions av ON av.id = aa.version_id
             JOIN education.students s ON s.id = aa.student_id AND s.organization_id = aa.organization_id
             WHERE av.assessment_id = CAST(:id AS uuid)
               AND aa.organization_id = CAST(:org AS uuid)
               AND s.person_id = CAST(:person AS uuid)
             LIMIT 1',
            [':id' => $assessmentId, ':org' => $organizationId, ':person' => $personId],
        )->queryScalar();

        return $isParticipant !== false;
    }
}
