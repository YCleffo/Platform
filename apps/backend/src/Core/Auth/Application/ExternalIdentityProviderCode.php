<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use InvalidArgumentException;

/** Canonical, provider-neutral identifier validation shared by Core callers. */
final class ExternalIdentityProviderCode
{
    public static function assert(string $provider): void
    {
        if (preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/', $provider) !== 1 || strlen($provider) > 50) {
            throw new InvalidArgumentException('External provider code must be a canonical lowercase identifier.');
        }
    }
}
