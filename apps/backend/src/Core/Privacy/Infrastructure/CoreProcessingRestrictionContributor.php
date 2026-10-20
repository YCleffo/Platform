<?php

declare(strict_types=1);

namespace App\Core\Privacy\Infrastructure;

use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use App\Core\Privacy\Application\ProcessingRestrictionContributor;

/** Application reads consult ProcessingBlockPolicy; no duplicate restriction state is written. */
final readonly class CoreProcessingRestrictionContributor implements ProcessingRestrictionContributor
{
    public function __construct(private ProcessingPurposeRegistry $purposes) {}

    public function code(): string { return 'core.processing_restriction'; }

    public function supports(string $scopeType, ?string $purposeCode, ?string $resourceType): bool
    {
        $purpose = $purposeCode === null ? null : $this->purposes->get($purposeCode);
        return $scopeType === 'purpose' && $purposeCode !== null
            && $purpose !== null
            && array_intersect($purpose->dataCategories,
                ['identity', 'preferences']) !== [];
    }

    public function applyRestriction(string $blockId, string $subjectPersonId): void {}
    public function releaseRestriction(string $blockId, string $subjectPersonId): void {}
}
