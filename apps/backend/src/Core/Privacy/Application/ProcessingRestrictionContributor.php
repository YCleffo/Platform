<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

/** Side effects only; core.privacy_processing_blocks remains authoritative. */
interface ProcessingRestrictionContributor
{
    public function code(): string;

    public function supports(string $scopeType, ?string $purposeCode, ?string $resourceType): bool;

    public function applyRestriction(string $blockId, string $subjectPersonId): void;

    public function releaseRestriction(string $blockId, string $subjectPersonId): void;
}
