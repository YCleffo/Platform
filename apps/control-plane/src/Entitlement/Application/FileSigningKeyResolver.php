<?php

declare(strict_types=1);

namespace ControlPlane\Entitlement\Application;

final readonly class FileSigningKeyResolver implements SigningKeyResolver
{
    /** @param array<string,string> $filesByReference */
    public function __construct(private array $filesByReference = []) {}

    public function resolve(string $secretReference): ?string
    {
        if (preg_match('~\A(?:vault|kms|hsm)://[^\s]+\z~D', $secretReference) !== 1) {
            return null;
        }
        $path = $this->filesByReference[$secretReference] ?? null;
        if (!is_string($path) || !str_starts_with($path, '/') || !is_file($path) || !is_readable($path)) {
            return null;
        }
        $encoded = file_get_contents($path);
        if ($encoded === false) { return null; }
        $secret = base64_decode(trim($encoded), true);
        return is_string($secret) && strlen($secret) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ? $secret : null;
    }
}
