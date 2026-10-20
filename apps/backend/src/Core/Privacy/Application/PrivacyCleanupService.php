<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Time\Application\ClockInterface;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Whitelisted, bounded lifecycle queries. Never accepts a table name from a caller. */
final readonly class PrivacyCleanupService
{
    private const CATEGORIES = [
        'audit', 'outbox', 'inbox', 'dsar_events', 'dsar_deadlines', 'dsar_executions',
        'processing_blocks', 'legal_holds', 'retention_decisions', 'auth_sessions',
        'destruction_evidence',
    ];

    public function __construct(private ConnectionInterface $db, private ClockInterface $clock,
        private PrivacyCleanupPolicy $policy) {}

    /** @return list<string> */
    public static function categories(): array { return self::CATEGORIES; }

    /** @return array{category:string,selected:int,deleted:int,dryRun:bool} */
    public function execute(string $category, int $limit = 100, bool $dryRun = true): array
    {
        if (!in_array($category, self::CATEGORIES, true) || $limit < 1 || $limit > 1000) {
            throw new DomainException('Invalid privacy cleanup batch.');
        }
        $days = $this->policy->days($category);
        if ($days === null && $category !== 'destruction_evidence') {
            throw new DomainException('Privacy cleanup retention policy is missing.');
        }
        $now = $this->clock->now();
        $params = [':now' => $now->format(DATE_ATOM), ':limit' => $limit,
            ':cutoff' => $days === null ? $now->format(DATE_ATOM)
                : $now->modify('-' . $days . ' days')->format(DATE_ATOM)];
        [$table, $where] = self::definition($category);
        $select = "SELECT e.id FROM core.{$table} e WHERE {$where} ORDER BY e.id LIMIT CAST(:limit AS integer)";
        if ($dryRun) {
            $selected = (int) $this->db->createCommand(
                "SELECT count(*) FROM ({$select}) eligible", $params)->queryScalar();
            return ['category' => $category, 'selected' => $selected,
                'deleted' => 0, 'dryRun' => true];
        }
        return $this->db->transaction(function () use ($category, $days, $table, $where, $params): array {
            $sql = <<<SQL
                WITH selected AS (
                    SELECT e.id FROM core.{$table} e WHERE {$where}
                    ORDER BY e.id FOR UPDATE SKIP LOCKED LIMIT CAST(:limit AS integer)
                )
                DELETE FROM core.{$table} d USING selected s WHERE d.id = s.id
                SQL;
            $this->db->createCommand("SET LOCAL app.privacy_lifecycle = 'on'")->execute();
            $deleted = $this->db->createCommand($sql, $params)->execute();
            $this->db->createCommand("SET LOCAL app.privacy_lifecycle = 'off'")->execute();
            $this->db->createCommand(<<<'SQL'
                INSERT INTO core.privacy_cleanup_runs (category_code, deleted_count, policy_days)
                VALUES (:category, :count, :days)
                SQL, [':category' => $category, ':count' => $deleted, ':days' => $days])->execute();
            return ['category' => $category, 'selected' => $deleted,
                'deleted' => $deleted, 'dryRun' => false];
        });
    }

    /** @return array{string,string} */
    private static function definition(string $category): array
    {
        $activeHold = "h.released_at IS NULL AND (h.expires_at IS NULL OR h.expires_at > CAST(:now AS timestamptz))";
        $globalHold = "NOT EXISTS (SELECT 1 FROM core.privacy_legal_holds h WHERE {$activeHold})";
        $subjectHold = static fn(string $subject): string =>
            "NOT EXISTS (SELECT 1 FROM core.privacy_legal_holds h WHERE {$activeHold} AND h.subject_person_id = {$subject})";
        $request = '(SELECT r.subject_person_id FROM core.data_subject_requests r WHERE r.id = e.request_id)';
        $terminalRequest = "EXISTS (SELECT 1 FROM core.data_subject_requests r
            WHERE r.id = e.request_id AND r.status IN ('completed','partially_completed','rejected')
                AND r.completed_at < CAST(:cutoff AS timestamptz))";
        return match ($category) {
            'audit' => ['audit_events', "e.occurred_at < CAST(:cutoff AS timestamptz)
                AND {$globalHold}"],
            'outbox' => ['outbox_events', "e.status IN ('published','dead')
                AND COALESCE(e.published_at,e.occurred_at) < CAST(:cutoff AS timestamptz)
                AND {$globalHold}"],
            'inbox' => ['inbox_events', "e.status IN ('processed','dead')
                AND COALESCE(e.processed_at,e.received_at) < CAST(:cutoff AS timestamptz)
                AND {$globalHold}"],
            'dsar_events' => ['data_subject_request_events',
                "e.occurred_at < CAST(:cutoff AS timestamptz)
                AND {$terminalRequest} AND " . $subjectHold($request)],
            'dsar_deadlines' => ['data_subject_request_deadlines',
                "e.created_at < CAST(:cutoff AS timestamptz)
                AND {$terminalRequest} AND " . $subjectHold($request)],
            'dsar_executions' => ['data_subject_request_executions',
                "e.created_at < CAST(:cutoff AS timestamptz)
                AND {$terminalRequest} AND " . $subjectHold($request)],
            'processing_blocks' => ['privacy_processing_blocks',
                "COALESCE(e.released_at,e.expires_at) < CAST(:cutoff AS timestamptz)
                AND (e.released_at IS NOT NULL OR e.expires_at < CAST(:now AS timestamptz))
                AND " . $subjectHold('e.subject_person_id')],
            'legal_holds' => ['privacy_legal_holds',
                "COALESCE(e.released_at,e.expires_at) < CAST(:cutoff AS timestamptz)
                AND NOT EXISTS (SELECT 1 FROM core.privacy_retention_decisions d WHERE d.legal_hold_id = e.id)
                AND " . $subjectHold('e.subject_person_id')],
            'retention_decisions' => ['privacy_retention_decisions',
                "e.evaluated_at < CAST(:cutoff AS timestamptz)
                AND " . $subjectHold('e.subject_person_id')],
            'auth_sessions' => ['auth_sessions',
                "COALESCE(e.revoked_at,e.expires_at) < CAST(:cutoff AS timestamptz)
                AND " . $subjectHold('(SELECT a.person_id FROM core.accounts a WHERE a.id = e.account_id)')],
            'destruction_evidence' => ['privacy_destruction_evidence',
                "e.evidence_retain_until < CAST(:now AS timestamptz)
                AND e.executed_at + interval '3 years' < CAST(:now AS timestamptz)
                AND NOT EXISTS (SELECT 1 FROM core.privacy_legal_destruction_packages p
                    WHERE p.evidence_id = e.id AND p.retain_until >= CAST(:now AS timestamptz))
                AND " . $subjectHold('(SELECT c.subject_person_id FROM core.privacy_erasure_cases c WHERE c.id = e.case_id)')],
            default => throw new DomainException('Unknown privacy cleanup category.'),
        };
    }
}
