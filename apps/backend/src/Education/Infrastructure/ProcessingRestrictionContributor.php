<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use App\Core\Privacy\Application\ProcessingRestrictionContributor as Contributor;

/** Purpose blocks are canonical in Core; Education only declares the covered purpose scope. */
final readonly class ProcessingRestrictionContributor implements Contributor
{
    public function __construct(private ProcessingPurposeRegistry $purposes) {}

    public function code(): string
    {
        return 'education.purpose_restriction';
    }

    public function supports(string $scopeType, ?string $purposeCode, ?string $resourceType): bool
    {
        if ($scopeType !== 'purpose' || $purposeCode === null) {
            return false;
        }
        $purpose = $this->purposes->get($purposeCode);
        return $purpose !== null && in_array('education', $purpose->dataCategories, true);
    }

    public function applyRestriction(string $blockId, string $subjectPersonId): void {}

    public function releaseRestriction(string $blockId, string $subjectPersonId): void {}
}
