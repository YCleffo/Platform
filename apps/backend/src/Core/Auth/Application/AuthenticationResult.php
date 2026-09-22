<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

final readonly class AuthenticationResult
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public string $accessExpiresAt,
        public string $sessionExpiresAt,
        public string $sessionId,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'tokenType' => 'Bearer',
            'accessToken' => $this->accessToken,
            'refreshToken' => $this->refreshToken,
            'accessExpiresAt' => $this->accessExpiresAt,
            'sessionExpiresAt' => $this->sessionExpiresAt,
            'sessionId' => $this->sessionId,
        ];
    }
}
