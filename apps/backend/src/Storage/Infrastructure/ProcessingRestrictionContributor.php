<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure;

use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use App\Core\Privacy\Application\ProcessingRestrictionContributor as Contributor;

/** Core owns restriction state; Storage checks it at every space access. */
final readonly class ProcessingRestrictionContributor implements Contributor
{
    public function __construct(private ProcessingPurposeRegistry $purposes) {}
    public function code(): string
    {
        return 'storage.processing_restriction';
    }
    public function supports(string $scopeType, ?string $purposeCode, ?string $resourceType): bool
    {
        return ($scopeType === 'resource' && $resourceType === 'storage.space')
            || ($scopeType === 'purpose' && $purposeCode !== null
                && in_array('storage', $this->purposes->get($purposeCode)?->dataCategories ?? [], true));
    }
    public function applyRestriction(string $blockId, string $subjectPersonId): void {}
    public function releaseRestriction(string $blockId, string $subjectPersonId): void {}
}
