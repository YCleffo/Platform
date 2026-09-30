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
        return $scopeType === 'purpose' && $purposeCode !== null
            && $this->purposes->get($purposeCode) !== null
            && array_intersect($this->purposes->get($purposeCode)->dataCategories,
                ['identity', 'preferences']) !== [];
    }

    public function applyRestriction(string $blockId, string $subjectPersonId): void {}
    public function releaseRestriction(string $blockId, string $subjectPersonId): void {}
}
