<?php

declare(strict_types=1);

namespace App\Media\Application;

final readonly class MediaScope
{
    public function __construct(
        public string $organizationId,
        public string $accountId,
        public string $personId,
    ) {}
}
