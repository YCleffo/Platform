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
        /** The authenticated actor remains distinct from a delegated record owner. */
        public ?string $actorAccountId = null,
    ) {}

    public function actor(): string
    {
        return $this->actorAccountId ?? $this->accountId;
    }
}
