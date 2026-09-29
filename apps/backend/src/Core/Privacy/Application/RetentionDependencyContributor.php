<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\RetentionCandidate;

interface RetentionDependencyContributor
{
    public function code(): string;
    public function supports(string $resourceType): bool;
    /** True only when all dependencies permit the configured action. */
    public function resolved(RetentionCandidate $candidate): bool;
}
