<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use DomainException;

/** Availability follows registered, executable contributor paths. */
final readonly class PrivacyRequestExecutionCapabilityRegistry
{
    public function __construct(
        private PersonalDataContributorRegistry $exportContributors,
        private PersonalDataCorrectionContributorRegistry $correctionContributors,
        private ProcessingRestrictionContributorRegistry $restrictionContributors,
        private DataSubjectRequestTypeRegistry $types,
        private ?RetentionCandidateContributorRegistry $retentionCandidates = null,
        private ?ProcessingPurposeRegistry $purposes = null,
        private ?PersonalDataErasureHandlerRegistry $erasureHandlers = null,
        private ?DestructionEvidencePolicy $destructionEvidence = null,
        private ?ProcessingInformationRegistry $processingInformation = null,
        private ?CorePrivacyContributorCoverage $coverage = null,
    ) {}

    public function available(string $requestType): bool
    {
        $capability = $this->types->get($requestType)['requiredCapability'];
        return match ($capability) {
            'privacy.data.access', 'privacy.data.export' => $this->exportContributors->all() !== [],
            'privacy.data.correction' => $this->correctionContributors->available(),
            'privacy.processing_information.response' => ($this->retentionCandidates?->available() ?? false)
                && ($this->purposes?->codes() ?? []) !== []
                && ($this->retentionCandidates?->coversAll($this->purposes?->codes() ?? []) ?? false)
                && ($this->processingInformation?->coversAll($this->purposes?->codes() ?? []) ?? false),
            // Restriction requires scope-specific enforcement before completion can be offered.
            'privacy.processing.restriction' => false,
            'privacy.processing.termination' => ($this->retentionCandidates?->available() ?? false)
                && ($this->purposes?->codes() ?? []) !== []
                && ($this->retentionCandidates?->coversAll($this->purposes?->codes() ?? []) ?? false)
                && $this->restrictionContributors->available()
                && ($this->coverage?->missing(false) ?? ['coverage_unavailable']) === [],
            // Subject inventory and policy must be checked before claiming erasure execution.
            'privacy.data.erasure' => false,
            default => false,
        };
    }

    public function availableForSubject(string $requestType, string $subjectPersonId): bool
    {
        if ($requestType !== 'erasure' || $this->coverage === null
            || ($this->purposes?->codes() ?? []) === []
            || !$this->retentionCandidates?->available()
            || !($this->destructionEvidence?->available() ?? false)
            || !$this->retentionCandidates->coversAll($this->purposes?->codes() ?? [])) {
            return false;
        }
        try {
            $candidates = $this->retentionCandidates->collect(1001, $subjectPersonId);
            return $candidates !== [] && count($candidates) <= 1000
                && $this->coverage->missingForSubject($subjectPersonId, $candidates) === [];
        } catch (\Throwable) {
            return false;
        }
    }

    public function assertAvailable(string $requestType): void
    {
        if (!$this->available($requestType)) {
            throw new DomainException('Request execution capability is unavailable.');
        }
    }
}
