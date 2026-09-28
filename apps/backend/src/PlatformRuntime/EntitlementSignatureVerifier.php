<?php

declare(strict_types=1);

namespace App\PlatformRuntime;

interface EntitlementSignatureVerifier
{
    /** Verify exact canonical document bytes against a trusted signing key. */
    public function verify(string $canonicalDocument, string $signature, string $keyId): bool;
}
