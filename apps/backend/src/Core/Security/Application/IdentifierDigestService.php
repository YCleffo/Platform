<?php

declare(strict_types=1);

namespace App\Core\Security\Application;

final readonly class IdentifierDigestService
{
    public function __construct(private ?string $hmacKey) {}

    public function digest(string $normalizedIdentifier): string
    {
        return $this->hmacKey === null || $this->hmacKey === ''
            ? hash('sha256', $normalizedIdentifier)
            : hash_hmac('sha256', $normalizedIdentifier, $this->hmacKey);
    }
}
