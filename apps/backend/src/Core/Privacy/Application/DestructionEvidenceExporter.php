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
            SELECT e.id::text, e.item_id::text, e.resource_type, e.resource_reference_hash, e.policy_code,
                e.evidence_policy_code, e.evidence_retain_until, e.action, e.result, e.executed_at,
                e.executor_account_id::text, e.journal_event_id::text,
                p.subject_person_id::text AS subject_person_id, p.subject_name,
                p.operator_name, p.operator_address, p.processor_name, p.processor_address,
                array_to_json(p.category_codes)::text AS category_codes_json,
                p.information_system_name, p.information_system_code, p.destruction_method,
                p.destruction_reason_code, p.document_reference,
                s.signed_document_reference, s.signature_reference,
                p.retain_until
            FROM core.privacy_destruction_evidence e
            LEFT JOIN core.privacy_legal_destruction_packages p ON p.evidence_id = e.id
            LEFT JOIN core.privacy_legal_destruction_signatures s ON s.package_id = p.id
            WHERE e.case_id = CAST(:id AS uuid)
            ORDER BY e.executed_at, e.id LIMIT :limit OFFSET :offset
            SQL, [':id' => $caseId, ':limit' => $limit, ':offset' => $offset])->queryAll();
        $items = [];
        foreach ($rows as $row) {
            $act = $row['document_reference'] === null ? null : [
                'documentReference' => $row['signed_document_reference'] ?? $row['document_reference'],
                'signatureReference' => $row['signature_reference'],
                'signed' => $row['signature_reference'] !== null,
                'operator' => ['name' => $row['operator_name'], 'address' => $row['operator_address']],
                'processor' => $row['processor_name'] === null ? null
                    : ['name' => $row['processor_name'], 'address' => $row['processor_address']],
                'subject' => ['personId' => $row['subject_person_id'], 'name' => $row['subject_name']],
                'dataCategories' => json_decode((string) $row['category_codes_json'], true, flags: JSON_THROW_ON_ERROR),
                'informationSystem' => ['name' => $row['information_system_name'], 'code' => $row['information_system_code']],
                'method' => $row['destruction_method'], 'reasonCode' => $row['destruction_reason_code'],
                'destroyedAt' => $row['executed_at'], 'responsibleExecutorAccountId' => $row['executor_account_id'],
                'retainUntil' => $row['retain_until'],
            ];
            $items[] = [
                'evidenceId' => $row['id'], 'itemId' => $row['item_id'], 'result' => $row['result'],
                'act' => $act,
                'journalExport' => $act === null ? null : [
                    'eventId' => $row['journal_event_id'],
                    'informationSystemCode' => $row['information_system_code'],
                    'subjectPersonId' => $row['subject_person_id'],
                    'dataCategories' => $act['dataCategories'],
                    'destructionMethod' => $row['destruction_method'],
                    'executedAt' => $row['executed_at'],
                    'result' => $row['result'],
                ],
            ];
        }
        return [
            'schemaVersion' => 'privacy.destruction.v2',
            'kind' => 'legal_act_and_system_journal_export',
            'signed' => false,
            'caseId' => $caseId,
            'caseStatus' => $case['status'],
            'caseCreatedAt' => $case['created_at'],
            'caseCompletedAt' => $case['completed_at'],
            'generatedAt' => $this->clock->now()->format(DATE_ATOM),
            'totalEvidenceItems' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'items' => $items,
        ];
    }
}
