<?php

declare(strict_types=1);

namespace ControlPlane\Entitlement\Application;

interface SigningKeyResolver
{
    /** Resolve a private Ed25519 key from an external secret reference. */
    public function resolve(string $secretReference): ?string;
}
