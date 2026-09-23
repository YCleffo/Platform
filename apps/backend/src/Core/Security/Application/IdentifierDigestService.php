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

    /**
     * Returns raw binary digest (32 bytes).
     * Suitable for use as a WebAuthn user handle (≤64 bytes per spec).
     * When an HMAC key is configured the output is not derivable from the
     * input alone, giving privacy-at-rest for account identifiers.
     */
    public function digestRaw(string $input): string
    {
        return $this->hmacKey === null || $this->hmacKey === ''
            ? hash('sha256', $input, true)
            : hash_hmac('sha256', $input, $this->hmacKey, true);
    }
}
