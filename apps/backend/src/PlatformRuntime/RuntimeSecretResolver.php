<?php

declare(strict_types=1);

namespace App\PlatformRuntime;

/** Resolves a deployment secret from the runtime environment without persisting it. */
final readonly class RuntimeSecretResolver
{
    public function deploymentCredential(string $reference): string
    {
        if (preg_match('/\Aenv:([A-Z][A-Z0-9_]*)\z/D', $reference, $match) !== 1) {
            throw new \DomainException('Deployment credential reference is unavailable.');
        }
        $secret = getenv($match[1]);
        if (!is_string($secret) || preg_match(
            '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\.[A-Za-z0-9_-]{43}\z/iD', $secret
        ) !== 1) {
            throw new \DomainException('Deployment credential is unavailable.');
        }
        return $secret;
    }
}
