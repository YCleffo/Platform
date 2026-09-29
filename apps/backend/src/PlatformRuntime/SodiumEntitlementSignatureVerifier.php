<?php

declare(strict_types=1);

namespace App\PlatformRuntime;

final readonly class SodiumEntitlementSignatureVerifier implements EntitlementSignatureVerifier
{
    public function __construct(private TrustedEntitlementKeyRegistry $keys) {}

    public function verify(string $canonicalDocument, string $signature, string $keyId): bool
    {
        $public = $this->keys->trustedPublicKey($keyId);
        return $public !== null && strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES
            && sodium_crypto_sign_verify_detached($signature, $canonicalDocument, $public);
    }
}
