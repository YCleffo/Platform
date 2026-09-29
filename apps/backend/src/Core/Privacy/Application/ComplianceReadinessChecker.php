<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

/** Read-only technical readiness, never a legal compliance certification. */
final readonly class ComplianceReadinessChecker
{
    /** @param array<string, mixed> $securityReferences @param array<string, mixed> $incidentDeadlines */
    public function __construct(private ProcessingPurposeRegistry $purposes,
        private RetentionPolicyRegistry $retention,
        private DestructionEvidencePolicy $destructionEvidence,
        private ProcessorRegistry $processors, private DataRecipientRegistry $recipients,
        private CrossBorderTransferRegistry $transfers,
        private ProcessingRoleRegistry $roles, private DataResidencyEvidence $residency,
        private ProcessingInformationRegistry $processingInformation,
        private array $securityReferences = [], private array $incidentDeadlines = []) {}

    /** @return array{result:string,missingRequirements:list<string>} */
    public function check(bool $productionRequired): array
    {
        $missing = [];
        if ($this->purposes->codes() === []) { $missing[] = 'purposes'; }
        foreach ($this->purposes->codes() as $code) {
            $purpose = $this->purposes->get($code);
            if ($purpose === null || $purpose->legalBasisCode === '') {
                $missing[] = 'legal_bases';
            }
            $policy = $purpose === null ? null : $this->retention->get($purpose->retentionPolicyCode);
            if ($policy === null || ($policy->durationUnit !== 'event_only' && $policy->duration === null)) {
                $missing[] = 'retention';
            }
            if ($this->roles->get($code) === null) {
                $missing[] = 'processing_roles';
            }
            if ($this->processingInformation->get($code) === null) {
                $missing[] = 'processing_information';
            }
        }
        if ($this->retention->all() === []) { $missing[] = 'retention'; }
        if (!$this->destructionEvidence->available()) { $missing[] = 'destruction_evidence_3_years'; }
        if ($this->processors->all() === [] && $this->recipients->all() === []) {
            $missing[] = 'processors_recipients';
        }
        foreach ([...$this->processors->all(), ...$this->recipients->all()] as $processor) {
            if ($processor->country !== 'RU') {
                foreach ($processor->purposes as $purpose) {
                    if ($this->transfers->find($processor->code, $processor->country, $purpose) === null) {
                        $missing[] = 'cross_border_decisions';
                    }
                }
            }
        }
        if (($this->incidentDeadlines['initialHours'] ?? null) !== 24
            || ($this->incidentDeadlines['investigationHours'] ?? null) !== 72) {
            $missing[] = 'incident_deadlines';
        }
        if ($productionRequired) {
            if (!$this->residency->complete()) { $missing[] = 'residency_evidence'; }
            foreach (['threatModelReference', 'harmAssessmentReference', 'ispdnProtectionLevel',
                'responsiblePersonReference', 'securityApprovalReference'] as $key) {
                if (!is_string($this->securityReferences[$key] ?? null)
                    || trim($this->securityReferences[$key]) === '') {
                    $missing[] = 'security_matrix_references';
                    break;
                }
            }
        }
        $missing = array_values(array_unique($missing));
        sort($missing);
        return ['result' => $missing === [] ? 'PASS' : 'FAIL', 'missingRequirements' => $missing];
    }
}
