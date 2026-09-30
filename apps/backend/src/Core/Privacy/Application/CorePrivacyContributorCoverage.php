<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

/** Static Core resource matrix, checked against the actual container registrations. */
final readonly class CorePrivacyContributorCoverage
{
    private const RESOURCE_CATEGORIES = [
        'core.person_contact' => 'identity',
        'core.account_settings' => 'preferences',
        'core.organization_membership' => 'identity',
    ];

    public function __construct(private ProcessingPurposeRegistry $purposes,
        private RetentionPolicyRegistry $policies,
        private RetentionCandidateContributorRegistry $candidates,
        private LawfulProcessingBasisContributorRegistry $bases,
        private RetentionDependencyContributorRegistry $dependencies,
        private ProcessingRestrictionContributorRegistry $restrictions,
        private PersonalDataErasureHandlerRegistry $handlers) {}

    /** @return list<string> Machine codes only; no subject data. */
    public function missing(bool $destructive): array
    {
        $missing = [];
        foreach ($this->purposes->codes() as $purposeCode) {
            $purpose = $this->purposes->get($purposeCode);
            $policy = $this->policies->get($purpose->retentionPolicyCode);
            if ($destructive && in_array('identity', $purpose->dataCategories, true)) {
                $missing[] = $purposeCode . ':core.person:erasure_handler';
                $missing[] = $purposeCode . ':core.account:erasure_handler';
            }
            foreach (self::RESOURCE_CATEGORIES as $resourceType => $category) {
                if (!in_array($category, $purpose->dataCategories, true)) { continue; }
                $prefix = $purposeCode . ':' . $resourceType . ':';
                if (!$this->candidates->coversResourcePurpose($resourceType, $purposeCode)) {
                    $missing[] = $prefix . 'retention';
                }
                // The built-in resolver proves consent from runtime rows; all other bases need a contributor.
                if (!$purpose->requiresConsent && !$this->bases->supports($purposeCode, $resourceType)) {
                    $missing[] = $prefix . 'lawful_basis';
                }
                if ($this->restrictions->forScope('purpose', $purposeCode, null) === []) {
                    $missing[] = $prefix . 'restriction';
                }
                if ($resourceType === 'core.organization_membership'
                    && !$this->dependencies->supports($resourceType)) {
                    $missing[] = $prefix . 'dependency';
                }
                if ($destructive && $policy !== null) {
                    $action = match ($policy->action) {
                        'delete', 'archive_then_delete' => 'delete',
                        'anonymize' => 'anonymize',
                        'pseudonymize' => 'pseudonymize',
                        default => 'custom',
                    };
                    if ($this->handlers->forAction($resourceType, $action) === null) {
                        $missing[] = $prefix . 'erasure_handler';
                    }
                }
            }
        }
        sort($missing);
        return array_values(array_unique($missing));
    }
}
