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
            $row = $this->db->createCommand(
                'SELECT a.id::text,a.student_id::text AS "studentId",a.status,a.revoked_at::text AS "revokedAt",
                a.finished_at::text AS "finishedAt",d.code,a.config FROM education.activity_attempts a
                JOIN education.activity_definitions d ON d.id=a.activity_id WHERE a.id=CAST(:id AS uuid)
                AND a.organization_id=CAST(:org AS uuid) AND a.student_id=CAST(:student AS uuid) AND a.account_id=CAST(:account AS uuid)',
                [':id' => Input::uuid($attemptId),':org' => $scope->organizationId,':student' => $scope->studentId,':account' => $scope->accountId],
            )->queryOne();
            if (!is_array($row)) {
                throw new EducationError(404, 'not_found');
            }
            $this->lock($scope->organizationId, $row['studentId']);
            if ($row['status'] !== 'finished') {
                throw new EducationError(409, 'attempt_not_finished');
            }
            $result = $this->results->forAttempt($scope->organizationId, $attemptId);
            if ($result !== null) {
                $this->event(
                    $scope->organizationId,
                    $row['studentId'],
                    'attempt',
                    $attemptId,
                    $row['finishedAt'],
                    ['activityCode' => $row['code'],'config' => ActivityHandlerRegistry::json($row['config']),'result' => $result],
                );
            }
            $this->reconcile($scope->organizationId, $row['studentId']);
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
                [':id' => Input::uuid($submissionId),':org' => $scope->organizationId,':actor' => $scope->actor()],
            )->queryOne();
            if (!is_array($row)) {
                throw new EducationError(404, 'not_found');
            }
            $this->lock($scope->organizationId, $row['studentId']);
            if ($row['state'] === 'REVIEWED' && $row['decision'] === 'accepted') {
                $this->event($scope->organizationId, $row['studentId'], 'homework', $submissionId, $row['reviewedAt'], ['accepted' => true,'homeworkId' => $row['homeworkId']]);
            }
            $this->reconcile($scope->organizationId, $row['studentId']);
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
            [':org' => $org,':student' => $student,':type' => $type,':source' => $source,':at' => $at,':payload' => json_encode($payload, JSON_THROW_ON_ERROR)],
        )->execute();
    }

    private function lock(string $org, string $student): void
    {
        $this->db->createCommand('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))', [':key' => 'education.achievements.' . $org . '.' . $student])->queryScalar();
    }

    /** Events are not deleted on correction. Current confirmed source validity controls all proofs. */
    private function reconcile(string $org, string $student): void
    {
        $params = [':org' => $org,':student' => $student];
        $this->db->createCommand('UPDATE education.achievement_events e SET revoked_at=CASE WHEN (
            (e.source_type=\'attempt\' AND EXISTS(SELECT 1 FROM education.activity_attempts a WHERE a.id=e.source_id
                AND a.organization_id=e.organization_id AND a.student_id=e.student_id AND a.status=\'finished\' AND a.revoked_at IS NULL)) OR
            (e.source_type=\'homework\' AND EXISTS(SELECT 1 FROM education.homework_submissions s
                JOIN education.homework_recipients r ON r.id=s.recipient_id WHERE s.id=e.source_id
                AND s.organization_id=e.organization_id AND r.student_id=e.student_id AND s.state=\'REVIEWED\'
                AND (SELECT rv.decision FROM education.homework_submission_versions v LEFT JOIN education.homework_reviews rv ON rv.submission_version_id=v.id
                    WHERE v.submission_id=s.id ORDER BY v.sequence DESC LIMIT 1)=\'accepted\'))
            ) THEN NULL ELSE COALESCE(e.revoked_at,now()) END WHERE e.organization_id=CAST(:org AS uuid) AND e.student_id=CAST(:student AS uuid)', $params)->execute();
        $events = $this->events($org, $student);
        $defs = $this->definitions();
        foreach ($defs as $definition) {
            $proof = $this->proof($definition, $events);
            $definitionId = $definition['id'];
            if ($proof['event'] === null) {
                $this->db->createCommand(
                    'UPDATE education.achievement_unlocks SET revoked_at=COALESCE(revoked_at,now())
                    WHERE organization_id=CAST(:org AS uuid) AND student_id=CAST(:student AS uuid) AND definition_id=CAST(:definition AS uuid)',
                    [...$params,':definition' => $definitionId],
                )->execute();
                continue;
            }
            $this->db->createCommand(
                'INSERT INTO education.achievement_unlocks(organization_id,student_id,definition_id,source_event_id)
                VALUES(CAST(:org AS uuid),CAST(:student AS uuid),CAST(:definition AS uuid),CAST(:event AS uuid))
                ON CONFLICT(organization_id,student_id,definition_id) DO UPDATE SET source_event_id=EXCLUDED.source_event_id,revoked_at=NULL',
                [...$params,':definition' => $definitionId,':event' => $proof['event']],
            )->execute();
        }
    }

    private function events(string $org, string $student): array
    {
        $rows = $this->db->createCommand('SELECT id::text,source_type AS "sourceType",source_id::text AS "sourceId",occurred_at::text AS "occurredAt",payload
            FROM education.achievement_events WHERE organization_id=CAST(:org AS uuid) AND student_id=CAST(:student AS uuid) AND revoked_at IS NULL
            ORDER BY occurred_at,id', [':org' => $org,':student' => $student])->queryAll();
        return array_map(static fn(array $r): array => [...$r,'payload' => ActivityHandlerRegistry::json($r['payload'])], $rows);
    }

    private function definitions(): array
    {
        return $this->db->createCommand('SELECT id::text,code,rule_version AS "ruleVersion",name,description,rule FROM education.achievement_definitions WHERE enabled ORDER BY code,rule_version')->queryAll();
    }

    /** A bounded state fold over confirmed events; no stored counters can drift on retry. */
    private function proof(array $definition, array $events): array
    {
        $rule = ActivityHandlerRegistry::json($definition['rule']);
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
                case 'first-training':if ($typing && ($result['typedCharacters'] ?? $result['keystrokes'] ?? 0) > 0) {
                    $current++;
                    $qualifies = $current >= $target;
                }break;
                case 'first-logic':if (($payload['activityCode'] ?? '') === 'platform-logic') {
                    $current++;
                    $qualifies = $current >= $target;
                }break;
                case 'first-homework':if ($event['sourceType'] === 'homework' && ($payload['accepted'] ?? false)) {
                    $current++;
                    $qualifies = $current >= $target;
                }break;
                case 'typing-accuracy':if ($typing && ($result['correctChars'] ?? 0) >= (int) ($rule['minCorrectChars'] ?? 20)) {
                    $current = max($current, (float) $result['accuracy']);
                    $qualifies = (float) $result['accuracy'] >= $target;
                }break;
                case 'personal-best':if ($typing) {
                    $c = $payload['config'];
                    $key = implode(':', [$c['language'] ?? 'en',$c['mode'] ?? 'words',$c['difficulty'] ?? 1,$c['durationSeconds'] ?? 30]);
                    $wpm = (float) $result['wpm'];
                    if (isset($best[$key]) && $wpm > $best[$key]) {
                        $current++;
                        $qualifies = $current >= $target;
                    }$best[$key] = max($best[$key] ?? 0, $wpm);
                }break;
                case 'successful-streak':if ($event['sourceType'] === 'attempt') {
                    $streak = ($result['passed'] ?? false) ? $streak + 1 : 0;
                    $current = max($current, $streak);
                    $qualifies = $streak >= $target;
                }break;
            }
            if ($qualifies && $proof === null) {
                $proof = $event['id'];
            }
        }
        return ['event' => $proof,'current' => min($target, $current),'target' => $target];
    }

    /** Caller authorizes the student. Callback applies existing subject/actor resource privacy. */
    public function readForStudent(string $organizationId, string $studentId, ?callable $allowed = null): array
    {
        $events = $this->events($organizationId, $studentId);
        if ($allowed !== null) {
            $events = array_values(array_filter($events, static fn(array $event): bool => $allowed(
                $event['sourceType'] === 'attempt' ? 'attempt' : 'homework',
                $event['payload']['homeworkId'] ?? $event['sourceId'],
            )));
        }
        $unlocks = $this->db->createCommand(
            'SELECT u.id::text,u.definition_id::text AS "definitionId",u.unlocked_at::text AS "unlockedAt",e.source_type AS "sourceType",e.source_id::text AS "sourceId",e.payload
            FROM education.achievement_unlocks u JOIN education.achievement_events e ON e.id=u.source_event_id
            WHERE u.organization_id=CAST(:org AS uuid) AND u.student_id=CAST(:student AS uuid) AND u.revoked_at IS NULL AND e.revoked_at IS NULL',
            [':org' => $organizationId,':student' => $studentId],
        )->queryAll();
        $byId = array_column($unlocks, null, 'definitionId');
        $out = [];
        foreach ($this->definitions() as $definition) {
            $proof = $this->proof($definition, $events);
            $unlock = $byId[$definition['id']] ?? [];
            if ($allowed !== null && $unlock !== [] && $proof['event'] === null) {
                continue;
            }
            $id = $unlock['id'] ?? $definition['id'];
            if ($allowed !== null) {
                if (!$allowed('achievement', $id)) {
                    continue;
                }
                if ($unlock !== []) {
                    $payload = ActivityHandlerRegistry::json($unlock['payload']);
                    if (!$allowed($unlock['sourceType'] === 'attempt' ? 'attempt' : 'homework', $payload['homeworkId'] ?? $unlock['sourceId'])) {
                        continue;
                    }
                }
            }
            $out[] = ['id' => $id,'code' => $definition['code'],'name' => $definition['name'],'description' => $definition['description'],'ruleVersion' => (int) $definition['ruleVersion'],
                'unlockedAt' => isset($unlock['unlockedAt']) ? (new \DateTimeImmutable($unlock['unlockedAt']))->format(DATE_ATOM) : null,
                'sourceType' => $unlock['sourceType'] ?? null,'sourceId' => $unlock['sourceId'] ?? null,'progress' => ['current' => $proof['current'],'target' => $proof['target']]];
        }return $out;
    }

    /** Full filtered aggregates with a bounded history. Raw states and answer keys never leave this method. */
    public function progressForStudent(string $organizationId, string $studentId, ?string $from = null, ?string $to = null, ?string $activity = null, ?callable $allowed = null): array
    {
        $params = [':org' => $organizationId,':student' => $studentId];
        $where = 'a.organization_id=CAST(:org AS uuid) AND a.student_id=CAST(:student AS uuid) AND a.status=\'finished\' AND a.revoked_at IS NULL';
        if ($from !== null) {
            $where .= ' AND a.finished_at>=CAST(:from AS timestamptz)';
            $params[':from'] = $from;
        }
        if ($to !== null) {
            $where .= ' AND a.finished_at<CAST(:to AS timestamptz)';
            $params[':to'] = $to;
        }
        if ($activity !== null) {
            $where .= ' AND d.code=:code';
            $params[':code'] = $activity;
        }
        $base = 'SELECT a.id::text AS "attemptId",a.finished_at::text AS "createdAt",a.assignment_id::text AS "assignmentId",d.code,
            a.config,r.metrics,t.duration_seconds AS "durationSeconds",t.language,t.keystrokes,t.correct_chars AS "correctChars",t.errors,t.wpm::float,t.accuracy::float
            FROM education.activity_attempts a JOIN education.activity_definitions d ON d.id=a.activity_id
            LEFT JOIN education.activity_results r ON r.attempt_id=a.id LEFT JOIN education.typing_results t ON t.attempt_id=a.id
            WHERE ' . $where;
        $typing = [];
        $logic = [];
        $assignments = [];
        $typingCount = 0;
        $logicCount = 0;
        $bestWpm = null;
        $bestScore = null;
        $accuracySum = 0.0;
        $cursor = null;
        $retained = 0;
        $total = 0;
        do {
            $cursorSql = $cursor === null ? '' : ' AND (a.finished_at,a.id)<(CAST(:cursorAt AS timestamptz),CAST(:cursorId AS uuid))';
            $cursorParams = $cursor === null ? [] : [':cursorAt' => $cursor['createdAt'],':cursorId' => $cursor['attemptId']];
            $rows = $this->db->createCommand($base . $cursorSql . ' ORDER BY a.finished_at DESC,a.id DESC LIMIT 500', [...$params,...$cursorParams])->queryAll();
            foreach ($rows as $row) {
                if ($allowed !== null && !$allowed('attempt', $row['attemptId'])) {
                    continue;
                }
                $metrics = ActivityHandlerRegistry::json($row['metrics']);
                $config = ActivityHandlerRegistry::json($row['config']);
                if ($metrics === [] && $row['code'] === 'platform-typing' && $row['durationSeconds'] !== null) {
                    $metrics = ['durationSeconds' => (int) $row['durationSeconds'],'language' => $row['language'],'wpm' => (float) $row['wpm'],
                        'accuracy' => (float) $row['accuracy'],'errors' => (int) $row['errors'],'typedCharacters' => (int) $row['keystrokes'],'passed' => (int) $row['keystrokes'] > 0];
                }
                if ($metrics === []) {
                    continue;
                }
                unset($metrics['rounds']);
                $total++;
                $item = ['attemptId' => $row['attemptId'],'createdAt' => (new \DateTimeImmutable($row['createdAt']))->format(DATE_ATOM),
                    'mode' => $config['mode'] ?? 'words','difficulty' => (int) ($config['difficulty'] ?? 1),...$metrics];
                if ($row['code'] === 'platform-typing') {
                    $typingCount++;
                    $bestWpm = max($bestWpm ?? 0, (float) $metrics['wpm']);
                    $accuracySum += (float) $metrics['accuracy'];
                    if ($retained < 500) {
                        $typing[] = $item;
                        $retained++;
                    }
                } elseif ($row['code'] === 'platform-logic') {
                    $logicCount++;
                    $bestScore = max($bestScore ?? 0, (int) $metrics['score']);
                    if ($retained < 500) {
                        $logic[] = $item;
                        $retained++;
                    }
                }
                if ($row['assignmentId'] !== null && ($metrics['passed'] ?? false)) {
                    $assignments[$row['assignmentId']] = true;
                }
            }
            if ($rows !== []) {
                $cursor = $rows[array_key_last($rows)];
            }
        } while (count($rows) === 500);
        return ['typing' => ['attempts' => $typingCount,'bestWpm' => $bestWpm,'averageAccuracy' => $typingCount === 0 ? null : round($accuracySum / $typingCount, 2),'history' => $typing],
            'logic' => ['attempts' => $logicCount,'bestScore' => $bestScore,'history' => $logic],
            'achievements' => $this->readForStudent($organizationId, $studentId, $allowed),'completedAssignments' => count($assignments),
            'historyLimit' => 500,'historyTruncated' => $total > 500];
    }
}
