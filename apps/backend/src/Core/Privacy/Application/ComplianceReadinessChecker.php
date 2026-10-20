<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Infrastructure\ConfiguredBusinessCalendar;

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
        private array $securityReferences = [], private array $incidentDeadlines = [],
        private ?BusinessCalendarInterface $calendar = null,
        private array $operatorNotification = [],
        private array $operatorIdentity = [],
        private ?DistributionConsentDefinitionRegistry $distributionDefinitions = null,
        private ?LegalDestructionPackageService $legalPackages = null,
        private ?CorePrivacyContributorCoverage $coverage = null) {}

    /** @return array{result:string,missingRequirements:list<string>,missingContributor:list<string>} */
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
            $registeredPurposes = $this->operatorNotification['registeredPurposes'] ?? null;
            if (!$this->calendar instanceof ConfiguredBusinessCalendar
                || !$this->calendar->coversCurrentPeriod(new \DateTimeImmutable())) {
                $missing[] = 'business_calendar_coverage';
            }
            if (($this->operatorNotification['required'] ?? null) === false) {
                if (trim((string) ($this->operatorNotification['exemptionReference'] ?? '')) === '') {
                    $missing[] = 'rkn_notification_evidence';
                }
            } elseif (($this->operatorNotification['required'] ?? null) !== true
                || (($this->operatorNotification['status'] ?? null) !== 'submitted'
                    || trim((string) ($this->operatorNotification['number'] ?? '')) === ''
                    || trim((string) ($this->operatorNotification['date'] ?? '')) === ''
                    || trim((string) ($this->operatorNotification['evidenceReference'] ?? '')) === ''
                    || $registeredPurposes === []
                    || array_diff($this->purposes->codes(), is_array($registeredPurposes) ? $registeredPurposes : []) !== []
                    || trim((string) ($this->operatorNotification['databaseLocationReference'] ?? '')) === '')) {
                $missing[] = 'rkn_notification_evidence';
            }
            if (!$this->residency->complete()) { $missing[] = 'residency_evidence'; }
            if (!$this->legalPackages?->available()) { $missing[] = 'legal_destruction_package'; }
            if (trim((string) ($this->operatorIdentity['name'] ?? '')) === ''
                || trim((string) ($this->operatorIdentity['address'] ?? '')) === '') {
                $missing[] = 'operator_identity';
            }
            foreach ($this->purposes->codes() as $purposeCode) {
                if ($this->purposes->get($purposeCode)?->distributionAllowed
                    && $this->distributionDefinitions?->get($purposeCode) === null) {
                    $missing[] = 'distribution_consent_definition';
                }
            }
            foreach (['threatModelReference', 'harmAssessmentReference', 'ispdnProtectionLevel',
                'responsiblePersonReference', 'securityApprovalReference',
                'backupRecoveryReference', 'vulnerabilityUpdateReference',
                'adminAccessReviewReference', 'secretsManagementReference',
                'processorContractsReference'] as $key) {
                $reference = $this->securityReferences[$key] ?? null;
                if (!is_string($reference) || trim($reference) === '') {
                    $missing[] = 'security_matrix_references';
                    break;
                }
            }
        }
        $missing = array_values(array_unique($missing));
        sort($missing);
        $missingContributor = $this->coverage?->missing(true) ?? ['coverage_unavailable'];
        return ['result' => $missing === [] && $missingContributor === [] ? 'PASS' : 'FAIL',
            'missingRequirements' => $missing, 'missingContributor' => $missingContributor];
    }
}
