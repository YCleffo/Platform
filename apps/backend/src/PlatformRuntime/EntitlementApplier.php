<?php

declare(strict_types=1);

namespace App\PlatformRuntime;

interface EntitlementApplier
{
    /** Persist a verified document; never accept unverified bytes from a caller. */
    public function apply(string $installationId, string $canonicalDocument, string $signature, string $keyId): void;
}
