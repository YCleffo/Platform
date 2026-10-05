<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

/**
 * Opaque request material passed only to an adapter's verification boundary.
 * Core never persists, audits, or exposes this object.
 */
final readonly class ExternalAuthVerificationInput
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $payload,
        public array $headers = [],
    ) {}
}
