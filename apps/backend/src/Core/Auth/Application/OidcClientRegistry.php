<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use App\Core\Auth\Infrastructure\GoogleOidcClient;
use App\Core\Auth\Infrastructure\TelegramOidcClient;
use RuntimeException;

final readonly class OidcClientRegistry implements OidcClientRegistryInterface
{
    public function __construct(
        private GoogleOidcClient $google,
        private TelegramOidcClient $telegram,
    ) {}

    public function get(string $provider): OidcClientInterface
    {
        return match ($provider) {
            'google' => $this->google,
            'telegram' => $this->telegram,
            default => throw new RuntimeException('Unsupported OIDC provider.'),
        };
    }
}
