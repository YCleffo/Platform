<?php

declare(strict_types=1);

namespace App\Education\Application;

use Yiisoft\Db\Connection\ConnectionInterface;

/** Confirmed source records are the only inputs. No client score or award command exists. */
final readonly class AchievementService
{
    public function __construct(private ConnectionInterface $db, private ActivityResultView $results) {}

    public function recordAttempt(Scope $scope, string $attemptId): void
    {
        $this->db->transaction(function () use ($scope, $attemptId): void {
            $id = Input::uuid($attemptId);
            $row = $this->db->createCommand(
                'SELECT a.id::text, s.id::text AS "studentId", a.state,
                        COALESCE(a.evaluated_at, a.submitted_at, a.updated_at)::text AS "finishedAt",
                        i.plugin_code AS code, a.runtime_config AS config
                 FROM activity.attempts a
                 JOIN activity.items i ON i.id = a.activity_id
                 JOIN education.students s ON s.person_id = a.participant_person_id AND s.organization_id = a.organization_id
                 WHERE a.id = CAST(:id AS uuid)
                   AND a.organization_id = CAST(:org AS uuid)
                   AND s.id = CAST(:student AS uuid)',
                [':id' => $id, ':org' => $scope->organizationId, ':student' => $scope->studentId],
            )->queryOne();

            if (is_array($row)) {
                $this->lock($scope->organizationId, (string) $row['studentId']);
                if (!in_array((string) $row['state'], ['EVALUATED', 'COMPLETED', 'EXPIRED'], true)) {
                    throw new EducationError(409, 'attempt_not_finished');
                }
                $result = $this->results->forAttempt($scope->organizationId, $id);
                if ($result !== null) {
                    $this->event(
                        $scope->organizationId,
                        (string) $row['studentId'],
                        'attempt',
                        $id,
                        (string) $row['finishedAt'],
                        ['activityCode' => (string) $row['code'], 'config' => Input::json($row['config']), 'result' => $result],
                    );
                }
                $this->reconcile($scope->organizationId, (string) $row['studentId']);
                return;
            }

            // Read-only fallback for unmigrated historical attempts
            $legacy = $this->db->createCommand(
                'SELECT a.id::text,a.student_id::text AS "studentId",a.status,a.revoked_at::text AS "revokedAt",
                a.finished_at::text AS "finishedAt",d.code,a.config FROM education.activity_attempts a
                JOIN education.activity_definitions d ON d.id=a.activity_id WHERE a.id=CAST(:id AS uuid)
                AND a.organization_id=CAST(:org AS uuid) AND a.student_id=CAST(:student AS uuid) AND a.account_id=CAST(:account AS uuid)',
                [':id' => $id, ':org' => $scope->organizationId, ':student' => $scope->studentId, ':account' => $scope->accountId],
            )->queryOne();
            if (!is_array($legacy)) {
                throw new EducationError(404, 'not_found');
            }
            $this->lock($scope->organizationId, (string) $legacy['studentId']);
            if ($legacy['status'] !== 'finished') {
                throw new EducationError(409, 'attempt_not_finished');
            }
            $result = $this->results->forAttempt($scope->organizationId, $id);
            if ($result !== null) {
                $this->event(
                    $scope->organizationId,
                    (string) $legacy['studentId'],
                    'attempt',
                    $id,
                    (string) $legacy['finishedAt'],
                    ['activityCode' => (string) $legacy['code'], 'config' => Input::json($legacy['config']), 'result' => $result],
                );
            }
            $this->reconcile($scope->organizationId, (string) $legacy['studentId']);
        });
    }

    /** Called after persisted teacher review/return, inside the same Education mutation. */
    public function recordHomework(Scope $scope, string $submissionId): void
    {
        $this->db->transaction(function () use ($scope, $submissionId): void {
            $row = $this->db->createCommand(
                'SELECT s.id::text,s.state,h.id::text AS "homeworkId",r.student_id::text AS "studentId",h.author_account_id::text AS author,
                latest.decision,latest.created_at::text AS "reviewedAt" FROM education.homework_submissions s
                JOIN education.homework_recipients r ON r.id=s.recipient_id AND r.organization_id=s.organization_id
                JOIN education.homework_assignments h ON h.id=r.assignment_id AND h.organization_id=r.organization_id
                LEFT JOIN LATERAL(SELECT v.id,rv.decision,rv.created_at FROM education.homework_submission_versions v
                    LEFT JOIN education.homework_reviews rv ON rv.submission_version_id=v.id
                    WHERE v.submission_id=s.id ORDER BY v.sequence DESC LIMIT 1) latest ON true
                WHERE s.id=CAST(:id AS uuid) AND s.organization_id=CAST(:org AS uuid)
                AND (h.author_account_id=CAST(:actor AS uuid) OR education.can_teach_group(h.organization_id,CAST(:actor AS uuid),h.group_id)
                    OR (h.group_id IS NULL AND EXISTS(SELECT 1 FROM education.group_students gs WHERE gs.student_id=r.student_id
                        AND gs.organization_id=r.organization_id AND education.can_teach_group(gs.organization_id,CAST(:actor AS uuid),gs.group_id))))',
                [':id' => Input::uuid($submissionId), ':org' => $scope->organizationId, ':actor' => $scope->actor()],
            )->queryOne();
            if (!is_array($row)) {
                throw new EducationError(404, 'not_found');
            }
            $this->lock($scope->organizationId, (string) $row['studentId']);
            if ($row['state'] === 'REVIEWED' && $row['decision'] === 'accepted') {
                $this->event($scope->organizationId, (string) $row['studentId'], 'homework', $submissionId, (string) $row['reviewedAt'], ['accepted' => true, 'homeworkId' => $row['homeworkId']]);
            }
            $this->reconcile($scope->organizationId, (string) $row['studentId']);
        });
    }

    /** Trusted module caller only, after its teacher revoke authorization has succeeded. */
    public function reconcileForStudent(string $organizationId, string $studentId): void
    {
        $this->db->transaction(function () use ($organizationId, $studentId): void {
            $this->lock($organizationId, $studentId);
            $this->reconcile($organizationId, $studentId);
        });
    }

    private function event(string $org, string $student, string $type, string $source, string $at, array $payload): void
    {
        $this->db->createCommand(
            'INSERT INTO education.achievement_events(organization_id,student_id,source_type,source_id,occurred_at,payload)
            VALUES(CAST(:org AS uuid),CAST(:student AS uuid),:type,CAST(:source AS uuid),CAST(:at AS timestamptz),CAST(:payload AS jsonb))
            ON CONFLICT(organization_id,student_id,source_type,source_id) DO NOTHING',
            [':org' => $org, ':student' => $student, ':type' => $type, ':source' => $source, ':at' => $at, ':payload' => json_encode($payload, JSON_THROW_ON_ERROR)],
        )->execute();
    }

    private function lock(string $org, string $student): void
    {
        $this->db->createCommand('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))', [':key' => 'education.achievements.' . $org . '.' . $student])->queryScalar();
    }

    /** Events are not deleted on correction. Current confirmed source validity controls all proofs. */
    private function reconcile(string $org, string $student): void
    {
        $params = [':org' => $org, ':student' => $student];
        $this->db->createCommand(
            'UPDATE education.achievement_events e SET revoked_at=CASE WHEN (
            (e.source_type=\'attempt\' AND (
                EXISTS(SELECT 1 FROM activity.attempts a
                    JOIN education.students s ON s.person_id=a.participant_person_id AND s.organization_id=a.organization_id
                    WHERE a.id=e.source_id AND a.organization_id=e.organization_id AND s.id=e.student_id
                      AND a.state IN (\'EVALUATED\',\'COMPLETED\',\'EXPIRED\'))
                OR EXISTS(SELECT 1 FROM education.activity_attempts a WHERE a.id=e.source_id
                    AND a.organization_id=e.organization_id AND a.student_id=e.student_id AND a.status=\'finished\' AND a.revoked_at IS NULL)
            )) OR
            (e.source_type=\'homework\' AND EXISTS(SELECT 1 FROM education.homework_submissions s
                JOIN education.homework_recipients r ON r.id=s.recipient_id WHERE s.id=e.source_id
                AND s.organization_id=e.organization_id AND r.student_id=e.student_id AND s.state=\'REVIEWED\'
                AND (SELECT rv.decision FROM education.homework_submission_versions v LEFT JOIN education.homework_reviews rv ON rv.submission_version_id=v.id
                    WHERE v.submission_id=s.id ORDER BY v.sequence DESC LIMIT 1)=\'accepted\'))
            ) THEN NULL ELSE COALESCE(e.revoked_at,now()) END WHERE e.organization_id=CAST(:org AS uuid) AND e.student_id=CAST(:student AS uuid)',
            $params,
        )->execute();
        $events = $this->events($org, $student);
        $defs = $this->definitions();
        foreach ($defs as $definition) {
            $proof = $this->proof($definition, $events);
            $definitionId = $definition['id'];
            if ($proof['event'] === null) {
                $this->db->createCommand(
                    'UPDATE education.achievement_unlocks SET revoked_at=COALESCE(revoked_at,now())
                    WHERE organization_id=CAST(:org AS uuid) AND student_id=CAST(:student AS uuid) AND definition_id=CAST(:definition AS uuid)',
                    [...$params, ':definition' => $definitionId],
                )->execute();
                continue;
            }
            $this->db->createCommand(
                'INSERT INTO education.achievement_unlocks(organization_id,student_id,definition_id,source_event_id)
                VALUES(CAST(:org AS uuid),CAST(:student AS uuid),CAST(:definition AS uuid),CAST(:event AS uuid))
                ON CONFLICT(organization_id,student_id,definition_id) DO UPDATE SET source_event_id=EXCLUDED.source_event_id,revoked_at=NULL',
                [...$params, ':definition' => $definitionId, ':event' => $proof['event']],
            )->execute();
        }
    }

    private function events(string $org, string $student): array
    {
        $rows = $this->db->createCommand(
            'SELECT id::text,source_type AS "sourceType",source_id::text AS "sourceId",occurred_at::text AS "occurredAt",payload
            FROM education.achievement_events WHERE organization_id=CAST(:org AS uuid) AND student_id=CAST(:student AS uuid) AND revoked_at IS NULL
            ORDER BY occurred_at,id',
            [':org' => $org, ':student' => $student],
        )->queryAll();

        return array_map(static fn(array $r): array => [...$r, 'payload' => Input::json($r['payload'])], $rows);
    }

    private function definitions(): array
    {
        return $this->db->createCommand('SELECT id::text,code,rule_version AS "ruleVersion",name,description,rule FROM education.achievement_definitions WHERE enabled ORDER BY code,rule_version')->queryAll();
    }

    /** A bounded state fold over confirmed events; no stored counters can drift on retry. */
    private function proof(array $definition, array $events): array
    {
        $rule = Input::json($definition['rule']);
        $type = $rule['type'] ?? '';
        $target = (float) ($rule['target'] ?? 1);
        $current = 0.0;
        $best = [];
        $streak = 0;
        $proof = null;
        foreach ($events as $event) {
            $payload = $event['payload'];
            $result = $payload['result'] ?? [];
            $typing = ($payload['activityCode'] ?? '') === 'platform-typing';
            $qualifies = false;
            switch ($type) {
                case 'first-training':
                    if ($typing && ($result['typedCharacters'] ?? $result['totalTypedChars'] ?? $result['keystrokes'] ?? 0) > 0) {
                        $current++;
                        $qualifies = $current >= $target;
                    }
                    break;
                case 'first-logic':
                    if (($payload['activityCode'] ?? '') === 'platform-logic') {
                        $current++;
                        $qualifies = $current >= $target;
                    }
                    break;
                case 'first-homework':
                    if ($event['sourceType'] === 'homework' && ($payload['accepted'] ?? false)) {
                        $current++;
                        $qualifies = $current >= $target;
                    }
                    break;
                case 'typing-accuracy':
                    if ($typing && ($result['correctChars'] ?? 0) >= (int) ($rule['minCorrectChars'] ?? 20)) {
                        $current = max($current, (float) ($result['accuracy'] ?? 0));
                        $qualifies = (float) ($result['accuracy'] ?? 0) >= $target;
                    }
                    break;
                case 'personal-best':
                    if ($typing) {
                        $c = is_array($payload['config'] ?? null) ? $payload['config'] : [];
                        $dur = $c['durationSeconds'] ?? $c['timeLimitSeconds'] ?? 30;
                        $key = implode(':', [$c['language'] ?? 'en', $c['mode'] ?? 'words', $c['difficulty'] ?? 1, $dur]);
                        $wpm = (float) ($result['wpm'] ?? 0);
                        if (isset($best[$key]) && $wpm > $best[$key]) {
                            $current++;
                            $qualifies = $current >= $target;
                        }
                        $best[$key] = max($best[$key] ?? 0, $wpm);
                    }
                    break;
                case 'successful-streak':
                    if ($event['sourceType'] === 'attempt') {
                        $streak = ($result['passed'] ?? false) ? $streak + 1 : 0;
                        $current = max($current, $streak);
                        $qualifies = $streak >= $target;
                    }
                    break;
            }
            if ($qualifies && $proof === null) {
                $proof = $event['id'];
            }
        }

        return ['event' => $proof, 'current' => min($target, $current), 'target' => $target];
    }

    /** Caller authorizes the student. Callback applies existing subject/actor resource privacy. */
    public function readForStudent(string $organizationId, string $studentId, ?callable $allowed = null): array
    {
        $events = $this->events($organizationId, $studentId);
        if ($allowed !== null) {
            $events = array_values(array_filter(
                $events,
                static fn(array $e): bool => $allowed($e['sourceType'] === 'homework' ? 'homework' : 'attempt', (string) $e['sourceId']),
            ));
        }
        $unlocks = [];
        foreach ($this->db->createCommand(
            'SELECT definition_id::text AS id,source_event_id::text AS event,unlocked_at::text AS at
            FROM education.achievement_unlocks WHERE organization_id=CAST(:org AS uuid) AND student_id=CAST(:student AS uuid) AND revoked_at IS NULL',
            [':org' => $organizationId, ':student' => $studentId],
        )->queryAll() as $unlock) {
            $unlocks[$unlock['id']] = $unlock;
        }
        $out = [];
        foreach ($this->definitions() as $definition) {
            $progress = $this->proof($definition, $events);
            $unlock = $unlocks[$definition['id']] ?? null;
            if ($unlock !== null && $progress['event'] === null) {
                $unlock = null;
            }
            if ($unlock === null && $progress['event'] !== null) {
                $row = $this->db->createCommand('SELECT unlocked_at::text AS at,payload FROM education.achievement_unlocks u
                    JOIN education.achievement_events e ON e.id=u.source_event_id WHERE u.organization_id=CAST(:org AS uuid)
                    AND u.student_id=CAST(:student AS uuid) AND u.definition_id=CAST(:def AS uuid) AND u.revoked_at IS NULL AND e.revoked_at IS NULL', [':org' => $organizationId, ':student' => $studentId, ':def' => $definition['id']])->queryOne();
                if (is_array($row)) {
                    $payload = Input::json($row['payload']);
                    if ($allowed === null || ($payload['activityCode'] ?? null) !== null) {
                        $unlock = ['at' => $row['at']];
                    }
                }
            }
            $out[] = [
                'code' => $definition['code'], 'ruleVersion' => (int) $definition['ruleVersion'],
                'name' => $definition['name'], 'description' => $definition['description'],
                'unlocked' => $unlock !== null, 'unlockedAt' => $unlock['at'] ?? null,
                'progress' => ['current' => $progress['current'], 'target' => $progress['target']],
            ];
        }

        return $out;
    }

    /** Full filtered aggregates with a bounded history. Raw states and answer keys never leave this method. */
    public function progressForStudent(string $organizationId, string $studentId, ?string $from = null, ?string $to = null, ?string $activity = null, ?callable $allowed = null): array
    {
        $params = [':org' => $organizationId, ':student' => $studentId];
        $whereEngine = 'a.organization_id=CAST(:org AS uuid) AND s.id=CAST(:student AS uuid) AND a.state IN (\'EVALUATED\',\'COMPLETED\',\'EXPIRED\')';
        $whereLegacy = 'a.organization_id=CAST(:org AS uuid) AND a.student_id=CAST(:student AS uuid) AND a.status=\'finished\' AND a.revoked_at IS NULL AND NOT EXISTS (SELECT 1 FROM activity.attempts aa WHERE aa.id=a.id)';
        if ($from !== null) {
            $whereEngine .= ' AND COALESCE(a.evaluated_at,a.submitted_at,a.updated_at)>=CAST(:from AS timestamptz)';
            $whereLegacy .= ' AND a.finished_at>=CAST(:from AS timestamptz)';
            $params[':from'] = $from;
        }
        if ($to !== null) {
            $whereEngine .= ' AND COALESCE(a.evaluated_at,a.submitted_at,a.updated_at)<CAST(:to AS timestamptz)';
            $whereLegacy .= ' AND a.finished_at<CAST(:to AS timestamptz)';
            $params[':to'] = $to;
        }
        if ($activity !== null) {
            $whereEngine .= ' AND i.plugin_code=:code';
            $whereLegacy .= ' AND d.code=:code';
            $params[':code'] = $activity;
        }
        $rows = $this->db->createCommand(
            'SELECT * FROM (
                SELECT a.id::text AS "attemptId",
                       COALESCE(a.evaluated_at,a.submitted_at,a.updated_at)::text AS "createdAt",
                       a.assignment_id::text AS "assignmentId",
                       i.plugin_code AS code,
                       a.runtime_config AS config,
                       (a.evaluation->\'metrics\') AS metrics,
                       a.raw_score::float AS "rawScore",
                       a.percentage::float AS percentage,
                       a.passed,
                       NULL::int AS "durationSeconds", NULL::text AS language, NULL::int AS keystrokes,
                       NULL::int AS "correctChars", NULL::int AS errors, NULL::float AS wpm, NULL::float AS accuracy
                FROM activity.attempts a
                JOIN activity.items i ON i.id=a.activity_id
                JOIN education.students s ON s.person_id=a.participant_person_id AND s.organization_id=a.organization_id
                WHERE ' . $whereEngine . '
                UNION ALL
                SELECT a.id::text AS "attemptId",a.finished_at::text AS "createdAt",a.assignment_id::text AS "assignmentId",d.code,
                       a.config,r.metrics,NULL::float,NULL::float,r.passed,
                       t.duration_seconds AS "durationSeconds",t.language,t.keystrokes,t.correct_chars AS "correctChars",t.errors,t.wpm::float,t.accuracy::float
                FROM education.activity_attempts a JOIN education.activity_definitions d ON d.id=a.activity_id
                LEFT JOIN education.activity_results r ON r.attempt_id=a.id LEFT JOIN education.typing_results t ON t.attempt_id=a.id
                WHERE ' . $whereLegacy . '
            ) u ORDER BY "createdAt" DESC, "attemptId" DESC LIMIT 501',
            $params,
        )->queryAll();

        $typing = [];
        $logic = [];
        $assignments = [];
        $typingCount = 0;
        $logicCount = 0;
        $bestWpm = null;
        $bestScore = null;
        $accuracySum = 0.0;
        $retained = 0;
        $total = 0;

        foreach ($rows as $row) {
            if ($allowed !== null && !$allowed('attempt', (string) $row['attemptId'])) {
                continue;
            }
            $metrics = Input::json($row['metrics']);
            $config = Input::json($row['config']);
            if ($metrics === [] && $row['code'] === 'platform-typing' && $row['durationSeconds'] !== null) {
                $metrics = [
                    'durationSeconds' => (int) $row['durationSeconds'], 'language' => $row['language'], 'wpm' => (float) $row['wpm'],
                    'accuracy' => (float) $row['accuracy'], 'errors' => (int) $row['errors'], 'typedCharacters' => (int) $row['keystrokes'], 'passed' => (int) $row['keystrokes'] > 0,
                ];
            } elseif ($row['code'] === 'platform-typing' && $metrics !== []) {
                $metrics['typedCharacters'] = (int) ($metrics['typedCharacters'] ?? $metrics['totalTypedChars'] ?? 0);
                $metrics['durationSeconds'] = (int) ($metrics['durationSeconds'] ?? round((float) ($metrics['elapsedSeconds'] ?? 0)));
                $metrics['errors'] = (int) ($metrics['errors'] ?? $metrics['errorCount'] ?? 0);
                $metrics['passed'] = (bool) ($row['passed'] ?? $metrics['passed'] ?? false);
            } elseif ($row['code'] === 'platform-logic' && ($metrics !== [] || $row['rawScore'] !== null)) {
                $metrics['score'] = (int) ($metrics['score'] ?? round((float) ($row['rawScore'] ?? 0)));
                $metrics['accuracy'] = (float) ($metrics['accuracy'] ?? $row['percentage'] ?? 0.0);
                $metrics['passed'] = (bool) ($row['passed'] ?? $metrics['passed'] ?? false);
            }
            if ($metrics === []) {
                continue;
            }
            unset($metrics['rounds']);
            $total++;
            $item = [
                'attemptId' => $row['attemptId'],
                'createdAt' => (new \DateTimeImmutable((string) $row['createdAt']))->format(DATE_ATOM),
                'mode' => $config['mode'] ?? 'words',
                'difficulty' => (int) ($config['difficulty'] ?? 1),
                ...$metrics,
            ];
            if ($row['code'] === 'platform-typing') {
                $typingCount++;
                $bestWpm = max($bestWpm ?? 0, (float) ($metrics['wpm'] ?? 0));
                $accuracySum += (float) ($metrics['accuracy'] ?? 0);
                if ($retained < 500) {
                    $typing[] = $item;
                    $retained++;
                }
            } elseif ($row['code'] === 'platform-logic') {
                $logicCount++;
                $bestScore = max($bestScore ?? 0, (int) ($metrics['score'] ?? 0));
                if ($retained < 500) {
                    $logic[] = $item;
                    $retained++;
                }
            }
            if ($row['assignmentId'] !== null && ($metrics['passed'] ?? false)) {
                $assignments[$row['assignmentId']] = true;
            }
        }

        return [
            'typing' => ['attempts' => $typingCount, 'bestWpm' => $bestWpm, 'averageAccuracy' => $typingCount === 0 ? null : round($accuracySum / $typingCount, 2), 'history' => $typing],
            'logic' => ['attempts' => $logicCount, 'bestScore' => $bestScore, 'history' => $logic],
            'achievements' => $this->readForStudent($organizationId, $studentId, $allowed),
            'completedAssignments' => count($assignments),
            'historyLimit' => 500,
            'historyTruncated' => $total > 500,
        ];
    }
}
