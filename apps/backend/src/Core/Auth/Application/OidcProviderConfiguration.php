<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use RuntimeException;

final readonly class OidcProviderConfiguration
{
    /** @param non-empty-list<string> $issuers */
    public function __construct(
        public string $code,
        public array $issuers,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $jwksEndpoint,
        public ?string $clientId,
        public ?string $clientSecret,
        public ?string $redirectUri,
        public string $scope = 'openid profile email',
        public string $tokenEndpointAuthMethod = 'client_secret_post',
    ) {
        foreach ([$authorizationEndpoint, $tokenEndpoint, $jwksEndpoint] as $endpoint) {
            if (parse_url($endpoint, PHP_URL_SCHEME) !== 'https') {
                throw new RuntimeException('OIDC endpoints must use HTTPS.');
            }
        }
        if (!in_array($tokenEndpointAuthMethod, ['client_secret_post', 'client_secret_basic'], true)) {
            throw new RuntimeException('OIDC token endpoint authentication method is invalid.');
        }
    }

    public function assertConfigured(): void
    {
        if ($this->clientId === null || $this->clientSecret === null || $this->redirectUri === null) {
            throw new RuntimeException(sprintf('OIDC provider "%s" is enabled but its environment configuration is incomplete.', $this->code));
        }
        $scheme = parse_url($this->redirectUri, PHP_URL_SCHEME);
        $host = parse_url($this->redirectUri, PHP_URL_HOST);
        if ($scheme !== 'https' && !($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1'], true))) {
            throw new RuntimeException('OIDC redirect URI must use HTTPS except on localhost.');
        }
    }
}
