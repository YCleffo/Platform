<?php

declare(strict_types=1);

namespace App\Education\Application;

use App\Activity\Application\ActivityPluginRegistry;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class HomeworkAttemptLinker implements ActivityResultSink
{
    public function __construct(
        private ConnectionInterface $db,
        private HomeworkService $homework,
        private ActivityPluginRegistry $activityPlugins,
        private ActivityResultView $activityResults,
        private EducationAccess $access,
    ) {}

    public function finished(Scope $scope, string $attemptId): void
    {
        $row = $this->db->createCommand(
            'SELECT r.assignment_id::text
             FROM activity.attempts a
             JOIN education.homework_recipients r ON r.activity_assignment_id = a.assignment_id
             WHERE a.id = CAST(:id AS uuid)
               AND a.organization_id = CAST(:org AS uuid)
               AND a.participant_person_id = CAST(:person AS uuid)',
            [':id' => $attemptId, ':org' => $scope->organizationId, ':person' => $scope->personId],
        )->queryOne();

        if (is_array($row)) {
            $detail = $this->homework->read('student_detail', $scope, [], (string) $row['assignment_id']);
            if (
                $detail['assignment']['status'] === 'PUBLISHED'
                && in_array($detail['submission']['state'], ['NOT_STARTED', 'IN_PROGRESS', 'RETURNED'], true)
            ) {
                $this->attach($scope, (string) $row['assignment_id'], $attemptId);
            }
        }
    }

    public function attach(Scope $scope, string $assignmentId, string $attemptId): array
    {
        return $this->db->transaction(function () use ($scope, $assignmentId, $attemptId): array {
            $this->db->createCommand(
                'SELECT id FROM education.homework_assignments WHERE id=CAST(:id AS uuid)
                 AND organization_id=CAST(:org AS uuid) FOR UPDATE',
                [':id' => $assignmentId, ':org' => $scope->organizationId],
            )->queryOne();
            $detail = $this->homework->read('student_detail', $scope, [], $assignmentId);
            $this->access->assertResource($scope, 'attempt', $attemptId);
            $recipient = (string) $detail['recipient']['id'];

            // The same lock serializes attach, submit and attempt-start limits.
            $this->db->createCommand(
                'SELECT id FROM education.homework_recipients WHERE id=CAST(:id AS uuid) FOR UPDATE',
                [':id' => $recipient],
            )->queryOne();

            $cleanAttemptId = Input::uuid($attemptId);
            $row = $this->db->createCommand(
                "SELECT a.id::text, a.activity_id::text AS \"activityId\", a.state,
                        a.runtime_config, h.activity_config, i.plugin_code AS activity_code
                 FROM activity.attempts a
                 JOIN activity.items i ON i.id = a.activity_id
                 JOIN education.homework_recipients r ON r.activity_assignment_id = a.assignment_id
                 JOIN education.homework_assignments h ON h.id = r.assignment_id
                 WHERE a.id = CAST(:attempt AS uuid)
                   AND r.id = CAST(:recipient AS uuid)
                   AND a.organization_id = CAST(:org AS uuid)
                   AND a.participant_person_id = CAST(:person AS uuid)
                   AND a.state IN ('EVALUATED', 'COMPLETED', 'EXPIRED')",
                [
                    ':attempt' => $cleanAttemptId,
                    ':recipient' => $recipient,
                    ':org' => $scope->organizationId,
                    ':person' => $scope->personId,
                ],
            )->queryOne();

            if (!is_array($row)) {
                throw new EducationError(404, 'activity_result_not_found');
            }

            $attemptConfig = Input::json($row['runtime_config']);
            $assignedConfig = Input::json($row['activity_config']);
            if (
                !ActivityCode::matchesHomeworkConfig(
                    $this->activityPlugins,
                    (string) $row['activity_code'],
                    (string) $row['activityId'],
                    $attemptConfig,
                    $assignedConfig,
                )
                || $this->activityResults->forAttempt($scope->organizationId, $cleanAttemptId) === null
            ) {
                throw new EducationError(404, 'activity_result_not_found');
            }

            $used = $this->db->createCommand(
                'SELECT 1 FROM education.homework_submission_versions WHERE activity_attempt_id=CAST(:id AS uuid)',
                [':id' => $cleanAttemptId],
            )->queryScalar();
            if ($used !== false) {
                $linked = $this->db->createCommand(
                    'SELECT 1 FROM education.homework_attempt_links WHERE attempt_id=CAST(:attempt AS uuid)
                     AND recipient_id=CAST(:recipient AS uuid)',
                    [':attempt' => $cleanAttemptId, ':recipient' => $recipient],
                )->queryScalar();
                if ($linked !== false) {
                    return $detail;
                }
                throw new EducationError(409, 'activity_result_used');
            }

            if ($detail['assignment']['status'] !== 'PUBLISHED' || !in_array($detail['submission']['state'], ['NOT_STARTED', 'IN_PROGRESS', 'RETURNED'], true)) {
                throw new EducationError(409, 'submission_not_editable');
            }
            if ($detail['submission']['id'] === null) {
                $detail = $this->homework->write('save_draft', $scope, ['version' => 0, 'text' => ''], $assignmentId);
            }

            $this->db->createCommand(
                'INSERT INTO education.homework_attempt_links(attempt_id,organization_id,recipient_id,answer_sequence)
                 VALUES(CAST(:attempt AS uuid),CAST(:org AS uuid),CAST(:recipient AS uuid),
                     (SELECT count(*)+1 FROM education.homework_submission_versions v JOIN education.homework_submissions s ON s.id=v.submission_id
                      WHERE s.recipient_id=CAST(:recipient AS uuid)))
                 ON CONFLICT(attempt_id) DO NOTHING',
                [':attempt' => $cleanAttemptId, ':org' => $scope->organizationId, ':recipient' => $recipient],
            )->execute();

            $this->db->createCommand(
                'UPDATE education.homework_submissions SET selected_activity_attempt_id=CAST(:attempt AS uuid)
                 WHERE recipient_id=CAST(:recipient AS uuid)',
                [':attempt' => $cleanAttemptId, ':recipient' => $recipient],
            )->execute();

            return $this->homework->read('student_detail', $scope, [], $assignmentId);
        });
    }
}
