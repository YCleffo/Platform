<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use App\Activity\Application\ActivityResultListenerInterface;
use App\Education\Application\HomeworkAttemptLinker;
use App\Education\Application\Scope;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Education domain adapter that projects Universal Activity Engine evaluation & publication
 * into the Education electronic journal (education.activity_journal_sync & education.lesson_results).
 * Strictly separates activity points, completion percentage, and educational grade.
 * Idempotent on repeated event delivery.
 */
final readonly class EducationActivityJournalAdapter implements ActivityResultListenerInterface
{
    public function __construct(
        private ConnectionInterface $db,
        private ?HomeworkAttemptLinker $homeworkLinker = null,
        private ?ParentNotifications $parentNotifications = null,
    ) {}

    public function onAttemptEvaluated(string $organizationId, string $attemptId, array $attemptData): void
    {
        $rawParticipant = $attemptData['participantPersonId'] ?? null;
        $participantPersonId = is_string($rawParticipant) ? $rawParticipant : '';
        if ($participantPersonId === '') {
            return;
        }

        $student = $this->db->createCommand(
            'SELECT s.id::text AS id, s.owner_account_id::text AS "ownerAccountId",
                    (SELECT sa.account_id::text FROM education.student_accounts sa WHERE sa.student_id = s.id AND sa.organization_id = s.organization_id AND sa.revoked_at IS NULL LIMIT 1) AS "studentAccountId"
             FROM education.students s
             WHERE s.organization_id = CAST(:org AS uuid) AND s.person_id = CAST(:person AS uuid)
             LIMIT 1',
            [':org' => $organizationId, ':person' => $participantPersonId],
        )->queryOne();

        if (!is_array($student)) {
            return;
        }

        $studentId = (string) $student['id'];
        $state = (string) ($attemptData['state'] ?? 'EVALUATED');
        $rawScore = is_numeric($attemptData['rawScore'] ?? null) ? round((float) $attemptData['rawScore'], 2) : null;
        $maxScore = is_numeric($attemptData['maxScore'] ?? null) ? round((float) $attemptData['maxScore'], 2) : null;
        $percentage = is_numeric($attemptData['percentage'] ?? null) ? round((float) $attemptData['percentage'], 2) : null;
        $passed = (bool) ($attemptData['passed'] ?? false);
        $activityId = is_string($attemptData['activityId'] ?? null) ? $attemptData['activityId'] : '';
        $rawAssignment = $attemptData['assignmentId'] ?? null;
        $assignmentId = is_string($rawAssignment) && $rawAssignment !== '' ? $rawAssignment : null;
        $revision = is_numeric($attemptData['revision'] ?? null) ? (int) $attemptData['revision'] : 1;

        $educationalGrade = self::mapEducationalGrade($state, $percentage, $passed);
        $deliveryKey = $attemptId . ':' . $revision;

        $lessonId = $this->resolveLessonId($organizationId, $assignmentId);
        $lessonResultId = null;

        if ($lessonId !== null && $educationalGrade !== 'pending') {
            $lessonResultId = $this->syncToLessonResult(
                $organizationId,
                $lessonId,
                $studentId,
                $educationalGrade,
                (string) ($attemptData['activityTitle'] ?? 'Activity'),
                $rawScore,
                $maxScore,
                $percentage,
            );
        }

        if ($activityId !== '') {
            $this->db->createCommand(
                'INSERT INTO education.activity_journal_sync (
                    organization_id, attempt_id, activity_id, student_id, lesson_id, lesson_result_id,
                    activity_raw_score, activity_max_score, completion_percentage, educational_grade, delivery_key, synced_at, updated_at
                 ) VALUES (
                    CAST(:org AS uuid), CAST(:att AS uuid), CAST(:act AS uuid), CAST(:stu AS uuid),
                    CAST(:les AS uuid), CAST(:lres AS uuid),
                    :raw, :max, :pct, :grade, :dkey, now(), now()
                 )
                 ON CONFLICT (organization_id, attempt_id) DO UPDATE SET
                    lesson_id = COALESCE(EXCLUDED.lesson_id, education.activity_journal_sync.lesson_id),
                    lesson_result_id = COALESCE(EXCLUDED.lesson_result_id, education.activity_journal_sync.lesson_result_id),
                    activity_raw_score = EXCLUDED.activity_raw_score,
                    activity_max_score = EXCLUDED.activity_max_score,
                    completion_percentage = EXCLUDED.completion_percentage,
                    educational_grade = EXCLUDED.educational_grade,
                    delivery_key = EXCLUDED.delivery_key,
                    updated_at = now()',
                [
                    ':org' => $organizationId,
                    ':att' => $attemptId,
                    ':act' => $activityId,
                    ':stu' => $studentId,
                    ':les' => $lessonId,
                    ':lres' => $lessonResultId,
                    ':raw' => $rawScore,
                    ':max' => $maxScore,
                    ':pct' => $percentage,
                    ':grade' => $educationalGrade,
                    ':dkey' => $deliveryKey,
                ],
            )->execute();
        }

        $rawStuAcc = $student['studentAccountId'] ?? null;
        $studentAccountId = is_string($rawStuAcc) ? $rawStuAcc : '';
        if ($this->homeworkLinker !== null && $assignmentId !== null && $studentAccountId !== '') {
            try {
                $scope = new Scope($organizationId, $studentAccountId, $participantPersonId, 'ru-RU', 'UTC', $studentId);
                $this->homeworkLinker->finished($scope, $attemptId);
            } catch (\Throwable) {
                // Homework linking is best-effort for standalone assessments
            }
        }
    }

    public function onAttemptResultsPublished(
        string $organizationId,
        string $attemptId,
        array $attemptData,
        bool $published,
    ): void {
        $this->onAttemptEvaluated($organizationId, $attemptId, $attemptData);

        if (!$published || $this->parentNotifications === null) {
            return;
        }
        $rawParticipant = $attemptData['participantPersonId'] ?? null;
        $participantPersonId = is_string($rawParticipant) ? $rawParticipant : '';
        if ($participantPersonId === '') {
            return;
        }
        $student = $this->db->createCommand(
            'SELECT s.id::text, s.owner_account_id::text AS "ownerAccountId"
             FROM education.students s
             WHERE s.organization_id = CAST(:org AS uuid) AND s.person_id = CAST(:person AS uuid)
             LIMIT 1',
            [':org' => $organizationId, ':person' => $participantPersonId],
        )->queryOne();
        if (!is_array($student)) {
            return;
        }
        try {
            $scope = new Scope(
                $organizationId,
                (string) $student['ownerAccountId'],
                $participantPersonId,
                'ru-RU',
                'UTC',
                (string) $student['id'],
            );
            $this->parentNotifications->activity($scope, (string) $student['id'], $attemptId);
        } catch (\Throwable) {
            // Non-blocking parent notification
        }
    }

    public static function mapEducationalGrade(string $state, ?float $percentage, bool $passed): string
    {
        if (!in_array($state, ['EVALUATED', 'COMPLETED'], true) || $percentage === null) {
            return 'pending';
        }
        if ($passed && $percentage >= 80.0) {
            return 'mastered';
        }
        if ($passed || $percentage >= 50.0) {
            return 'developing';
        }
        return 'needs_support';
    }

    private function resolveLessonId(string $organizationId, ?string $assignmentId): ?string
    {
        if ($assignmentId === null) {
            return null;
        }
        $row = $this->db->createCommand(
            "SELECT target_type AS \"targetType\", target_id::text AS \"targetId\"
             FROM activity.assignments
             WHERE id = CAST(:id AS uuid) AND organization_id = CAST(:org AS uuid)",
            [':id' => $assignmentId, ':org' => $organizationId],
        )->queryOne();

        if (is_array($row) && (string) ($row['targetType'] ?? '') === 'lesson') {
            return (string) $row['targetId'];
        }

        $hwLesson = $this->db->createCommand(
            'SELECT ha.lesson_id::text
             FROM education.homework_recipients hr
             JOIN education.homework_assignments ha ON ha.id = hr.assignment_id AND ha.organization_id = hr.organization_id
             WHERE hr.organization_id = CAST(:org AS uuid)
               AND (hr.activity_assignment_id = CAST(:aid AS uuid) OR hr.assignment_id = CAST(:aid AS uuid))
               AND ha.lesson_id IS NOT NULL
             LIMIT 1',
            [':org' => $organizationId, ':aid' => $assignmentId],
        )->queryScalar();

        return is_string($hwLesson) && $hwLesson !== '' ? $hwLesson : null;
    }

    private function syncToLessonResult(
        string $organizationId,
        string $lessonId,
        string $studentId,
        string $status,
        string $title,
        ?float $rawScore,
        ?float $maxScore,
        ?float $percentage,
    ): ?string {
        return $this->db->transaction(function () use ($organizationId, $lessonId, $studentId, $status, $title, $rawScore, $maxScore, $percentage): ?string {
            $lesson = $this->db->createCommand(
                "SELECT l.id::text, l.owner_account_id::text AS \"ownerAccountId\", l.status
                 FROM education.lessons l
                 WHERE l.id = CAST(:lid AS uuid) AND l.organization_id = CAST(:org AS uuid) AND l.status = 'completed'
                 FOR UPDATE OF l",
                [':lid' => $lessonId, ':org' => $organizationId],
            )->queryOne();
            if (!is_array($lesson)) {
                return null;
            }

            $ownerAccountId = (string) $lesson['ownerAccountId'];
            $studentExists = $this->db->createCommand(
                'SELECT 1 FROM education.students WHERE id = CAST(:sid AS uuid) AND organization_id = CAST(:org AS uuid) AND owner_account_id = CAST(:owner AS uuid)',
                [':sid' => $studentId, ':org' => $organizationId, ':owner' => $ownerAccountId],
            )->queryScalar();
            if ($studentExists === false) {
                return null;
            }

            $scorePart = ($rawScore !== null && $maxScore !== null && $percentage !== null)
                ? "{$rawScore}/{$maxScore} ({$percentage}%)"
                : ($percentage !== null ? "{$percentage}%" : '');
            $comment = mb_substr(trim("{$title}: {$scorePart}"), 0, 1000);

            $existing = $this->db->createCommand(
                'SELECT id::text, status, comment FROM education.lesson_results
                 WHERE lesson_id = CAST(:lid AS uuid) AND student_id = CAST(:sid AS uuid)
                 FOR UPDATE',
                [':lid' => $lessonId, ':sid' => $studentId],
            )->queryOne();

            if (is_array($existing) && (string) $existing['status'] === $status && (string) ($existing['comment'] ?? '') === $comment) {
                // Idempotent replay: nothing changed, do not append duplicate revision
                return (string) $existing['id'];
            }

            $resultId = (string) $this->db->createCommand(
                'INSERT INTO education.lesson_results (organization_id, owner_account_id, lesson_id, student_id, status, comment)
                 VALUES (CAST(:org AS uuid), CAST(:owner AS uuid), CAST(:lid AS uuid), CAST(:sid AS uuid), :status, :comment)
                 ON CONFLICT (lesson_id, student_id) DO UPDATE SET status = EXCLUDED.status, comment = EXCLUDED.comment, updated_at = now()
                 RETURNING id::text',
                [
                    ':org' => $organizationId,
                    ':owner' => $ownerAccountId,
                    ':lid' => $lessonId,
                    ':sid' => $studentId,
                    ':status' => $status,
                    ':comment' => $comment,
                ],
            )->queryScalar();

            $this->db->createCommand(
                'INSERT INTO education.result_revisions (organization_id, owner_account_id, result_id, status, comment, revised_by_account_id)
                 VALUES (CAST(:org AS uuid), CAST(:owner AS uuid), CAST(:rid AS uuid), :status, :comment, CAST(:owner AS uuid))',
                [
                    ':org' => $organizationId,
                    ':owner' => $ownerAccountId,
                    ':rid' => $resultId,
                    ':status' => $status,
                    ':comment' => $comment,
                ],
            )->execute();

            return $resultId;
        });
    }
}
