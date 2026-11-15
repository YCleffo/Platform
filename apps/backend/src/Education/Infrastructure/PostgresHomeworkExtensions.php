<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use App\Education\Application\{EducationAccess, EducationError, EducationStore, HomeworkExtensions, Scope, ActivityHandlerRegistry, ActivityResultView, AchievementService};
use Yiisoft\Db\Connection\ConnectionInterface;

/** Bridges Homework to the existing Activities, file store and notification center. */
final readonly class PostgresHomeworkExtensions implements HomeworkExtensions
{
    public function __construct(private ConnectionInterface $db, private EducationStore $education, private EducationAccess $access,
        private ActivityHandlerRegistry $activityHandlers, private ActivityResultView $activityResults,
        private AchievementService $achievements, private ActivityAwardNotifications $awards) {}

    public function validateSubmission(Scope $scope, array $assignment, array $submission): ?string
    {
        $recipient = (string) $submission['recipientId'];
        if (in_array($assignment['type'], ['typing', 'activity'], true)) {
            $order = match ($assignment['attemptPolicy'] ?? 'latest') {
                'first' => 'a.finished_at,a.id',
                'best' => "COALESCE((verified.metrics->>'score')::numeric,t.wpm) DESC NULLS LAST,COALESCE((verified.metrics->>'accuracy')::numeric,t.accuracy) DESC NULLS LAST,a.finished_at DESC,a.id",
                default => 'a.finished_at DESC,a.id DESC',
            };
            $row = $this->db->createCommand('SELECT a.id::text,a.config,d.code FROM education.homework_attempt_links l
                JOIN education.homework_recipients r ON r.id=l.recipient_id
                JOIN education.activity_attempts a ON a.id=l.attempt_id
                LEFT JOIN education.typing_results t ON t.attempt_id=a.id
                LEFT JOIN education.activity_results verified ON verified.attempt_id=a.id
                JOIN education.activity_definitions d ON d.id=a.activity_id
                WHERE r.id=CAST(:recipient AS uuid) AND r.organization_id=CAST(:org AS uuid)
                AND r.student_id=CAST(:student AS uuid) AND a.assignment_id=r.activity_assignment_id
                AND a.status=\'finished\'
                AND COALESCE(verified.passed,t.keystrokes>0,false)
                AND l.answer_sequence=(SELECT count(*)+1 FROM education.homework_submission_versions vv
                    JOIN education.homework_submissions ss ON ss.id=vv.submission_id WHERE ss.recipient_id=r.id)
                AND NOT EXISTS(SELECT 1 FROM education.homework_submission_versions v WHERE v.activity_attempt_id=a.id)
                ORDER BY ' . $order . ' LIMIT 1', [':recipient' => $recipient, ':org' => $scope->organizationId,
                ':student' => $scope->studentId])->queryOne();
            if (!is_array($row)) throw new EducationError(422, 'activity_result_required');
            $this->access->assertResource($scope, 'attempt', (string) $row['id']);
            $config = is_array($row['config']) ? $row['config'] : json_decode($row['config'], true, flags: JSON_THROW_ON_ERROR);
            $proof = $this->activityResults->forAttempt($scope->organizationId, $row['id']);
            if ($proof === null || $this->activityHandlers->validateConfig($row['code'], $config)
                != $this->activityHandlers->validateConfig($row['code'], $assignment['activityConfig'])) {
                throw new EducationError(422, 'activity_result_required');
            }
            if (($proof['passed'] ?? true) === false) throw new EducationError(422, 'activity_target_not_reached');
            return (string) $row['id'];
        }
        $count = (int) $this->db->createCommand('SELECT count(*) FROM education.homework_draft_files
            WHERE recipient_id=CAST(:id AS uuid)', [':id' => $recipient])->queryScalar();
        if ($assignment['type'] === 'files' && $count === 0) throw new EducationError(422, 'attachment_required');
        if ($assignment['type'] === 'text' && trim((string) ($submission['text'] ?? '')) === '' && $count === 0) {
            throw new EducationError(422, 'answer_required');
        }
        return null;
    }

    public function assignmentView(Scope $scope, array $assignment): array
    {
        $assignment['studentIds'] = $this->db->createCommand('SELECT student_id::text FROM education.homework_recipients
            WHERE assignment_id=CAST(:id AS uuid) AND organization_id=CAST(:org AS uuid) ORDER BY assigned_at,id',
            [':id' => $assignment['id'], ':org' => $scope->organizationId])->queryColumn();
        $assignment['files'] = $this->files('f.assignment_id=CAST(:id AS uuid) AND f.purpose=\'material\'',
            [':id' => $assignment['id'], ':org' => $scope->organizationId]);
        return $assignment;
    }

    public function submissionView(Scope $scope, array $assignment, array $submission): array
    {
        foreach ($submission['reviews'] ?? [] as $index => $review) {
            $person = $this->db->createCommand('SELECT person_id::text FROM core.accounts WHERE id=CAST(:id AS uuid)',
                [':id' => $review['teacherAccountId']])->queryScalar();
            $submission['reviews'][$index]['teacherName'] = is_string($person) ? $this->access->personName($person) : null;
        }
        $submission['files'] = $this->files('EXISTS(SELECT 1 FROM education.homework_draft_files d
            WHERE d.file_id=f.id AND d.recipient_id=CAST(:id AS uuid))', [':id' => $submission['recipientId'], ':org' => $scope->organizationId]);
        foreach ($submission['versions'] ?? [] as $index => $version) {
            $version['files'] = $this->files('EXISTS(SELECT 1 FROM education.homework_version_files v
                WHERE v.file_id=f.id AND v.version_id=CAST(:id AS uuid))', [':id' => $version['id'], ':org' => $scope->organizationId]);
            $version['activityResult'] = $this->result($version['activityAttemptId'] ?? null, $scope);
            $submission['versions'][$index] = $version;
            if (($submission['currentVersion']['id'] ?? null) === $version['id']) $submission['currentVersion'] = $version;
        }
        $selected = $this->db->createCommand('SELECT selected_activity_attempt_id::text FROM education.homework_submissions
            WHERE recipient_id=CAST(:id AS uuid) AND organization_id=CAST(:org AS uuid)',
            [':id' => $submission['recipientId'], ':org' => $scope->organizationId])->queryScalar();
        $submission['selectedActivityAttemptId'] = is_string($selected) ? $selected : null;
        $submission['activityResult'] = $this->result(is_string($selected) ? $selected : null, $scope);
        return $submission;
    }

    public function changed(Scope $scope, string $event, array $assignment, ?array $submission = null): void
    {
        $id = (string) $assignment['id'];
        (new ParentNotifications($this->db))->homework($scope, $event, $assignment, $submission);
        if ($submission !== null && in_array($event, ['reviewed', 'returned'], true)) {
            $this->achievements->recordHomework($scope, (string) $submission['id']);
            $this->awards->notify($scope, (string) $submission['studentId']);
        }
        if (in_array($event, ['published', 'recipient_added'], true)) {
            $recipients = $this->db->createCommand('SELECT r.id::text,r.student_id::text,r.person_id::text,
                s.owner_account_id::text FROM education.homework_recipients r JOIN education.students s ON s.id=r.student_id
                WHERE r.assignment_id=CAST(:id AS uuid) AND r.organization_id=CAST(:org AS uuid) FOR UPDATE OF r',
                [':id' => $id, ':org' => $scope->organizationId])->queryAll();
            foreach ($recipients as $recipient) {
                if (in_array($assignment['type'], ['typing', 'activity'], true)) $this->activityAssignment($scope, $assignment, $recipient);
                $this->notify($scope, (string) $recipient['person_id'], 'published', $assignment,
                    '/student/homework/' . $id, 'published:' . $id . ':' . $recipient['id']);
            }
        }
        if ($event === 'deadline_changed') {
            $this->db->createCommand('UPDATE education.activity_assignments a SET due_at=CAST(:due AS timestamptz)
                FROM education.homework_recipients r WHERE r.activity_assignment_id=a.id AND r.assignment_id=CAST(:id AS uuid)
                AND r.organization_id=CAST(:org AS uuid)', [':due' => $assignment['dueAt'], ':id' => $id, ':org' => $scope->organizationId])->execute();
            foreach ($this->db->createCommand('SELECT person_id::text FROM education.homework_recipients WHERE assignment_id=CAST(:id AS uuid)
                AND organization_id=CAST(:org AS uuid)', [':id' => $id, ':org' => $scope->organizationId])->queryAll() as $r) {
                $this->notify($scope, (string) $r['person_id'], $event, $assignment, '/student/homework/' . $id, $event . ':' . $id . ':' . $assignment['version']);
            }
        }
        if ($submission === null) return;
        if ($event === 'submitted') {
            $version = $submission['currentVersion'];
            $this->db->createCommand('INSERT INTO education.homework_version_files(version_id,file_id)
                SELECT CAST(:version AS uuid),file_id FROM education.homework_draft_files WHERE recipient_id=CAST(:recipient AS uuid)
                ON CONFLICT DO NOTHING', [':version' => $version['id'], ':recipient' => $submission['recipientId']])->execute();
            $this->notify($scope, (string) $assignment['authorPersonId'], $event, $assignment,
                '/homework/' . $id, 'submitted:' . $version['id']);
        }
        if (in_array($event, ['returned', 'reviewed'], true)) {
            if ($event === 'returned') $this->db->createCommand('UPDATE education.homework_submissions SET selected_activity_attempt_id=NULL
                WHERE id=CAST(:id AS uuid)', [':id' => $submission['id']])->execute();
            $person = $this->db->createCommand('SELECT person_id::text FROM education.homework_recipients WHERE id=CAST(:id AS uuid)',
                [':id' => $submission['recipientId']])->queryScalar();
            $this->notify($scope, (string) $person, $event, $assignment, '/student/homework/' . $id,
                $event . ':' . $submission['id'] . ':' . $submission['version']);
        }
    }

    private function activityAssignment(Scope $scope, array $assignment, array $recipient): void
    {
        $existing = $this->db->createCommand('SELECT activity_assignment_id FROM education.homework_recipients WHERE id=CAST(:id AS uuid)',
            [':id' => $recipient['id']])->queryScalar();
        if ($existing !== null && $existing !== false) return;
        $definition = $this->education->activityDefinition((string) $assignment['activityCode']);
        if ($definition === null || !in_array($definition['enabled'], [true, 't', 1], true)) throw new EducationError(422, 'activity_unavailable');
        $this->activityHandlers->validateConfig((string) $assignment['activityCode'], $assignment['activityConfig']);
        $owner = new Scope($scope->organizationId, (string) $recipient['owner_account_id'], $scope->personId,
            $scope->locale, $scope->timezone, actorAccountId: $scope->actor());
        $activity = $this->education->createAssignment($owner, (string) $definition['id'], null,
            (string) $recipient['student_id'], $assignment['activityConfig']);
        $this->db->createCommand('UPDATE education.homework_recipients SET activity_assignment_id=CAST(:activity AS uuid) WHERE id=CAST(:id AS uuid)',
            [':id' => $recipient['id'], ':activity' => $activity['id']])->execute();
        $this->db->createCommand('UPDATE education.activity_assignments SET due_at=CAST(:due AS timestamptz) WHERE id=CAST(:id AS uuid)',
            [':id' => $activity['id'], ':due' => $assignment['dueAt']])->execute();
    }

    private function files(string $where, array $params): array
    {
        return $this->db->createCommand('SELECT f.id::text,f.name,f.mime,f.size_bytes AS "sizeBytes",f.created_at::text AS "createdAt"
            FROM education.homework_files f WHERE f.organization_id=CAST(:org AS uuid) AND ' . $where . ' ORDER BY f.created_at,f.id', $params)->queryAll();
    }

    private function result(?string $attempt, Scope $scope): ?array
    {
        return $attempt === null ? null : $this->activityResults->forAttempt($scope->organizationId, $attempt);
    }

    private function notify(Scope $scope, string $person, string $event, array $assignment, string $path, string $key): void
    {
        $this->db->createCommand('INSERT INTO education.notifications(organization_id,person_id,kind,resource_id,resource_path,subject,body,event_key)
            VALUES(CAST(:org AS uuid),CAST(:person AS uuid),:kind,CAST(:id AS uuid),:path,:subject,:body,:key)
            ON CONFLICT(organization_id,person_id,event_key) WHERE event_key IS NOT NULL DO NOTHING',
            [':org' => $scope->organizationId, ':person' => $person, ':kind' => 'homework_' . $event,
                ':id' => $assignment['id'], ':path' => $path, ':subject' => mb_substr((string) $assignment['title'], 0, 160),
                ':body' => $event, ':key' => $key])->execute();
    }
}
