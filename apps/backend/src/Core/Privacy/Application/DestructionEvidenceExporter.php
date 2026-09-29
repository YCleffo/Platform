<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Time\Application\ClockInterface;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Technical act and journal data only; signing is an organizational step. */
final readonly class DestructionEvidenceExporter
{
    public function __construct(private ConnectionInterface $db, private ClockInterface $clock) {}

    public function export(string $caseId, int $limit = 1000, int $offset = 0): array
    {
        if ($limit < 1 || $limit > 1000 || $offset < 0) {
            throw new DomainException('Invalid destruction evidence page.');
        }
        $case = $this->db->createCommand(<<<'SQL'
            SELECT id::text, status, created_at, completed_at
            FROM core.privacy_erasure_cases WHERE id = CAST(:id AS uuid)
            SQL, [':id' => $caseId])->queryOne();
        if (!is_array($case)) {
            throw new DomainException('Erasure case not found.');
        }
        $total = (int) $this->db->createCommand(<<<'SQL'
            SELECT count(*) FROM core.privacy_destruction_evidence WHERE case_id = CAST(:id AS uuid)
            SQL, [':id' => $caseId])->queryScalar();
        $rows = $this->db->createCommand(<<<'SQL'
            SELECT id::text, item_id::text, resource_type, resource_reference_hash, policy_code,
                evidence_policy_code, evidence_retain_until, action, result, executed_at,
                executor_account_id::text, journal_event_id::text
            FROM core.privacy_destruction_evidence WHERE case_id = CAST(:id AS uuid)
            ORDER BY executed_at, id LIMIT :limit OFFSET :offset
            SQL, [':id' => $caseId, ':limit' => $limit, ':offset' => $offset])->queryAll();
        return [
            'schemaVersion' => 'privacy.destruction.v1',
            'kind' => 'technical_act_and_journal_export',
            'signed' => false,
            'caseId' => $caseId,
            'caseStatus' => $case['status'],
            'caseCreatedAt' => $case['created_at'],
            'caseCompletedAt' => $case['completed_at'],
            'generatedAt' => $this->clock->now()->format(DATE_ATOM),
            'totalEvidenceItems' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'items' => $rows,
        ];
    }
}
