<?php

declare(strict_types=1);

namespace App\Core\Auth\Infrastructure;

use App\Core\Auth\Application\ExternalIdentityProfile;
use App\Core\Auth\Application\OidcClientInterface;
use App\Core\Auth\Application\OidcProviderConfiguration;
use Predis\ClientInterface;

final readonly class TelegramOidcClient implements OidcClientInterface
{
    private SecureOidcClient $client;

    public function __construct(?string $clientId, ?string $clientSecret, ?string $redirectUri, ClientInterface $redis)
    {
        $this->client = new SecureOidcClient(new OidcProviderConfiguration(
            code: 'telegram',
            issuers: ['https://oauth.telegram.org'],
            authorizationEndpoint: 'https://oauth.telegram.org/auth',
            tokenEndpoint: 'https://oauth.telegram.org/token',
            jwksEndpoint: 'https://oauth.telegram.org/.well-known/jwks.json',
            clientId: $clientId,
            clientSecret: $clientSecret,
            redirectUri: $redirectUri,
            scope: 'openid profile',
            tokenEndpointAuthMethod: 'client_secret_basic',
        ), $redis);
    }

    public function providerCode(): string
    {
        return $this->client->providerCode();
    }
    public function authorizationUrl(string $state, string $nonce, string $codeChallenge): string
    {
        return $this->client->authorizationUrl($state, $nonce, $codeChallenge);
    }
    public function exchangeAuthorizationCode(string $code, string $nonce, string $codeVerifier): ExternalIdentityProfile
    {
        return $this->client->exchangeAuthorizationCode($code, $nonce, $codeVerifier);
    }
}
