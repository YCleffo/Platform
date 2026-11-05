<?php

declare(strict_types=1);

namespace App\Core\Privacy\Infrastructure;

use App\Core\Privacy\Application\LawfulProcessingBasisContributor;
use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use App\Core\Privacy\Domain\RetentionCandidate;

/** Consent is checked by LawfulProcessingBasisResolver; no other basis is inferred from row existence. */
final readonly class CoreResourceBasisContributor implements LawfulProcessingBasisContributor
{
    public function __construct(private ProcessingPurposeRegistry $purposes) {}

    public function code(): string
    {
        return 'core.resource_basis';
    }

    public function supports(string $purposeCode, string $resourceType): bool
    {
        $category = match ($resourceType) {
            'core.person', 'core.human_account', 'core.person_contact',
            'core.organization_membership' => 'identity',
            'core.account_settings' => 'preferences',
            default => null,
        };
        $purpose = $this->purposes->get($purposeCode);
        return $category !== null && $purpose !== null && $purpose->requiresConsent === true
            && in_array($category, $purpose->dataCategories, true);
    }

    public function resolve(RetentionCandidate $candidate): string
    {
        $purpose = $this->purposes->get($candidate->purposeCode);
        if ($purpose === null || !$this->supports($candidate->purposeCode, $candidate->resourceType)) {
            return 'unknown';
        }
        // The resolver already proved that no active consent exists before delegating here.
        return 'inactive';
    }
}
