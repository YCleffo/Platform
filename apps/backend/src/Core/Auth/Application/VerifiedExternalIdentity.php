<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A stable external subject after an infrastructure adapter has verified it.
 *
 * This value intentionally contains no provider profile, signature, token, or
 * launch payload. Provider adapters own verification and replay prevention.
 */
final readonly class VerifiedExternalIdentity
{
    public function __construct(
        public string $provider,
        public string $externalUserId,
        public ?DateTimeImmutable $issuedAt = null,
    ) {
        ExternalIdentityProviderCode::assert($provider);
        if ($externalUserId === '' || trim($externalUserId) === '' || strlen($externalUserId) > 255) {
            throw new InvalidArgumentException('External user ID must be a non-empty string of at most 255 bytes.');
        }
    }
}
