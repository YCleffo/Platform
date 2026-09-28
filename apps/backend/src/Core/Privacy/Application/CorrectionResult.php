<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

final readonly class CorrectionResult
{
    /** @param list<string> $changedPaths */
    public function __construct(public string $resourceType, public string $resourceId,
        public array $changedPaths) {}
}
