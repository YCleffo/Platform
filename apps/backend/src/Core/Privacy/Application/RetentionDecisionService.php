<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Audit\Application\AuditService;
use App\Core\Audit\Application\WriteAuditEventCommand;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Domain\RetentionCandidate;
use App\Core\Privacy\Domain\RetentionDecision;
use App\Core\Time\Application\ClockInterface;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class RetentionDecisionService
{
    public function __construct(private ConnectionInterface $db, private RetentionEvaluator $evaluator,
        private ClockInterface $clock, private AuditService $audit) {}

    /** @param list<RetentionCandidate> $candidates @return array{decisions:list<RetentionDecision>,aggregate:string} */
    public function evaluateResource(array $candidates, ?AuthenticatedIdentity $actor = null,
        ?string $sourceRequestId = null): array
    {
        if ($candidates === []) {
            return ['decisions' => [], 'aggregate' => 'retain'];
        }
        $first = $candidates[0];
        foreach ($candidates as $candidate) {
            if ($candidate->subjectPersonId !== $first->subjectPersonId
                || $candidate->resourceType !== $first->resourceType || $candidate->resourceId !== $first->resourceId) {
                throw new DomainException('Retention batch mixes resources.');
            }
        }
        return $this->db->transaction(function () use ($candidates, $first, $actor, $sourceRequestId): array {
            $this->db->createCommand('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))',
                [':key' => implode(':', [$first->subjectPersonId, $first->resourceType, $first->resourceId])])->queryScalar();
            $decisions = array_map($this->evaluator->evaluate(...), $candidates);
            $aggregate = $this->evaluator->aggregate($decisions);
            foreach ($decisions as $decision) {
                $this->persist($decision, $actor, $sourceRequestId);
            }
            return ['decisions' => $decisions, 'aggregate' => $aggregate];
        });
    }

    private function persist(RetentionDecision $decision, ?AuthenticatedIdentity $actor, ?string $sourceRequestId): void
    {
        $candidate = $decision->candidate;
        $params = [':subject' => $candidate->subjectPersonId, ':resource_type' => $candidate->resourceType,
            ':resource_id' => $candidate->resourceId, ':purpose' => $candidate->purposeCode,
            ':policy' => $candidate->retentionPolicyCode, ':code' => $decision->code,
            ':trigger' => $candidate->triggerCode, ':triggered' => $candidate->triggeredAt?->format(DATE_ATOM),
            ':eligible' => $decision->eligibleAt?->format(DATE_ATOM), ':hold' => $decision->legalHoldId,
            ':request' => $sourceRequestId, ':now' => $this->clock->now()->format(DATE_ATOM), ':version' => 'b3.1'];
        $previous = $this->db->createCommand(<<<'SQL'
            SELECT decision_code, (policy_code = :policy AND decision_code = :code
                AND trigger_code = :trigger
                AND triggered_at IS NOT DISTINCT FROM CAST(:triggered AS timestamptz)
                AND eligible_at IS NOT DISTINCT FROM CAST(:eligible AS timestamptz)
                AND legal_hold_id IS NOT DISTINCT FROM CAST(:hold AS uuid)
                AND source_request_id IS NOT DISTINCT FROM CAST(:request AS uuid)) AS same
            FROM core.privacy_retention_decisions
            WHERE subject_person_id = CAST(:subject AS uuid) AND resource_type = :resource_type
                AND resource_id = CAST(:resource_id AS uuid) AND purpose_code = :purpose
            ORDER BY evaluated_at DESC, id DESC LIMIT 1
            SQL, array_diff_key($params, array_flip([':now', ':version'])))->queryOne();
        if (is_array($previous) && in_array($previous['same'], [true, 't', 1], true)) {
            return;
        }
        $id = $this->db->createCommand(<<<'SQL'
            INSERT INTO core.privacy_retention_decisions (subject_person_id, resource_type, resource_id,
                purpose_code, policy_code, decision_code, trigger_code, triggered_at, eligible_at,
                legal_hold_id, source_request_id, evaluated_at, evaluator_version)
            VALUES (CAST(:subject AS uuid), :resource_type, CAST(:resource_id AS uuid), :purpose, :policy,
                :code, :trigger, CAST(:triggered AS timestamptz), CAST(:eligible AS timestamptz),
                CAST(:hold AS uuid), CAST(:request AS uuid), CAST(:now AS timestamptz), :version)
            RETURNING id::text
            SQL, $params)->queryScalar();
        if (!is_string($id)) {
            throw new DomainException('Retention decision could not be saved.');
        }
        $metadata = ['purposeCode' => $candidate->purposeCode,
            'policyCode' => $candidate->retentionPolicyCode, 'decisionCode' => $decision->code];
        foreach (['privacy.retention.evaluated', ...(is_array($previous)
            && $previous['decision_code'] !== $decision->code ? ['privacy.retention.decision_changed'] : [])] as $action) {
            $this->audit->record(new WriteAuditEventCommand($actor === null ? 'system' : 'account',
                $actor?->accountId, $action, resourceType: 'core.privacy_retention_decision',
                resourceId: $id, metadata: $metadata));
        }
    }
}
