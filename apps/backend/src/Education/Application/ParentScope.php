<?php

declare(strict_types=1);

namespace App\Education\Application;

/** A parent actor remains the authenticated Core identity; no student account is used. */
final readonly class ParentScope
{
    public function __construct(
        public string $organizationId,
        public string $accountId,
        public string $personId,
        public string $studentId,
        public string $studentPersonId,
        public string $locale,
        public string $timezone,
    ) {}
}
