<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

final readonly class CredentialTarget
{
    public function __construct(
        public string $accountId,
        public string $email,
        public string $normalizedEmail,
        public string $displayName,
        public string $locale,
    ) {}
}
