<?php

declare(strict_types=1);

namespace App\Education\Application;

final readonly class Scope
{
    /** @param non-empty-string $timezone */
    public function __construct(
        public string $organizationId,
        public string $accountId,
        public string $personId,
        public string $locale,
        public string $timezone,
        public ?string $studentId = null,
    ) {}
}
