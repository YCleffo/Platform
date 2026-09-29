<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Audit\Application\AuditService;
use App\Core\Audit\Application\WriteAuditEventCommand;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Domain\RetentionDecision;
use App\Core\Time\Application\ClockInterface;
use DomainException;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Core handlers execute in the item transaction; module handlers must reconcile by item ID. */
final readonly class ErasureExecutor
{
    public function __construct(private ConnectionInterface $db, private ClockInterface $clock,
        private RetentionCandidateContributorRegistry $candidates,
        private ProcessingPurposeRegistry $purposes,
        private RetentionDecisionService $decisions,
        private PersonalDataErasureHandlerRegistry $handlers,
        private DestructionEvidencePolicy $evidencePolicy,
        private ErasureCaseStateMachine $states,
        private AuditService $audit,
        private ErasureRequestCompletionService $requests,
        private ?LegalDestructionPackageService $legalPackages = null) {}

    /** @return array{status:string,completed:int,blocked:int,failed:int,pending:int} */
    public function executeBatch(AuthenticatedIdentity $actor, string $caseId, int $limit = 25): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new DomainException('Erasure batch limit must be between 1 and 100.');
        }
        $key = 'privacy.erasure.execute:' . $caseId;
        $this->db->createCommand('SELECT pg_advisory_lock(hashtextextended(:key, 0))', [':key' => $key])->queryScalar();
        try {
            $case = $this->db->createCommand('SELECT id::text, status FROM core.privacy_erasure_cases WHERE id = CAST(:id AS uuid)',
                [':id' => $caseId])->queryOne();
            if (!is_array($case)) {
                throw new DomainException('Erasure case not found.');
            }
            if ($case['status'] === 'completed') {
                $summary = $this->summary($caseId);
                $this->requests->completeIfReady($actor, $caseId, $summary);
                return $summary;
            }
            $items = $this->db->createCommand(<<<'SQL'
                SELECT id::text FROM core.privacy_erasure_items WHERE case_id = CAST(:case AS uuid)
                    AND status <> 'completed' ORDER BY attempts, id LIMIT :limit
                SQL, [':case' => $caseId, ':limit' => $limit])->queryAll();
            foreach ($items as $item) {
                try {
                    $this->executeItem($actor, $caseId, (string) $item['id']);
                } catch (\Throwable) {
                    $this->markFailed($actor, $caseId, (string) $item['id']);
                }
            }
            $summary = $this->refreshCase($actor, $caseId);
            $this->requests->completeIfReady($actor, $caseId, $summary);
            return $summary;
        } finally {
            $this->db->createCommand('SELECT pg_advisory_unlock(hashtextextended(:key, 0))', [':key' => $key])->queryScalar();
        }
    }

    private function executeItem(AuthenticatedIdentity $actor, string $caseId, string $itemId): void
    {
        $this->db->transaction(function () use ($actor, $caseId, $itemId): void {
            $case = $this->db->createCommand(<<<'SQL'
                SELECT subject_person_id::text, source_request_id::text, status, started_at
                FROM core.privacy_erasure_cases WHERE id = CAST(:id AS uuid) FOR UPDATE
                SQL, [':id' => $caseId])->queryOne();
            $item = $this->db->createCommand(<<<'SQL'
                SELECT resource_type, resource_id::text, policy_code, planned_action, status
                FROM core.privacy_erasure_items WHERE id = CAST(:id AS uuid)
                    AND case_id = CAST(:case AS uuid) FOR UPDATE
                SQL, [':id' => $itemId, ':case' => $caseId])->queryOne();
            if (!is_array($case) || !is_array($item) || $case['status'] === 'completed') {
                throw new DomainException('Erasure case or item unavailable.');
            }
            if ($item['status'] === 'completed') {
                return;
            }
            $subject = (string) $case['subject_person_id'];
            $this->db->createCommand('SELECT id FROM core.persons WHERE id = CAST(:id AS uuid) FOR UPDATE',
                [':id' => $subject])->queryScalar();
            if ($this->legalPackages !== null && !$this->legalPackages->available()) {
                $this->block($actor, $itemId, 'legal_evidence_config_missing', (string) $item['resource_type']);
                return;
            }
            $subjectName = $this->legalPackages?->subjectName($caseId, $subject);
            $now = $this->clock->now()->format(DATE_ATOM);
            if ($case['started_at'] === null) {
                $this->audit($actor, 'privacy.erasure.started', $caseId, []);
            }
            $this->states->assertTransition((string) $case['status'], 'in_progress');
            $this->db->createCommand(<<<'SQL'
                UPDATE core.privacy_erasure_cases SET status = 'in_progress',
                    started_at = COALESCE(started_at, CAST(:now AS timestamptz)),
                    updated_at = CAST(:now AS timestamptz) WHERE id = CAST(:id AS uuid)
                SQL, [':now' => $now, ':id' => $caseId])->execute();
            $this->db->createCommand(<<<'SQL'
                UPDATE core.privacy_erasure_items SET status = 'in_progress', attempts = attempts + 1,
                    started_at = CAST(:now AS timestamptz), completed_at = NULL, failure_code = NULL,
                    updated_at = CAST(:now AS timestamptz) WHERE id = CAST(:id AS uuid)
                SQL, [':now' => $now, ':id' => $itemId])->execute();
            $handler = $item['planned_action'] === null ? null
                : $this->handlers->forAction((string) $item['resource_type'], (string) $item['planned_action']);
            if ($handler !== null) {
                $reconciled = $handler->reconcile($itemId);
                if ($reconciled !== null) {
                    $this->finishItem($actor, $caseId, $itemId, $item, $reconciled, $subjectName);
                    return;
                }
            }
            if (!$this->evidencePolicy->available()) {
                $this->block($actor, $itemId, 'evidence_policy_missing', (string) $item['resource_type']);
                return;
            }
            if (!$this->candidates->coversAll($this->purposes->codes())) {
                $this->block($actor, $itemId, 'candidate_coverage_missing', (string) $item['resource_type']);
                return;
            }
            $all = $this->candidates->collect(1001, $subject);
            if (count($all) > 1000) {
                $this->block($actor, $itemId, 'candidate_batch_exceeded', (string) $item['resource_type']);
                return;
            }
            $plannedResources = $this->db->createCommand(<<<'SQL'
                SELECT resource_type, resource_id::text FROM core.privacy_erasure_items
                WHERE case_id = CAST(:case AS uuid)
                SQL, [':case' => $caseId])->queryAll();
            $plannedKeys = [];
            foreach ($plannedResources as $plannedResource) {
                $plannedKeys[$plannedResource['resource_type'] . ':' . $plannedResource['resource_id']] = true;
            }
            foreach ($all as $candidate) {
                if (!isset($plannedKeys[$candidate->resourceType . ':' . $candidate->resourceId])) {
                    $this->block($actor, $itemId, 'candidate_set_changed', (string) $item['resource_type']);
                    return;
                }
            }
            $group = array_values(array_filter($all, static fn($candidate): bool =>
                $candidate->resourceType === $item['resource_type'] && $candidate->resourceId === $item['resource_id']));
            if ($group === []) {
                $this->block($actor, $itemId, 'candidate_missing', (string) $item['resource_type']);
                return;
            }
            $evaluated = $this->decisions->evaluateResource($group, $actor,
                $case['source_request_id'] === null ? null : (string) $case['source_request_id']);
            $action = ErasureCaseService::actionFor($evaluated['aggregate']);
            if ($action === null) {
                $this->block($actor, $itemId, self::retentionReason($evaluated['decisions']),
                    (string) $item['resource_type']);
                return;
            }
            $policy = ErasureCaseService::policyFor($group, $evaluated['decisions'], $evaluated['aggregate']);
            if (($item['planned_action'] !== null && $item['planned_action'] !== $action)
                || $item['policy_code'] !== $policy) {
                $this->block($actor, $itemId, 'plan_changed', (string) $item['resource_type']);
                return;
            }
            $handler = $this->handlers->forAction((string) $item['resource_type'], $action);
            if ($handler === null) {
                $this->block($actor, $itemId, 'unsupported_handler', (string) $item['resource_type']);
                return;
            }
            if (!$handler->owns($subject, (string) $item['resource_id'])) {
                $this->block($actor, $itemId, 'ownership_unverified', (string) $item['resource_type']);
                return;
            }
            $result = $handler->execute($subject, (string) $item['resource_id'], $action, $itemId);
            $this->db->createCommand(<<<'SQL'
                UPDATE core.privacy_erasure_items SET planned_action = :action, policy_code = :policy
                WHERE id = CAST(:id AS uuid)
                SQL, [':action' => $action, ':policy' => $policy, ':id' => $itemId])->execute();
            $item['planned_action'] = $action;
            $item['policy_code'] = $policy;
            $this->finishItem($actor, $caseId, $itemId, $item, $result, $subjectName);
        });
    }

    private function finishItem(AuthenticatedIdentity $actor, string $caseId, string $itemId,
        array $item, string $result, ?string $subjectName): void
    {
        $expected = match ($item['planned_action']) {
            'delete' => 'deleted', 'anonymize' => 'anonymized',
            'pseudonymize' => 'pseudonymized', 'custom' => 'custom_completed',
            default => throw new DomainException('Erasure action is missing.'),
        };
        if ($result !== $expected) {
            throw new DomainException('Erasure handler returned the wrong result.');
        }
        $now = $this->clock->now();
        $receipt = $this->audit->record(new WriteAuditEventCommand('account', $actor->accountId,
            'privacy.erasure.item_completed', resourceType: 'core.privacy_erasure_item',
            resourceId: $itemId, metadata: ['resourceType' => $item['resource_type'],
                'policyCode' => $item['policy_code'], 'action' => $item['planned_action'], 'result' => $result]));
        $evidenceId = $this->db->createCommand(<<<'SQL'
            INSERT INTO core.privacy_destruction_evidence
                (case_id, item_id, resource_type, resource_reference_hash, policy_code,
                    evidence_policy_code, evidence_retain_until, action, result, executed_at,
                    executor_account_id, journal_event_id)
            VALUES (CAST(:case AS uuid), CAST(:item AS uuid), :type, :hash, :policy,
                :evidence_policy, CAST(:retain_until AS timestamptz), :action, :result,
                CAST(:now AS timestamptz), CAST(:actor AS uuid), CAST(:event AS uuid))
            RETURNING id::text
            SQL, [':case' => $caseId, ':item' => $itemId, ':type' => $item['resource_type'],
                ':hash' => $this->evidencePolicy->referenceHash((string) $item['resource_type'],
                    (string) $item['resource_id']), ':policy' => $item['policy_code'],
                ':evidence_policy' => $this->evidencePolicy->code(),
                ':retain_until' => $this->evidencePolicy->retainUntil($now)->format(DATE_ATOM),
                ':action' => $item['planned_action'], ':result' => $result,
                ':now' => $now->format(DATE_ATOM), ':actor' => $actor->accountId,
                ':event' => $receipt->eventId])->queryScalar();
        if ($this->legalPackages !== null) {
            if (!is_string($evidenceId) || $subjectName === null) {
                throw new DomainException('Legal destruction evidence was not recorded.');
            }
            $subjectId = $this->db->createCommand('SELECT subject_person_id::text FROM core.privacy_erasure_cases WHERE id = CAST(:id AS uuid)',
                [':id' => $caseId])->queryScalar();
            if (!is_string($subjectId)) { throw new DomainException('Erasure subject unavailable.'); }
            $this->legalPackages->record($evidenceId, $caseId, $itemId, $subjectId, $subjectName,
                $actor->accountId, $receipt->eventId, $now,
                $this->evidencePolicy->retainUntil($now));
        }
        $this->db->createCommand(<<<'SQL'
            UPDATE core.privacy_erasure_items SET status = 'completed', completed_at = CAST(:now AS timestamptz),
                failure_code = NULL, updated_at = CAST(:now AS timestamptz) WHERE id = CAST(:id AS uuid)
            SQL, [':id' => $itemId, ':now' => $now->format(DATE_ATOM)])->execute();
    }

    private function block(AuthenticatedIdentity $actor, string $itemId, string $reason,
        string $resourceType): void
    {
        $this->db->createCommand(<<<'SQL'
            UPDATE core.privacy_erasure_items SET status = 'blocked', failure_code = :reason,
                updated_at = CAST(:now AS timestamptz) WHERE id = CAST(:id AS uuid)
            SQL, [':id' => $itemId, ':reason' => $reason,
                ':now' => $this->clock->now()->format(DATE_ATOM)])->execute();
        $this->audit($actor, 'privacy.erasure.item_blocked', $itemId,
            ['reasonCode' => $reason, 'resourceType' => $resourceType]);
    }

    private function markFailed(AuthenticatedIdentity $actor, string $caseId, string $itemId): void
    {
        $this->db->transaction(function () use ($actor, $caseId, $itemId): void {
            $row = $this->db->createCommand(<<<'SQL'
                SELECT status FROM core.privacy_erasure_items WHERE id = CAST(:id AS uuid)
                    AND case_id = CAST(:case AS uuid) FOR UPDATE
                SQL, [':id' => $itemId, ':case' => $caseId])->queryOne();
            if (!is_array($row) || $row['status'] === 'completed') {
                return;
            }
            $this->db->createCommand(<<<'SQL'
                UPDATE core.privacy_erasure_items SET status = 'failed', attempts = attempts + 1,
                    failure_code = 'handler_failed', started_at = CAST(:now AS timestamptz),
                    updated_at = CAST(:now AS timestamptz) WHERE id = CAST(:id AS uuid)
                SQL, [':id' => $itemId, ':now' => $this->clock->now()->format(DATE_ATOM)])->execute();
            $this->audit($actor, 'privacy.erasure.failed', $itemId, ['reasonCode' => 'handler_failed']);
        });
    }

    /** @return array{status:string,completed:int,blocked:int,failed:int,pending:int} */
    private function refreshCase(AuthenticatedIdentity $actor, string $caseId): array
    {
        return $this->db->transaction(function () use ($actor, $caseId): array {
            $case = $this->db->createCommand('SELECT status, started_at FROM core.privacy_erasure_cases WHERE id = CAST(:id AS uuid) FOR UPDATE',
                [':id' => $caseId])->queryOne();
            if (!is_array($case)) {
                throw new DomainException('Erasure case not found.');
            }
            $counts = $this->counts($caseId);
            $status = $counts['pending'] === 0 && $counts['blocked'] === 0 && $counts['failed'] === 0
                ? 'completed' : ($counts['completed'] > 0 ? 'partially_completed'
                    : ($counts['pending'] > 0 ? 'in_progress' : ($counts['blocked'] > 0 ? 'blocked' : 'failed')));
            $this->states->assertTransition((string) $case['status'], $status);
            $now = $this->clock->now()->format(DATE_ATOM);
            $this->db->createCommand(<<<'SQL'
                UPDATE core.privacy_erasure_cases SET status = :status,
                    completed_at = CASE WHEN :is_complete THEN CAST(:completed_now AS timestamptz) ELSE NULL END,
                    updated_at = CAST(:now AS timestamptz) WHERE id = CAST(:id AS uuid)
                SQL, [':status' => $status, ':is_complete' => $status === 'completed',
                    ':completed_now' => $now, ':now' => $now, ':id' => $caseId])->execute();
            if ($status === 'completed' && $case['status'] !== 'completed') {
                $this->audit($actor, 'privacy.erasure.completed', $caseId, []);
            }
            return ['status' => $status, ...$counts];
        });
    }

    /** @return array{status:string,completed:int,blocked:int,failed:int,pending:int} */
    private function summary(string $caseId): array
    {
        $status = $this->db->createCommand('SELECT status FROM core.privacy_erasure_cases WHERE id = CAST(:id AS uuid)',
            [':id' => $caseId])->queryScalar();
        if (!is_string($status)) {
            throw new DomainException('Erasure case not found.');
        }
        return ['status' => $status, ...$this->counts($caseId)];
    }

    /** @return array{completed:int,blocked:int,failed:int,pending:int} */
    private function counts(string $caseId): array
    {
        $rows = $this->db->createCommand(<<<'SQL'
            SELECT status, count(*) AS total FROM core.privacy_erasure_items
            WHERE case_id = CAST(:case AS uuid) GROUP BY status
            SQL, [':case' => $caseId])->queryAll();
        $counts = ['completed' => 0, 'blocked' => 0, 'failed' => 0, 'pending' => 0];
        foreach ($rows as $row) {
            $key = in_array($row['status'], ['planned', 'in_progress'], true)
                ? 'pending' : (string) $row['status'];
            $counts[$key] += (int) $row['total'];
        }
        return $counts;
    }

    /** @param list<RetentionDecision> $decisions */
    private static function retentionReason(array $decisions): string
    {
        foreach (['blocked_by_legal_hold', 'blocked_by_active_basis', 'blocked_by_unknown_basis',
            'blocked_by_missing_policy', 'blocked_by_dependency', 'not_yet_eligible'] as $code) {
            foreach ($decisions as $decision) {
                if ($decision->code === $code) {
                    return $code;
                }
            }
        }
        return 'retention_blocked';
    }

    /** @param array<string, mixed> $metadata */
    private function audit(AuthenticatedIdentity $actor, string $action, string $id, array $metadata): void
    {
        $this->audit->record(new WriteAuditEventCommand('account', $actor->accountId, $action,
            resourceType: 'core.privacy_erasure', resourceId: $id, metadata: $metadata));
    }
}
