<?php

declare(strict_types=1);

namespace App\PlatformRuntime;

final readonly class TrustedEntitlementKeyRegistry
{
    /** @param array<string,array{publicKeyBase64:string,status:string}> $keys */
    public function __construct(private array $keys = [])
    {
        foreach ($keys as $id => $definition) {
            $bytes = base64_decode($definition['publicKeyBase64'] ?? '', true);
            if (preg_match('/\A[a-z0-9]+(?:[._-][a-z0-9]+)*\z/D', $id) !== 1
                || !in_array($definition['status'] ?? null, ['trusted', 'revoked'], true)
                || !is_string($bytes) || strlen($bytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new \InvalidArgumentException('Invalid trusted entitlement key configuration.');
            }
        }
    }

    public function trustedPublicKey(string $keyId): ?string
    {
        $definition = $this->keys[$keyId] ?? null;
        if ($definition === null || $definition['status'] !== 'trusted') {
            return null;
        }
        $bytes = base64_decode($definition['publicKeyBase64'], true);
        return is_string($bytes) ? $bytes : null;
    }
}
