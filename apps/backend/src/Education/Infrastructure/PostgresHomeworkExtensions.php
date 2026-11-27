<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use App\Activity\Application\ActivityPluginRegistry;
use App\Education\Application\{AchievementService, ActivityCode, ActivityResultView, EducationAccess, EducationError, EducationStore, HomeworkExtensions, Input, Scope};
use Yiisoft\Db\Connection\ConnectionInterface;

/** Bridges Homework to canonical Activity Engine, file store and notification center. */
final readonly class PostgresHomeworkExtensions implements HomeworkExtensions
{
    public function __construct(
        private ConnectionInterface $db,
        private EducationStore $education,
        private EducationAccess $access,
        private ActivityPluginRegistry $activityPlugins,
        private ActivityResultView $activityResults,
        private AchievementService $achievements,
        private ActivityAwardNotifications $awards,
        private ParentNotifications $parents,
    ) {}

    public function validateSubmission(Scope $scope, array $assignment, array $submission): ?string
    {
        $recipient = (string) $submission['recipientId'];
        if (in_array($assignment['type'], ['typing', 'activity'], true)) {
            $order = match ($assignment['attemptPolicy'] ?? 'latest') {
                'first' => 'COALESCE(a.evaluated_at, a.submitted_at, a.created_at), a.id',
                'best' => 'a.raw_score DESC NULLS LAST, a.percentage DESC NULLS LAST, COALESCE(a.evaluated_at, a.submitted_at, a.created_at) DESC, a.id',
                default => 'COALESCE(a.evaluated_at, a.submitted_at, a.created_at) DESC, a.id DESC',
            };
            $row = $this->db->createCommand(
                "SELECT a.id::text, a.activity_id::text AS \"activityId\", a.runtime_config, i.plugin_code AS code
                 FROM education.homework_attempt_links l
                 JOIN education.homework_recipients r ON r.id = l.recipient_id
                 JOIN activity.attempts a ON a.id = l.attempt_id
                 JOIN activity.items i ON i.id = a.activity_id
                 WHERE r.id = CAST(:recipient AS uuid)
                   AND r.organization_id = CAST(:org AS uuid)
                   AND r.person_id = a.participant_person_id
                   AND r.student_id = CAST(:student AS uuid)
                   AND a.assignment_id = r.activity_assignment_id
                   AND a.state IN ('EVALUATED', 'COMPLETED', 'EXPIRED')
                   AND COALESCE(a.passed, false) = true
                   AND l.answer_sequence = (
                       SELECT count(*) + 1
                       FROM education.homework_submission_versions vv
                       JOIN education.homework_submissions ss ON ss.id = vv.submission_id
                       WHERE ss.recipient_id = r.id
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM education.homework_submission_versions v WHERE v.activity_attempt_id = a.id
                   )
                 ORDER BY {$order}
                 LIMIT 1",
                [':recipient' => $recipient, ':org' => $scope->organizationId, ':student' => $scope->studentId],
            )->queryOne();

            if (!is_array($row)) {
                throw new EducationError(422, 'activity_result_required');
            }
            $this->access->assertResource($scope, 'attempt', (string) $row['id']);
            $attemptConfig = Input::json($row['runtime_config']);
            $proof = $this->activityResults->forAttempt($scope->organizationId, (string) $row['id']);
            if (
                $proof === null
                || !ActivityCode::matchesHomeworkConfig(
                    $this->activityPlugins,
                    (string) $row['code'],
                    (string) $row['activityId'],
                    $attemptConfig,
                    is_array($assignment['activityConfig'] ?? null) ? $assignment['activityConfig'] : [],
                )
            ) {
                throw new EducationError(422, 'activity_result_required');
            }
            if (($proof['passed'] ?? true) === false) {
                throw new EducationError(422, 'activity_target_not_reached');
            }

            return (string) $row['id'];
        }

        $count = (int) $this->db->createCommand(
            'SELECT count(*) FROM education.homework_draft_files WHERE recipient_id=CAST(:id AS uuid)',
            [':id' => $recipient],
        )->queryScalar();
        if ($assignment['type'] === 'files' && $count === 0) {
            throw new EducationError(422, 'attachment_required');
        }
        if ($assignment['type'] === 'text' && trim((string) ($submission['text'] ?? '')) === '' && $count === 0) {
            throw new EducationError(422, 'answer_required');
        }

        return null;
    }

    public function assignmentView(Scope $scope, array $assignment): array
    {
        $assignment['studentIds'] = $this->db->createCommand(
            'SELECT student_id::text FROM education.homework_recipients
             WHERE assignment_id=CAST(:id AS uuid) AND organization_id=CAST(:org AS uuid) ORDER BY assigned_at,id',
            [':id' => $assignment['id'], ':org' => $scope->organizationId],
        )->queryColumn();
        $assignment['files'] = $this->files(
            "f.assignment_id=CAST(:id AS uuid) AND f.purpose='material'",
            [':id' => $assignment['id'], ':org' => $scope->organizationId],
        );

        return $assignment;
    }

    public function submissionView(Scope $scope, array $assignment, array $submission): array
    {
        foreach ($submission['reviews'] ?? [] as $index => $review) {
            $person = $this->db->createCommand(
                'SELECT a.person_id::text
                 FROM education.homework_reviews r
                 JOIN core.accounts a ON a.id = r.teacher_account_id
                 WHERE r.id = CAST(:id AS uuid)',
                [':id' => $review['id']],
            )->queryScalar();
            $submission['reviews'][$index]['reviewerName'] = is_string($person) ? ($this->access->personName($person) ?? '') : '';
        }
        $submission['files'] = $this->files(
            'f.recipient_id=CAST(:id AS uuid)',
            [':id' => $submission['recipientId'], ':org' => $scope->organizationId],
        );
        if (isset($submission['currentVersion'])) {
            $submission['currentVersion']['files'] = $this->files(
                'f.id IN (SELECT file_id FROM education.homework_version_files WHERE version_id=CAST(:id AS uuid))',
                [':id' => $submission['currentVersion']['id'], ':org' => $scope->organizationId],
            );
            $submission['currentVersion']['activityResult'] = $this->result(
                $submission['currentVersion']['activityAttemptId'] ?? null,
                $scope,
            );
        }
        $submission['selectedActivityResult'] = $this->result($submission['selectedActivityAttemptId'] ?? null, $scope);

        return $submission;
    }

    public function changed(Scope $scope, string $event, array $assignment, ?array $submission = null): void
    {
        $id = (string) $assignment['id'];
        if (in_array($event, ['published', 'recipient_added'], true)) {
            foreach ($this->db->createCommand(
                'SELECT r.id::text,r.student_id::text,r.person_id::text,s.owner_account_id::text
                 FROM education.homework_recipients r JOIN education.students s ON s.id=r.student_id
                 WHERE r.assignment_id=CAST(:id AS uuid) AND r.organization_id=CAST(:org AS uuid)',
                [':id' => $id, ':org' => $scope->organizationId],
            )->queryAll() as $recipient) {
                if (in_array($assignment['type'], ['typing', 'activity'], true)) {
                    $this->activityAssignment($scope, $assignment, $recipient);
                }
                if ($event === 'published') {
                    $this->notify(
                        $scope,
                        (string) $recipient['person_id'],
                        'assigned',
                        $assignment,
                        '/student/homework/' . $id,
                        'published:' . $id . ':' . $recipient['id'],
                    );
                }
            }
        }
        $this->parents->homework($scope, $event, $assignment, $submission);
        if ($event === 'deadline_changed') {
            $this->db->createCommand(
                'UPDATE activity.assignments a SET due_at=CAST(:due AS timestamptz)
                 FROM education.homework_recipients r WHERE r.activity_assignment_id=a.id AND r.assignment_id=CAST(:id AS uuid)
                 AND r.organization_id=CAST(:org AS uuid)',
                [':due' => $assignment['dueAt'], ':id' => $id, ':org' => $scope->organizationId],
            )->execute();
            foreach ($this->db->createCommand(
                'SELECT person_id::text FROM education.homework_recipients WHERE assignment_id=CAST(:id AS uuid)
                 AND organization_id=CAST(:org AS uuid)',
                [':id' => $id, ':org' => $scope->organizationId],
            )->queryAll() as $r) {
                $this->notify(
                    $scope,
                    (string) $r['person_id'],
                    $event,
                    $assignment,
                    '/student/homework/' . $id,
                    $event . ':' . $id . ':' . $assignment['version'],
                );
            }
        }
        if ($submission === null) {
            return;
        }
        if ($event === 'submitted') {
            $version = $submission['currentVersion'];
            $this->db->createCommand(
                'INSERT INTO education.homework_version_files(version_id,file_id)
                 SELECT CAST(:version AS uuid),file_id FROM education.homework_draft_files WHERE recipient_id=CAST(:recipient AS uuid)
                 ON CONFLICT DO NOTHING',
                [':version' => $version['id'], ':recipient' => $submission['recipientId']],
            )->execute();
            $this->notify(
                $scope,
                (string) $assignment['authorPersonId'],
                $event,
                $assignment,
                '/homework/' . $id,
                'submitted:' . $version['id'],
            );
        }
        if (in_array($event, ['returned', 'reviewed'], true)) {
            if ($event === 'returned') {
                $this->db->createCommand(
                    'UPDATE education.homework_submissions SET selected_activity_attempt_id=NULL
                     WHERE id=CAST(:id AS uuid)',
                    [':id' => $submission['id']],
                )->execute();
            }
            $person = $this->db->createCommand(
                'SELECT person_id::text FROM education.homework_recipients WHERE id=CAST(:id AS uuid)',
                [':id' => $submission['recipientId']],
            )->queryScalar();
            $this->notify(
                $scope,
                (string) $person,
                $event,
                $assignment,
                '/student/homework/' . $id,
                $event . ':' . $submission['id'] . ':' . $submission['version'],
            );
        }
        if ($event === 'reviewed') {
            $this->achievements->recordHomework($scope, (string) $submission['id']);
            $this->awards->notify($scope, (string) $submission['studentId']);
        }
    }

    private function activityAssignment(Scope $scope, array $assignment, array $recipient): void
    {
        $existing = $this->db->createCommand(
            'SELECT activity_assignment_id FROM education.homework_recipients WHERE id=CAST(:id AS uuid)',
            [':id' => $recipient['id']],
        )->queryScalar();
        if ($existing !== null && $existing !== false) {
            return;
        }

        $code = ActivityCode::canonical((string) $assignment['activityCode']);
        if (!$this->activityPlugins->has($code)) {
            throw new EducationError(422, 'activity_unavailable');
        }
        $rawConfig = is_array($assignment['activityConfig'] ?? null) ? $assignment['activityConfig'] : [];
        $canonicalConfig = ActivityCode::toCanonicalConfig($this->activityPlugins, $code, $rawConfig, false);
        $targetActivity = $this->resolveOrCreateCanonicalActivity($scope, $code, $canonicalConfig);

        $override = $canonicalConfig;
        unset($override['assessmentId'], $override['maxAttempts']);
        if ($code === 'platform-typing' && !isset($override['minAccuracy'])) {
            $override['minAccuracy'] = 0;
        }
        if ($code === 'platform-logic' && !isset($override['minAccuracy'])) {
            $override['minAccuracy'] = 0;
        }

        $assignmentId = (string) $this->db->createCommand(
            "INSERT INTO activity.assignments (
                organization_id, activity_id, version_id, assigned_by_person_id,
                target_type, target_id, due_at, allow_late, config_override, status
             ) VALUES (
                CAST(:org AS uuid), CAST(:aid AS uuid), CAST(:vid AS uuid), CAST(:person AS uuid),
                'student', CAST(:student AS uuid), CAST(:due AS timestamptz), :late, CAST(:override AS jsonb), 'ACTIVE'
             ) RETURNING id::text",
            [
                ':org' => $scope->organizationId,
                ':aid' => $targetActivity['itemId'],
                ':vid' => $targetActivity['versionId'],
                ':person' => $scope->personId,
                ':student' => (string) $recipient['student_id'],
                ':due' => $assignment['dueAt'],
                // Homework deadline enforcement lives in HomeworkService / HomeworkActivityService (uses injected ClockInterface).
                ':late' => 'true',
                ':override' => $override === [] ? '{}' : json_encode($override, JSON_THROW_ON_ERROR),
            ],
        )->queryScalar();

        $this->db->createCommand(
            'UPDATE education.homework_recipients SET activity_assignment_id=CAST(:activity AS uuid) WHERE id=CAST(:id AS uuid)',
            [':activity' => $assignmentId, ':id' => $recipient['id']],
        )->execute();
    }

    /**
     * Resolves an existing published activity item + active version for a homework plugin,
     * creating an org catalog item cleanly if not yet bootstrapped.
     *
     * @param array<string, mixed> $config
     * @return array{itemId: string, versionId: string}
     */
    private function resolveOrCreateCanonicalActivity(Scope $scope, string $pluginCode, array $config): array
    {
        if ($pluginCode === 'platform-assessment' && isset($config['assessmentId']) && is_string($config['assessmentId'])) {
            $explicit = $this->db->createCommand(
                "SELECT id::text AS \"itemId\", active_version_id::text AS \"versionId\"
                 FROM activity.items
                 WHERE id = CAST(:id AS uuid)
                   AND organization_id = CAST(:org AS uuid)
                   AND plugin_code = 'platform-assessment'
                   AND active_version_id IS NOT NULL
                   AND deleted_at IS NULL
                 LIMIT 1",
                [':id' => $config['assessmentId'], ':org' => $scope->organizationId],
            )->queryOne();
            if (!is_array($explicit)) {
                throw new EducationError(422, 'activity_unavailable');
            }

            return ['itemId' => (string) $explicit['itemId'], 'versionId' => (string) $explicit['versionId']];
        }

        $row = $this->db->createCommand(
            "SELECT i.id::text AS \"itemId\",
                    COALESCE(i.active_version_id, v.id)::text AS \"versionId\"
             FROM activity.items i
             LEFT JOIN LATERAL (
                 SELECT id FROM activity.versions WHERE activity_id = i.id ORDER BY version_number DESC LIMIT 1
             ) v ON true
             WHERE i.organization_id = CAST(:org AS uuid)
               AND i.plugin_code = :code
               AND i.status = 'PUBLISHED'
               AND i.deleted_at IS NULL
             ORDER BY i.created_at ASC
             LIMIT 1",
            [':org' => $scope->organizationId, ':code' => $pluginCode],
        )->queryOne();

        if (is_array($row) && is_string($row['versionId'] ?? null) && $row['versionId'] !== '') {
            return ['itemId' => (string) $row['itemId'], 'versionId' => (string) $row['versionId']];
        }

        $plugin = $this->activityPlugins->get($pluginCode);
        $defaultCfg = $plugin->defaultConfig();
        $defaultCfg['revealAnswersPolicy'] = 'immediate';
        $title = $pluginCode === 'platform-typing' ? 'Typing Arena' : $plugin->title();

        $itemId = is_array($row) && is_string($row['itemId'] ?? null) && $row['itemId'] !== ''
            ? (string) $row['itemId']
            : (string) $this->db->createCommand(
                "INSERT INTO activity.items (
                    organization_id, plugin_code, author_person_id, author_account_id,
                    title, description, status, visibility, tags
                 ) VALUES (
                    CAST(:org AS uuid), :code, CAST(:person AS uuid), CAST(:account AS uuid),
                    :title, :descr, 'PUBLISHED', 'public_catalog', '[]'::jsonb
                 ) RETURNING id::text",
                [
                    ':org' => $scope->organizationId,
                    ':code' => $pluginCode,
                    ':person' => $scope->personId,
                    ':account' => $scope->actor(),
                    ':title' => $title,
                    ':descr' => 'Canonical ' . $plugin->title() . ' activity',
                ],
            )->queryScalar();

        $verId = (string) $this->db->createCommand(
            "INSERT INTO activity.versions (
                activity_id, version_number, title, instructions, config, content,
                time_limit_seconds, max_attempts, passing_score_percent, grading_policy, reveal_answers_policy, auto_publish_results, created_by_person_id
             ) VALUES (
                CAST(:aid AS uuid), 1, :title, '', CAST(:cfg AS jsonb), '{}'::jsonb,
                :timeLimit, 0, 65, 'highest', 'immediate', true, CAST(:person AS uuid)
             ) RETURNING id::text",
            [
                ':aid' => $itemId,
                ':title' => $title,
                ':cfg' => json_encode($defaultCfg, JSON_THROW_ON_ERROR),
                ':timeLimit' => isset($defaultCfg['timeLimitSeconds']) && is_int($defaultCfg['timeLimitSeconds']) ? $defaultCfg['timeLimitSeconds'] : null,
                ':person' => $scope->personId,
            ],
        )->queryScalar();

        $this->db->createCommand(
            'UPDATE activity.items SET active_version_id = CAST(:vid AS uuid) WHERE id = CAST(:aid AS uuid)',
            [':vid' => $verId, ':aid' => $itemId],
        )->execute();

        return ['itemId' => $itemId, 'versionId' => $verId];
    }

    private function files(string $where, array $params): array
    {
        return $this->db->createCommand(
            'SELECT f.id::text,f.name,f.mime,f.size_bytes AS "sizeBytes",f.created_at::text AS "createdAt"
             FROM education.homework_files f WHERE f.organization_id=CAST(:org AS uuid) AND ' . $where . ' ORDER BY f.created_at,f.id',
            $params,
        )->queryAll();
    }

    private function result(?string $attempt, Scope $scope): ?array
    {
        return $attempt === null ? null : $this->activityResults->forAttempt($scope->organizationId, $attempt);
    }

    private function notify(Scope $scope, string $person, string $event, array $assignment, string $path, string $key): void
    {
        $kind = match ($event) {
            'assigned' => 'homework_published',
            'deadline_changed' => 'homework_deadline_changed',
            'submitted' => 'homework_submitted',
            'returned' => 'homework_returned',
            default => 'homework_reviewed',
        };
        $title = match ($event) {
            'assigned' => 'Новое домашнее задание: ' . $assignment['title'],
            'deadline_changed' => 'Обновлён срок: ' . $assignment['title'],
            'submitted' => 'Работа сдана: ' . $assignment['title'],
            'returned' => 'Работа возвращена на доработку: ' . $assignment['title'],
            default => 'Работа проверена: ' . $assignment['title'],
        };
        $this->db->createCommand(
            'INSERT INTO education.notifications
                (organization_id,person_id,kind,resource_id,resource_path,subject,body,event_key)
             VALUES (CAST(:org AS uuid),CAST(:person AS uuid),:kind,CAST(:resource AS uuid),:path,:subject,:body,:key)
             ON CONFLICT (organization_id,person_id,event_key) WHERE event_key IS NOT NULL DO NOTHING',
            [
                ':org' => $scope->organizationId,
                ':person' => $person,
                ':kind' => $kind,
                ':resource' => $assignment['id'],
                ':path' => $path,
                ':subject' => $title,
                ':body' => $title,
                ':key' => 'homework:' . $key,
            ],
        )->execute();
    }
}
