<?php

declare(strict_types=1);

namespace App\Education\Application;

use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class HomeworkActivityService
{
    public function __construct(private ConnectionInterface $db, private HomeworkService $homework,
        private EducationService $education, private HomeworkAttemptLinker $links) {}

    public function start(Scope $scope, string $id): array
    {
        return $this->db->transaction(function () use ($scope, $id): array {
            $this->db->createCommand('SELECT id FROM education.homework_assignments WHERE id=CAST(:id AS uuid)
                AND organization_id=CAST(:org AS uuid) FOR UPDATE', [':id' => Input::uuid($id), ':org' => $scope->organizationId])->queryOne();
            $detail = $this->homework->read('student_detail', $scope, [], Input::uuid($id));
            if (!in_array($detail['assignment']['type'], ['typing','activity'], true)
                || $detail['assignment']['status'] !== 'PUBLISHED'
                || !in_array($detail['submission']['state'], ['NOT_STARTED','IN_PROGRESS','RETURNED'], true)) {
                throw new EducationError(409, 'submission_not_editable');
            }
            $recipient = $this->db->createCommand('SELECT activity_assignment_id::text FROM education.homework_recipients
                WHERE id=CAST(:id AS uuid) AND organization_id=CAST(:org AS uuid) FOR UPDATE',
                [':id' => $detail['recipient']['id'], ':org' => $scope->organizationId])->queryOne();
            if (!is_array($recipient) || $recipient['activity_assignment_id'] === null) throw new EducationError(409, 'activity_unavailable');
            $activity = (string) $recipient['activity_assignment_id'];
            $count = (int) $this->db->createCommand('SELECT count(*) FROM education.activity_attempts
                WHERE assignment_id=CAST(:id AS uuid)', [':id' => $activity])->queryScalar();
            if ($count >= (int) $detail['assignment']['maxAttempts']) throw new EducationError(409, 'attempt_limit_reached');
            return $this->education->startHomeworkAttempt($scope, (string) $detail['assignment']['activityCode'], [
                ...$detail['assignment']['activityConfig'], 'assignmentId' => $activity, 'source' => 'browser',
            ]);
        });
    }

    public function attach(Scope $scope, string $id, string $attemptId): array
    {
        return $this->links->attach($scope, Input::uuid($id), Input::uuid($attemptId));
    }
}
