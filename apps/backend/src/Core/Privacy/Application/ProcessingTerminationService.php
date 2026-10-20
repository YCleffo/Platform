<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Audit\Application\AuditService;
use App\Core\Audit\Application\WriteAuditEventCommand;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Time\Application\ClockInterface;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Executes only restriction effects; retention decisions never destroy data. */
final readonly class ProcessingTerminationService
{
    public function __construct(private ConnectionInterface $db, private ClockInterface $clock,
        private RetentionCandidateContributorRegistry $candidates,
        private RetentionDecisionService $decisions, private LawfulProcessingBasisResolver $bases,
        private ProcessingBlockService $blocks,
        private ProcessingRestrictionContributorRegistry $restrictions,
        private DataSubjectRequestStateMachine $states,
        private AuditService $audit,
        private ProcessingPurposeRegistry $purposes) {}

    /** @return array{status:string,executionId:string} */
    public function execute(AuthenticatedIdentity $actor, string $requestId): array
    {
        $key = 'privacy.termination:' . $requestId;
        $this->db->createCommand('SELECT pg_advisory_lock(hashtextextended(:key, 0))', [':key' => $key])->queryScalar();
        try {
            return $this->executeLocked($actor, $requestId);
        } finally {
            $this->db->createCommand('SELECT pg_advisory_unlock(hashtextextended(:key, 0))', [':key' => $key])->queryScalar();
        }
    }

    /** @return array{status:string,executionId:string} */
    private function executeLocked(AuthenticatedIdentity $actor, string $requestId): array
    {
        $request = $this->db->createCommand(<<<'SQL'
            SELECT subject_person_id::text, request_type, status FROM core.data_subject_requests
            WHERE id = CAST(:id AS uuid)
            SQL, [':id' => $requestId])->queryOne();
        if (!is_array($request) || $request['request_type'] !== 'processing_termination') {
            throw new DomainException('Processing termination request not found.');
        }
        $existing = $this->db->createCommand(<<<'SQL'
            SELECT id::text FROM core.data_subject_request_executions
            WHERE request_id = CAST(:id AS uuid) AND execution_code = 'processing_termination'
                AND status = 'completed' ORDER BY completed_at DESC LIMIT 1
            SQL, [':id' => $requestId])->queryScalar();
        if (is_string($existing) && in_array($request['status'], ['completed', 'partially_completed'], true)) {
            return ['status' => $request['status'], 'executionId' => $existing];
        }
        if ($request['status'] !== 'in_progress') {
            throw new DomainException('Processing termination is not in progress.');
        }
        $subject = (string) $request['subject_person_id'];
        $this->recordAudit($actor, 'privacy.processing_termination.started', $requestId);
        try {
            if (!$this->candidates->coversAll($this->purposes->codes())) {
                throw new DomainException('Processing purpose candidate coverage is incomplete.');
            }
            $candidates = $this->candidates->collect(1001, $subject);
            if ($candidates === []) {
                throw new DomainException('No retention candidates cover this request.');
            }
            if (count($candidates) > 1000) {
                throw new DomainException('Processing termination candidate set exceeds safe batch.');
            }
            $groups = [];
            $stopped = [];
            $retained = [];
            $reasons = [];
            foreach ($candidates as $candidate) {
                $groups[implode(':', [$candidate->resourceType, $candidate->resourceId])][] = $candidate;
                $basis = $this->bases->resolve($candidate);
                if ($basis === 'unknown') {
                    throw new DomainException('Unknown lawful processing basis.');
                }
                if ($basis === 'active') {
                    $retained[$candidate->purposeCode] = true;
                    unset($stopped[$candidate->purposeCode]);
                    $reasons['active_basis'] = true;
                } elseif (!isset($retained[$candidate->purposeCode])) {
                    $stopped[$candidate->purposeCode] = true;
                }
            }
            if (count($stopped) + count($retained) > 100) {
                throw new DomainException('Processing termination purpose set exceeds evidence limit.');
            }
            foreach ($groups as $group) {
                $this->decisions->evaluateResource($group, $actor, $requestId);
            }
            foreach (array_keys($stopped) as $purposeCode) {
                $contributors = $this->restrictions->forScope('purpose', $purposeCode, null);
                if ($contributors === []) {
                    throw new DomainException('Processing restriction contributor missing.');
                }
                $blockId = $this->blocks->createBlock($actor, $subject, 'purpose', $purposeCode,
                    null, null, 'processing_termination', $requestId);
                foreach ($contributors as $contributor) {
                    $contributor->applyRestriction($blockId, $subject);
                }
            }
            $stoppedCodes = array_keys($stopped);
            $retainedCodes = array_keys($retained);
            $reasonCodes = array_keys($reasons);
            sort($stoppedCodes);
            sort($retainedCodes);
            sort($reasonCodes);
            return $this->db->transaction(function () use ($actor, $requestId, $stoppedCodes,
                $retainedCodes, $reasonCodes): array {
                $row = $this->db->createCommand(<<<'SQL'
                    SELECT status FROM core.data_subject_requests WHERE id = CAST(:id AS uuid) FOR UPDATE
                    SQL, [':id' => $requestId])->queryOne();
                if (!is_array($row) || $row['status'] !== 'in_progress') {
                    throw new DomainException('Request changed during processing termination.');
                }
                $status = $retainedCodes === [] ? 'completed' : 'partially_completed';
                $this->states->assertTransition('in_progress', $status);
                $now = $this->clock->now()->format(DATE_ATOM);
                $id = $this->db->createCommand(<<<'SQL'
                    INSERT INTO core.data_subject_request_executions
                        (request_id, execution_code, status, actor_account_id, started_at, completed_at,
                            stopped_purpose_codes, retained_purpose_codes, reason_codes)
                    VALUES (CAST(:request AS uuid), 'processing_termination', 'completed', CAST(:actor AS uuid),
                        CAST(:now AS timestamptz), CAST(:now AS timestamptz),
                        CAST(:stopped AS text[]), CAST(:retained AS text[]), CAST(:reasons AS text[])) RETURNING id::text
                    SQL, [':request' => $requestId, ':actor' => $actor->accountId, ':now' => $now,
                        ':stopped' => '{' . implode(',', $stoppedCodes) . '}',
                        ':retained' => '{' . implode(',', $retainedCodes) . '}',
                        ':reasons' => '{' . implode(',', $reasonCodes) . '}'])->queryScalar();
                if (!is_string($id)) {
                    throw new DomainException('Termination execution evidence could not be saved.');
                }
                $updated = $this->db->createCommand(<<<'SQL'
                    UPDATE core.data_subject_request_deadlines SET satisfied_at = CAST(:now AS timestamptz)
                    WHERE request_id = CAST(:request AS uuid) AND deadline_code = 'processing_termination'
                        AND satisfied_at IS NULL
                    SQL, [':request' => $requestId, ':now' => $now])->execute();
                if ($updated !== 1) {
                    throw new DomainException('Processing termination deadline is missing or satisfied.');
                }
                $this->db->createCommand(<<<'SQL'
                    UPDATE core.data_subject_requests SET status = :status, completed_at = CAST(:now AS timestamptz),
                        resolution_code = :resolution, updated_at = CAST(:now AS timestamptz)
                    WHERE id = CAST(:request AS uuid)
                    SQL, [':status' => $status, ':now' => $now, ':resolution' => $status === 'completed'
                        ? 'executed' : 'partially_executed', ':request' => $requestId])->execute();
                $this->db->createCommand(<<<'SQL'
                    INSERT INTO core.data_subject_request_events
                        (request_id, event_code, from_status, to_status, actor_account_id, occurred_at)
                    VALUES (CAST(:request AS uuid), :event, 'in_progress', :status,
                        CAST(:actor AS uuid), CAST(:now AS timestamptz))
                    SQL, [':request' => $requestId, ':event' => $status, ':status' => $status,
                        ':actor' => $actor->accountId, ':now' => $now])->execute();
                $this->recordAudit($actor, 'privacy.processing_termination.' . $status, $requestId,
                    ['stoppedPurposeCodes' => $stoppedCodes, 'retainedPurposeCodes' => $retainedCodes]);
                return ['status' => $status, 'executionId' => $id];
            });
        } catch (\Throwable $error) {
            $this->recordAudit($actor, 'privacy.processing_termination.failed', $requestId,
                ['reasonCode' => 'execution_failed']);
            throw $error;
        }
    }

    private function recordAudit(AuthenticatedIdentity $actor, string $action, string $requestId,
        array $metadata = []): void
    {
        $this->audit->record(new WriteAuditEventCommand('account', $actor->accountId, $action,
            resourceType: 'core.data_subject_request', resourceId: $requestId, metadata: $metadata));
    }
}
