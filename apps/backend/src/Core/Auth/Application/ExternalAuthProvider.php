<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

/**
 * Infrastructure boundary for a future provider adapter.
 *
 * Implementations validate signatures, expiry, nonce and replay evidence before
 * returning a VerifiedExternalIdentity. They must not persist or log raw input.
 */
interface ExternalAuthProvider
{
    public function providerCode(): string;

    public function verify(ExternalAuthVerificationInput $input): VerifiedExternalIdentity;
}
