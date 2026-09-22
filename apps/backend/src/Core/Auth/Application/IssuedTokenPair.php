<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

/** @internal Raw credentials must be returned once and never persisted or logged. */
final readonly class IssuedTokenPair
{
    public function __construct(
        public OpaqueToken $accessToken,
        public OpaqueToken $refreshToken,
    ) {}
}
