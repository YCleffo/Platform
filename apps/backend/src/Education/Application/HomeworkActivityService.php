<?php

declare(strict_types=1);

namespace App\Education\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class HomeworkActivityService
{
    public function __construct(
        private ConnectionInterface $db,
        private HomeworkService $homework,
        private EducationService $education,
        private HomeworkAttemptLinker $links,
    ) {}

    public function start(Scope $scope, string $id, ?AuthenticatedIdentity $identity = null): array
    {
        return $this->db->transaction(function () use ($scope, $id, $identity): array {
            $cleanId = Input::uuid($id);
            $this->db->createCommand(
                'SELECT id FROM education.homework_assignments WHERE id=CAST(:id AS uuid)
                 AND organization_id=CAST(:org AS uuid) FOR UPDATE',
                [':id' => $cleanId, ':org' => $scope->organizationId],
            )->queryOne();

            $detail = $this->homework->read('student_detail', $scope, [], $cleanId);
            if (
                !in_array($detail['assignment']['type'], ['typing', 'activity'], true)
                || $detail['assignment']['status'] !== 'PUBLISHED'
                || !in_array($detail['submission']['state'], ['NOT_STARTED', 'IN_PROGRESS', 'RETURNED'], true)
            ) {
                throw new EducationError(409, 'submission_not_editable');
            }

            $recipient = $this->db->createCommand(
                'SELECT activity_assignment_id::text FROM education.homework_recipients
                 WHERE id=CAST(:id AS uuid) AND organization_id=CAST(:org AS uuid) FOR UPDATE',
                [':id' => $detail['recipient']['id'], ':org' => $scope->organizationId],
            )->queryOne();
            if (!is_array($recipient) || $recipient['activity_assignment_id'] === null) {
                throw new EducationError(409, 'activity_unavailable');
            }

            $activityAssignmentId = (string) $recipient['activity_assignment_id'];
            $count = (int) $this->db->createCommand(
                "SELECT count(*) FROM activity.attempts
                 WHERE assignment_id = CAST(:id AS uuid)
                   AND state NOT IN ('CANCELLED', 'INVALIDATED')",
                [':id' => $activityAssignmentId],
            )->queryScalar();

            if ($count >= (int) $detail['assignment']['maxAttempts']) {
                throw new EducationError(409, 'attempt_limit_reached');
            }

            return $this->education->startHomeworkAttempt($scope, (string) $detail['assignment']['activityCode'], [
                ...$detail['assignment']['activityConfig'],
                'assignmentId' => $activityAssignmentId,
                'source' => 'browser',
            ], $identity);
        });
    }

    public function attach(Scope $scope, string $id, string $attemptId): array
    {
        return $this->links->attach($scope, Input::uuid($id), Input::uuid($attemptId));
    }
}
