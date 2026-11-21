<?php

declare(strict_types=1);

namespace App\Education\Application;

use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class HomeworkAttemptLinker implements ActivityResultSink
{
    public function __construct(
        private ConnectionInterface $db,
        private HomeworkService $homework,
        private ActivityHandlerRegistry $activityHandlers,
        private ActivityResultView $activityResults,
        private EducationAccess $access,
    ) {}

    public function finished(Scope $scope, string $attemptId): void
    {
        $row = $this->db->createCommand(
            'SELECT r.assignment_id::text FROM education.activity_attempts a
            JOIN education.homework_recipients r ON r.activity_assignment_id=a.assignment_id
            WHERE a.id=CAST(:id AS uuid) AND a.organization_id=CAST(:org AS uuid) AND a.student_id=CAST(:student AS uuid)',
            [':id' => $attemptId, ':org' => $scope->organizationId, ':student' => $scope->studentId],
        )->queryOne();
        if (is_array($row)) {
            $detail = $this->homework->read('student_detail', $scope, [], (string) $row['assignment_id']);
            if ($detail['assignment']['status'] === 'PUBLISHED'
                && in_array($detail['submission']['state'], ['NOT_STARTED','IN_PROGRESS','RETURNED'], true)) {
                $this->attach($scope, (string) $row['assignment_id'], $attemptId);
            }
        }
    }

    public function attach(Scope $scope, string $assignmentId, string $attemptId): array
    {
        return $this->db->transaction(function () use ($scope, $assignmentId, $attemptId): array {
            $this->db->createCommand('SELECT id FROM education.homework_assignments WHERE id=CAST(:id AS uuid)
                AND organization_id=CAST(:org AS uuid) FOR UPDATE', [':id' => $assignmentId, ':org' => $scope->organizationId])->queryOne();
            $detail = $this->homework->read('student_detail', $scope, [], $assignmentId);
            $this->access->assertResource($scope, 'attempt', $attemptId);
            $recipient = (string) $detail['recipient']['id'];
            // The same lock serializes attach, submit and attempt-start limits.
            $this->db->createCommand(
                'SELECT id FROM education.homework_recipients WHERE id=CAST(:id AS uuid) FOR UPDATE',
                [':id' => $recipient],
            )->queryOne();
            $row = $this->db->createCommand('SELECT a.id::text,a.status,a.config,a.account_id::text,
                h.activity_config,d.code AS activity_code FROM education.activity_attempts a
                JOIN education.homework_recipients r ON r.activity_assignment_id=a.assignment_id
                JOIN education.homework_assignments h ON h.id=r.assignment_id
                JOIN education.activity_definitions d ON d.id=a.activity_id
                WHERE a.id=CAST(:attempt AS uuid) AND r.id=CAST(:recipient AS uuid)
                AND a.organization_id=CAST(:org AS uuid) AND a.student_id=CAST(:student AS uuid)
                AND a.status=\'finished\'', [':attempt' => Input::uuid($attemptId),
                ':recipient' => $recipient, ':org' => $scope->organizationId, ':student' => $scope->studentId])->queryOne();
            if (!is_array($row) || $row['account_id'] !== $scope->accountId) {
                throw new EducationError(404, 'activity_result_not_found');
            }
            $attemptConfig = is_array($row['config']) ? $row['config'] : json_decode($row['config'], true, flags: JSON_THROW_ON_ERROR);
            $assignedConfig = is_array($row['activity_config']) ? $row['activity_config'] : json_decode($row['activity_config'], true, flags: JSON_THROW_ON_ERROR);
            if ($this->activityHandlers->validateConfig($row['activity_code'], $attemptConfig)
                != $this->activityHandlers->validateConfig($row['activity_code'], $assignedConfig)
                || $this->activityResults->forAttempt($scope->organizationId, $attemptId) === null) {
                throw new EducationError(404, 'activity_result_not_found');
            }
            $used = $this->db->createCommand(
                'SELECT 1 FROM education.homework_submission_versions WHERE activity_attempt_id=CAST(:id AS uuid)',
                [':id' => $attemptId],
            )->queryScalar();
            if ($used !== false) {
                // A successful finish can be retried after submit without changing immutable evidence.
                $linked = $this->db->createCommand('SELECT 1 FROM education.homework_attempt_links WHERE attempt_id=CAST(:attempt AS uuid)
                    AND recipient_id=CAST(:recipient AS uuid)', [':attempt' => $attemptId, ':recipient' => $recipient])->queryScalar();
                if ($linked !== false) {
                    return $detail;
                }
                throw new EducationError(409, 'activity_result_used');
            }
            if ($detail['assignment']['status'] !== 'PUBLISHED' || !in_array($detail['submission']['state'], ['NOT_STARTED','IN_PROGRESS','RETURNED'], true)) {
                throw new EducationError(409, 'submission_not_editable');
            }
            if ($detail['submission']['id'] === null) {
                $detail = $this->homework->write('save_draft', $scope, ['version' => 0, 'text' => ''], $assignmentId);
            }
            $this->db->createCommand(
                'INSERT INTO education.homework_attempt_links(attempt_id,organization_id,recipient_id,answer_sequence)
                VALUES(CAST(:attempt AS uuid),CAST(:org AS uuid),CAST(:recipient AS uuid),
                    (SELECT count(*)+1 FROM education.homework_submission_versions v JOIN education.homework_submissions s ON s.id=v.submission_id
                        WHERE s.recipient_id=CAST(:recipient AS uuid))) ON CONFLICT(attempt_id) DO NOTHING',
                [':attempt' => $attemptId, ':org' => $scope->organizationId, ':recipient' => $recipient],
            )->execute();
            $this->db->createCommand('UPDATE education.homework_submissions SET selected_activity_attempt_id=CAST(:attempt AS uuid)
                WHERE recipient_id=CAST(:recipient AS uuid)', [':attempt' => $attemptId, ':recipient' => $recipient])->execute();
            return $this->homework->read('student_detail', $scope, [], $assignmentId);
        });
    }
}
