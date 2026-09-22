<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

final readonly class ExternalAuthorizationStart
{
    public function __construct(public string $authorizationUrl, public string $expiresAt) {}

    /** @return array{authorizationUrl: string, expiresAt: string} */
    public function toArray(): array
    {
        return ['authorizationUrl' => $this->authorizationUrl, 'expiresAt' => $this->expiresAt];
    }
}
