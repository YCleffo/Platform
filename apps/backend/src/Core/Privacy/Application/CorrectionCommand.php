<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

/** Values exist only in request memory and are never recorded as evidence. */
final readonly class CorrectionCommand
{
    /** @param array<string, mixed> $changes */
    public function __construct(public string $contributorCode, public string $resourceId,
        public array $changes) {}
}
