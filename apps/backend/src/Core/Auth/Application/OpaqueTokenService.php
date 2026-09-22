<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

final readonly class OpaqueTokenService
{
    private const int TOKEN_BYTES = 32;
    private const int ENCODED_LENGTH = 43;

    public function issue(): OpaqueToken
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
        return new OpaqueToken($raw, $this->hashHex($raw));
    }

    public function hashHex(string $raw): string
    {
        return hash('sha256', $raw);
    }

    public function hasValidFormat(string $raw): bool
    {
        return strlen($raw) === self::ENCODED_LENGTH
            && preg_match('/\A[A-Za-z0-9_-]+\z/', $raw) === 1;
    }
}
