<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Time\Application\ClockInterface;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class ProcessingInformationService
{
    public function __construct(private ConnectionInterface $db, private ClockInterface $clock,
        private DataSubjectRequestService $requests,
        private RepresentativeAuthorityPolicy $authority,
        private RetentionCandidateContributorRegistry $candidates,
        private ProcessingPurposeRegistry $purposes,
        private RetentionPolicyRegistry $retention,
        private LawfulProcessingBasisResolver $basis,
        private ProcessingInformationRegistry $information,
        private ProcessorRegistry $processors, private DataRecipientRegistry $recipients,
        private CrossBorderTransferRegistry $transfers,
        private ProcessingRoleRegistry $roles,
        private DataSubjectRequestExecutionService $executions) {}

    public function issue(AuthenticatedIdentity $actor, string $requestId,
        ?string $representationId = null): string
    {
        return $this->db->transaction(function () use ($actor, $requestId, $representationId): string {
            $view = $this->requests->getVisible($actor, $requestId);
            if ($view === null || $view->requestType !== 'processing_information'
                || $view->status !== 'in_progress' || $actor->personId === null) {
                throw new DomainException('Processing information response unavailable.');
            }
            $subject = $view->subjectPersonId;
            if ($representationId === null) {
                if ($subject !== $actor->personId) {
                    throw new DomainException('Processing information response unavailable.');
                }
            } else {
                $requestRepresentation = $this->db->createCommand(<<<'SQL'
                    SELECT legal_representation_id::text FROM core.data_subject_requests
                    WHERE id = CAST(:id AS uuid)
                    SQL, [':id' => $requestId])->queryScalar();
                if ($requestRepresentation !== $representationId || $subject === $actor->personId) {
                    throw new DomainException('Processing information response unavailable.');
                }
                $this->authority->assertActiveForAction($representationId, $subject,
                    $actor->personId, 'privacy.request.view');
                $this->authority->assertActiveForAction($representationId, $subject,
                    $actor->personId, 'privacy.processing_information.response');
            }
            if (!$this->candidates->available() || !$this->candidates->coversAll($this->purposes->codes())
                || !$this->information->coversAll($this->purposes->codes())) {
                throw new DomainException('Processing information coverage is incomplete.');
            }
            $candidates = $this->candidates->collect(1001, $subject);
            if (count($candidates) > 1000) {
                throw new DomainException('Processing information scope exceeds safe batch.');
            }
            $byPurpose = [];
            foreach ($candidates as $candidate) {
                $byPurpose[$candidate->purposeCode][] = $candidate;
            }
            $purposeRows = [];
            foreach ($byPurpose as $purposeCode => $group) {
                $purpose = $this->purposes->get($purposeCode);
                $definition = $this->information->get($purposeCode);
                $policy = $purpose === null ? null : $this->retention->get($purpose->retentionPolicyCode);
                $role = $this->roles->get($purposeCode);
                if ($purpose === null || $definition === null || $policy === null || $role === null) {
                    throw new DomainException('Processing information purpose is incomplete.');
                }
                $states = [];
                $resourceTypes = [];
                foreach ($group as $candidate) {
                    $state = $this->basis->resolve($candidate);
                    if ($state === 'unknown') {
                        throw new DomainException('Processing basis is unknown.');
                    }
                    $states[$state] = true;
                    $resourceTypes[$candidate->resourceType] = true;
                }
                $recipientRows = [];
                $transfers = [];
                foreach ($this->recipients->all() as $recipient) {
                    if ($recipient->activeAt($this->clock->now())
                        && in_array($purposeCode, $recipient->purposes, true)) {
                        $recipientRows[] = ['code' => $recipient->code,
                            'nameReference' => $recipient->nameReference,
                            'country' => $recipient->country];
                        if ($recipient->country !== 'RU') {
                            $transfer = $this->transfers->find($recipient->code,
                                $recipient->country, $purposeCode);
                            if ($transfer === null) {
                                throw new DomainException('Cross-border recipient information is unknown.');
                            }
                            $transfers[] = ['providerCode' => $recipient->code,
                                'country' => $recipient->country,
                                'notificationStatus' => $transfer->notificationStatus,
                                'allowed' => $transfer->allowed];
                        }
                    }
                }
                $processorRows = [];
                foreach ($this->processors->all() as $processor) {
                    if (!$processor->activeAt($this->clock->now())
                        || !in_array($purposeCode, $processor->purposes, true)) { continue; }
                    $processorRows[] = ['code' => $processor->code,
                        'nameReference' => $processor->nameReference,
                        'country' => $processor->country];
                    if ($processor->country !== 'RU') {
                        $transfer = $this->transfers->find($processor->code,
                            $processor->country, $purposeCode);
                        if ($transfer === null) {
                            throw new DomainException('Cross-border information is unknown.');
                        }
                        $transfers[] = ['providerCode' => $processor->code,
                            'country' => $processor->country,
                            'notificationStatus' => $transfer->notificationStatus,
                            'allowed' => $transfer->allowed];
                    }
                }
                if ($purpose->crossBorderPolicy !== 'none' && $transfers === []) {
                    throw new DomainException('Cross-border information is incomplete.');
                }
                $purposeRows[] = [
                    'purposeCode' => $purposeCode,
                    'legalBasisCode' => $purpose->legalBasisCode,
                    'basisStates' => array_keys($states),
                    'processingRole' => $role->platformRole,
                    'operatorReference' => $role->operatorReference,
                    'methods' => $definition->methodCodes,
                    'dataCategories' => $purpose->dataCategories,
                    'resourceTypes' => array_keys($resourceTypes),
                    'sources' => $definition->sourceCodes,
                    'storageAndProcessingPeriod' => [
                        'policyCode' => $policy->code,
                        'trigger' => $policy->trigger,
                        'duration' => $policy->duration,
                        'durationUnit' => $policy->durationUnit,
                    ],
                    'rightsProcedureReference' => $definition->rightsProcedureReference,
                    'recipients' => $recipientRows,
                    'processorsByInstruction' => $processorRows,
                    'crossBorderTransfer' => $transfers,
                    'complianceMethods' => $definition->complianceMethodCodes,
                ];
            }
            $json = json_encode([
                'schemaVersion' => 'privacy.processing_information.v1',
                'subjectPersonId' => $subject,
                'processed' => $purposeRows !== [],
                'purposes' => $purposeRows,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $this->executions->recordProcessingInformationIssued($actor, $requestId,
                hash('sha256', $json), strlen($json));
            return $json;
        });
    }
}
