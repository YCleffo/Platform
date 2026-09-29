<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use DomainException;

/** Availability follows registered, executable contributor paths. */
final readonly class PrivacyRequestExecutionCapabilityRegistry
{
    public function __construct(private PersonalDataContributorRegistry $exportContributors,
        private PersonalDataCorrectionContributorRegistry $correctionContributors,
        private ProcessingRestrictionContributorRegistry $restrictionContributors,
        private DataSubjectRequestTypeRegistry $types,
        private ?RetentionCandidateContributorRegistry $retentionCandidates = null,
        private ?ProcessingPurposeRegistry $purposes = null) {}

    public function available(string $requestType): bool
    {
        $capability = $this->types->get($requestType)['requiredCapability'];
        return match ($capability) {
            'privacy.data.access', 'privacy.data.export' => $this->exportContributors->all() !== [],
            'privacy.data.correction' => $this->correctionContributors->available(),
            // Restriction requires scope-specific enforcement before completion can be offered.
            'privacy.processing.restriction' => false,
            'privacy.processing.termination' => ($this->retentionCandidates?->available() ?? false)
                && ($this->purposes?->codes() ?? []) !== []
                && ($this->retentionCandidates?->coversAll($this->purposes?->codes() ?? []) ?? false)
                && $this->restrictionContributors->available(),
            default => false,
        };
    }

    public function assertAvailable(string $requestType): void
    {
        if (!$this->available($requestType)) {
            throw new DomainException('Request execution capability is unavailable.');
        }
    }
}
