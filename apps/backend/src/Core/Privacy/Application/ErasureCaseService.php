<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Audit\Application\AuditService;
use App\Core\Audit\Application\WriteAuditEventCommand;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Domain\MachineCode;
use App\Core\Privacy\Domain\RetentionCandidate;
use App\Core\Time\Application\ClockInterface;
use DateTimeImmutable;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class ErasureCaseService
{
    public function __construct(
        private ConnectionInterface $db,
        private ClockInterface $clock,
        private RetentionCandidateContributorRegistry $candidates,
        private ProcessingPurposeRegistry $purposes,
        private RetentionDecisionService $decisions,
        private PersonalDataErasureHandlerRegistry $handlers,
        private AuditService $audit,
        private ?CorePrivacyContributorCoverage $coverage = null,
    ) {}

    public function create(
        AuthenticatedIdentity $actor,
        string $subjectPersonId,
        string $reasonCode,
        ?string $sourceRequestId = null,
        ?DateTimeImmutable $deadlineAt = null,
        ?string $deadlineCalendarType = null,
    ): string {
        MachineCode::assert($reasonCode);
        if ($sourceRequestId !== null && ($deadlineAt === null
            || !in_array($deadlineCalendarType, ['calendar_days', 'working_days', 'hours'], true))) {
            throw new DomainException('Erasure request requires an approved deadline.');
        }
        if ($sourceRequestId !== null) {
            $existing = $this->db->createCommand(
                'SELECT id::text FROM core.privacy_erasure_cases WHERE source_request_id = CAST(:id AS uuid) AND subject_person_id = CAST(:subject AS uuid)',
                [':id' => $sourceRequestId, ':subject' => $subjectPersonId],
            )->queryScalar();
            if (is_string($existing)) {
                return $existing;
            }
        }
        if (!$this->candidates->available() || !$this->candidates->coversAll($this->purposes->codes())
            || $this->purposes->codes() === []) {
            throw new DomainException('Erasure candidate coverage is incomplete.');
        }
        $all = $this->candidates->collect(1001, $subjectPersonId);
        if ($all === [] || count($all) > 1000) {
            throw new DomainException('Erasure candidate set is missing or exceeds safe batch.');
        }
        if ($sourceRequestId !== null && $this->coverage !== null
            && $this->coverage->missingForSubject($subjectPersonId, $all) !== []) {
            throw new DomainException('Erasure contributor coverage is incomplete for subject.');
        }
        $groups = [];
        foreach ($all as $candidate) {
            $groups[$candidate->resourceType . ':' . $candidate->resourceId][] = $candidate;
        }
        return $this->db->transaction(function () use (
            $actor,
            $subjectPersonId,
            $reasonCode,
            $sourceRequestId,
            $deadlineAt,
            $deadlineCalendarType,
            $groups
        ): string {
            $now = $this->clock->now();
            $this->db->createCommand(
                'SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))',
                [':key' => 'privacy.erasure.plan:' . ($sourceRequestId ?? $subjectPersonId)],
            )->queryScalar();
            $subject = $this->db->createCommand(
                'SELECT id FROM core.persons WHERE id = CAST(:id AS uuid) FOR SHARE',
                [':id' => $subjectPersonId],
            )->queryScalar();
            if ($subject === false || $subject === null) {
                throw new DomainException('Erasure subject not found.');
            }
            if ($sourceRequestId !== null) {
                $existing = $this->db->createCommand(
                    'SELECT id::text FROM core.privacy_erasure_cases WHERE source_request_id = CAST(:id AS uuid)',
                    [':id' => $sourceRequestId],
                )->queryScalar();
                if (is_string($existing)) {
                    return $existing;
                }
                $request = $this->db->createCommand(<<<'SQL'
                    SELECT status FROM core.data_subject_requests
                    WHERE id = CAST(:request AS uuid) AND subject_person_id = CAST(:subject AS uuid)
                        AND request_type = 'erasure' FOR UPDATE
                    SQL, [':request' => $sourceRequestId, ':subject' => $subjectPersonId])->queryOne();
                if (!is_array($request) || $request['status'] !== 'in_progress' || $deadlineAt <= $now) {
                    throw new DomainException('Erasure request or approved deadline is invalid.');
                }
                /** @var DateTimeImmutable $deadlineAt */
                $this->db->createCommand(<<<'SQL'
                    INSERT INTO core.data_subject_request_deadlines
                        (request_id, deadline_code, triggered_at, calendar_type, due_at, extension_allowed)
                    VALUES (CAST(:request AS uuid), 'erasure_completion', CAST(:now AS timestamptz),
                        :calendar, CAST(:due AS timestamptz), false)
                    SQL, [':request' => $sourceRequestId, ':now' => $now->format(DATE_ATOM),
                    ':calendar' => $deadlineCalendarType, ':due' => $deadlineAt->format(DATE_ATOM)])->execute();
            }
            $id = $this->db->createCommand(<<<'SQL'
                INSERT INTO core.privacy_erasure_cases
                    (subject_person_id, source_request_id, reason_code, status, created_at, updated_at,
                        created_by_account_id)
                VALUES (CAST(:subject AS uuid), CAST(:request AS uuid), :reason, 'planned',
                    CAST(:now AS timestamptz), CAST(:now AS timestamptz), CAST(:actor AS uuid)) RETURNING id::text
                SQL, [':subject' => $subjectPersonId, ':request' => $sourceRequestId,
                ':reason' => $reasonCode, ':now' => $now->format(DATE_ATOM),
                ':actor' => $actor->accountId])->queryScalar();
            if (!is_string($id)) {
                throw new DomainException('Erasure case could not be created.');
            }
            $planned = 0;
            foreach ($groups as $group) {
                $evaluation = $this->decisions->evaluateResource($group, $actor, $sourceRequestId);
                /** @var array{aggregate: string, decisions: list<\App\Core\Privacy\Domain\RetentionDecision>} $evaluation */
                $aggregate = $evaluation['aggregate'];
                $action = self::actionFor($aggregate);
                $handler = $action === null ? null : $this->handlers->forAction($group[0]->resourceType, $action);
                $status = $action !== null && $handler !== null ? 'planned' : 'blocked';
                $failure = $status === 'blocked' ? ($action === null ? 'retention_blocked' : 'unsupported_handler') : null;
                $policy = self::policyFor($group, $evaluation['decisions'], $aggregate);
                $itemId = $this->db->createCommand(<<<'SQL'
                    INSERT INTO core.privacy_erasure_items
                        (case_id, resource_type, resource_id, policy_code, planned_action, status,
                            failure_code, created_at, updated_at)
                    VALUES (CAST(:case AS uuid), :type, CAST(:resource AS uuid), :policy, :action,
                        :status, :failure, CAST(:now AS timestamptz), CAST(:now AS timestamptz))
                    RETURNING id::text
                    SQL, [':case' => $id, ':type' => $group[0]->resourceType,
                    ':resource' => $group[0]->resourceId, ':policy' => $policy,
                    ':action' => $action, ':status' => $status, ':failure' => $failure,
                    ':now' => $now->format(DATE_ATOM)])->queryScalar();
                if (!is_string($itemId)) {
                    throw new DomainException('Erasure item could not be created.');
                }
                $seen = [];
                foreach ($group as $candidate) {
                    $key = $candidate->purposeCode . ':' . $candidate->contributorCode;
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $this->db->createCommand(<<<'SQL'
                        INSERT INTO core.privacy_erasure_item_purposes
                            (item_id, purpose_code, policy_code, trigger_code, triggered_at, contributor_code)
                        VALUES (CAST(:item AS uuid), :purpose, :policy, :trigger,
                            CAST(:triggered AS timestamptz), :contributor)
                        SQL, [':item' => $itemId, ':purpose' => $candidate->purposeCode,
                        ':policy' => $candidate->retentionPolicyCode, ':trigger' => $candidate->triggerCode,
                        ':triggered' => $candidate->triggeredAt?->format(DATE_ATOM),
                        ':contributor' => $candidate->contributorCode])->execute();
                }
                if ($status === 'planned') {
                    $planned++;
                } else {
                    $this->audit(
                        $actor,
                        'privacy.erasure.item_blocked',
                        $itemId,
                        ['reasonCode' => $failure, 'resourceType' => $group[0]->resourceType],
                    );
                }
            }
            if ($planned === 0) {
                $this->db->createCommand(
                    "UPDATE core.privacy_erasure_cases SET status = 'blocked' WHERE id = CAST(:id AS uuid)",
                    [':id' => $id],
                )->execute();
            }
            $this->audit($actor, 'privacy.erasure.created', $id, ['reasonCode' => $reasonCode]);
            return $id;
        });
    }

    public function get(string $caseId): ?array
    {
        $case = $this->db->createCommand(<<<'SQL'
            SELECT id::text, subject_person_id::text, source_request_id::text, reason_code,
                status, created_at, started_at, completed_at
            FROM core.privacy_erasure_cases WHERE id = CAST(:id AS uuid)
            SQL, [':id' => $caseId])->queryOne();
        if (!is_array($case)) {
            return null;
        }
        $case['items'] = $this->db->createCommand(<<<'SQL'
            SELECT id::text, resource_type, resource_id::text, policy_code, planned_action,
                status, attempts, started_at, completed_at, failure_code
            FROM core.privacy_erasure_items WHERE case_id = CAST(:id AS uuid) ORDER BY created_at, id
            SQL, [':id' => $caseId])->queryAll();
        return $case;
    }

    public static function actionFor(string $decisionCode): ?string
    {
        return match ($decisionCode) {
            'eligible_delete' => 'delete', 'eligible_anonymize' => 'anonymize',
            'eligible_pseudonymize' => 'pseudonymize', 'eligible_custom' => 'custom',
            default => null,
        };
    }

    /** @param list<RetentionCandidate> $candidates @param list<\App\Core\Privacy\Domain\RetentionDecision> $decisions */
    public static function policyFor(array $candidates, array $decisions, string $aggregate): string
    {
        foreach ($decisions as $decision) {
            /** @var \App\Core\Privacy\Domain\RetentionDecision $decision */
            if ($decision->code === $aggregate) {
                return $decision->candidate->retentionPolicyCode;
            }
        }
        return $candidates[0]->retentionPolicyCode;
    }

    /** @param array<string, mixed> $metadata */
    private function audit(AuthenticatedIdentity $actor, string $action, string $id, array $metadata): void
    {
        $this->audit->record(new WriteAuditEventCommand(
            'account',
            $actor->accountId,
            $action,
            resourceType: 'core.privacy_erasure',
            resourceId: $id,
            metadata: $metadata,
        ));
    }
}
