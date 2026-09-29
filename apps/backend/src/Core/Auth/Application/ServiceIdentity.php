<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

final readonly class ServiceIdentity
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $accountId,
        public string $credentialId,
        public string $accountType,
        public array $scopes,
    ) {}
}
