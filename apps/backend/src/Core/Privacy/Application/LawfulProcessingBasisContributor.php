<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\RetentionCandidate;

interface LawfulProcessingBasisContributor
{
    public function code(): string;
    public function supports(string $purposeCode, string $resourceType): bool;
    /** Return active, inactive, or unknown. */
    public function resolve(RetentionCandidate $candidate): string;
}
