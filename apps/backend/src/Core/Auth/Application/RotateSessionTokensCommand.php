<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

final readonly class RotateSessionTokensCommand
{
    public function __construct(
        public string $currentRefreshTokenHashHex,
        public string $newAccessTokenHashHex,
        public string $newRefreshTokenHashHex,
        public string $accessExpiresAt,
        public string $expectedSecurityStamp,
    ) {}
}
