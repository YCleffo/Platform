<?php

declare(strict_types=1);

namespace App\Education\Application;

use Yiisoft\Db\Connection\ConnectionInterface;

/** Safe projection. Callers authorize the student/assignment before reading it. */
final readonly class ActivityResultView
{
    public function __construct(private ConnectionInterface $db) {}

    public function forAttempt(string $organizationId, string $attemptId): ?array
    {
        // Canonical Universal Activity Engine result (PLATFORM_13_C)
        $engine = $this->db->createCommand(
            'SELECT a.id::text AS "attemptId", i.plugin_code AS "pluginCode",
                    a.raw_score AS "rawScore", a.max_score AS "maxScore",
                    a.percentage, a.passed, a.state, a.evaluation::text AS "evJson",
                    a.results_published_at::text AS "resultsPublishedAt"
             FROM activity.attempts a
             JOIN activity.items i ON i.id = a.activity_id
             WHERE a.id = CAST(:id AS uuid) AND a.organization_id = CAST(:org AS uuid)
               AND a.state IN (\'EVALUATED\', \'COMPLETED\')',
            [':id' => $attemptId, ':org' => $organizationId],
        )->queryOne();
        if (is_array($engine)) {
            $eval = ActivityHandlerRegistry::json($engine['evJson'] ?? []);
            $metrics = is_array($eval['metrics'] ?? null) ? $eval['metrics'] : [];
            $score = (float) ($engine['rawScore'] ?? 0);
            $maxScore = (float) ($engine['maxScore'] ?? 0);
            return [
                'attemptId' => $attemptId,
                ...$metrics,
                'type' => $engine['pluginCode'] === 'platform-assessment' ? 'assessment' : (string) $engine['pluginCode'],
                'score' => $score,
                'maxScore' => $maxScore,
                'accuracy' => (float) ($engine['percentage'] ?? 0.0),
                'percentage' => (float) ($engine['percentage'] ?? 0.0),
                'errors' => max(0, (int) round($maxScore - $score)),
                'passed' => (bool) ($engine['passed'] ?? false),
                'resultsPublished' => $engine['resultsPublishedAt'] !== null,
            ];
        }

        // Legacy fallback for pre-consolidation non-assessment activity rows
        $row = $this->db->createCommand('SELECT r.metrics FROM education.activity_results r JOIN education.activity_attempts a ON a.id=r.attempt_id
            WHERE a.id=CAST(:id AS uuid) AND a.organization_id=CAST(:org AS uuid) AND r.organization_id=a.organization_id
            AND a.status=\'finished\' AND a.revoked_at IS NULL', [':id' => $attemptId,':org' => $organizationId])->queryOne();
        if (is_array($row)) {
            return ['attemptId' => $attemptId,...ActivityHandlerRegistry::json($row['metrics'])];
        }

        $old = $this->db->createCommand(
            'SELECT t.attempt_id::text AS "attemptId",t.duration_seconds AS "durationSeconds",t.language,t.keystrokes,
            t.correct_chars AS "correctChars",t.errors,t.wpm::float,t.accuracy::float FROM education.typing_results t
            JOIN education.activity_attempts a ON a.id=t.attempt_id WHERE a.id=CAST(:id AS uuid) AND a.organization_id=CAST(:org AS uuid)
            AND t.organization_id=a.organization_id AND a.status=\'finished\' AND a.revoked_at IS NULL AND a.contract_version=1',
            [':id' => $attemptId,':org' => $organizationId],
        )->queryOne();
        return is_array($old) ? [...$old,'typedCharacters' => (int) $old['keystrokes'],'passed' => (int) $old['keystrokes'] > 0] : null;
    }
}
