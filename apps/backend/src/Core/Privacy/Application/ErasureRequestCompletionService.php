<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Audit\Application\AuditService;
use App\Core\Audit\Application\WriteAuditEventCommand;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Time\Application\ClockInterface;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class ErasureRequestCompletionService
{
    public function __construct(private ConnectionInterface $db, private ClockInterface $clock,
        private DataSubjectRequestStateMachine $states, private AuditService $audit) {}

    /** @param array{status:string,completed:int,blocked:int,failed:int,pending:int} $summary */
    public function completeIfReady(AuthenticatedIdentity $actor, string $caseId, array $summary): ?string
    {
        if ($summary['pending'] > 0 || $summary['completed'] === 0
            || !in_array($summary['status'], ['completed', 'partially_completed'], true)) {
            return null;
        }
        return $this->db->transaction(function () use ($actor, $caseId, $summary): ?string {
            $case = $this->db->createCommand(<<<'SQL'
                SELECT source_request_id::text FROM core.privacy_erasure_cases
                WHERE id = CAST(:case AS uuid) FOR UPDATE
                SQL, [':case' => $caseId])->queryOne();
            if (!is_array($case) || $case['source_request_id'] === null) {
                return null;
            }
            $requestId = (string) $case['source_request_id'];
            $request = $this->db->createCommand(<<<'SQL'
                SELECT request_type, status FROM core.data_subject_requests
                WHERE id = CAST(:id AS uuid) FOR UPDATE
                SQL, [':id' => $requestId])->queryOne();
            if (!is_array($request) || $request['request_type'] !== 'erasure') {
                throw new DomainException('Erasure source request is invalid.');
            }
            $existing = $this->db->createCommand(<<<'SQL'
                SELECT id::text FROM core.data_subject_request_executions
                WHERE request_id = CAST(:id AS uuid) AND execution_code = 'data_erasure'
                    AND status = 'completed' ORDER BY completed_at DESC LIMIT 1
                SQL, [':id' => $requestId])->queryScalar();
            if (is_string($existing) && in_array($request['status'], ['completed', 'partially_completed'], true)) {
                return $existing;
            }
            if ($request['status'] !== 'in_progress') {
                throw new DomainException('Erasure request cannot be completed in this state.');
            }
            $status = $summary['status'] === 'completed' ? 'completed' : 'partially_completed';
            $this->states->assertTransition('in_progress', $status);
            $now = $this->clock->now()->format(DATE_ATOM);
            $id = $this->db->createCommand(<<<'SQL'
                INSERT INTO core.data_subject_request_executions
                    (request_id, execution_code, status, actor_account_id, started_at, completed_at,
                        erasure_case_id, erasure_completed_items, erasure_blocked_items, erasure_failed_items)
                VALUES (CAST(:request AS uuid), 'data_erasure', 'completed', CAST(:actor AS uuid),
                    CAST(:now AS timestamptz), CAST(:now AS timestamptz), CAST(:case AS uuid),
                    :completed, :blocked, :failed) RETURNING id::text
                SQL, [':request' => $requestId, ':actor' => $actor->accountId, ':now' => $now,
                    ':case' => $caseId, ':completed' => $summary['completed'],
                    ':blocked' => $summary['blocked'], ':failed' => $summary['failed']])->queryScalar();
            if (!is_string($id)) {
                throw new DomainException('Erasure execution evidence could not be stored.');
            }
            $satisfied = $this->db->createCommand(<<<'SQL'
                UPDATE core.data_subject_request_deadlines SET satisfied_at = CAST(:now AS timestamptz)
                WHERE request_id = CAST(:request AS uuid) AND deadline_code = 'erasure_completion'
                    AND satisfied_at IS NULL
                SQL, [':request' => $requestId, ':now' => $now])->execute();
            if ($satisfied !== 1) {
                throw new DomainException('Approved erasure deadline is missing or already satisfied.');
            }
            $this->db->createCommand(<<<'SQL'
                UPDATE core.data_subject_requests SET status = :status, completed_at = CAST(:now AS timestamptz),
                    resolution_code = :resolution, updated_at = CAST(:now AS timestamptz)
                WHERE id = CAST(:request AS uuid)
                SQL, [':status' => $status, ':now' => $now,
                    ':resolution' => $status === 'completed' ? 'executed' : 'partially_executed',
                    ':request' => $requestId])->execute();
            $this->db->createCommand(<<<'SQL'
                INSERT INTO core.data_subject_request_events
                    (request_id, event_code, from_status, to_status, actor_account_id, occurred_at)
                VALUES (CAST(:request AS uuid), :event, 'in_progress', :status,
                    CAST(:actor AS uuid), CAST(:now AS timestamptz))
                SQL, [':request' => $requestId, ':event' => $status, ':status' => $status,
                    ':actor' => $actor->accountId, ':now' => $now])->execute();
            $this->audit->record(new WriteAuditEventCommand('account', $actor->accountId,
                'privacy.request.execution_completed', resourceType: 'core.data_subject_request',
                resourceId: $requestId, metadata: ['fromStatus' => 'in_progress', 'toStatus' => $status,
                    'executionCode' => 'data_erasure']));
            return $id;
        });
    }
}
