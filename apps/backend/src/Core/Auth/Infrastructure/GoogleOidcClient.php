<?php

declare(strict_types=1);

namespace App\Core\Auth\Infrastructure;

use App\Core\Auth\Application\ExternalIdentityProfile;
use App\Core\Auth\Application\OidcClientInterface;
use App\Core\Auth\Application\OidcProviderConfiguration;
use Predis\ClientInterface;

final readonly class GoogleOidcClient implements OidcClientInterface
{
    private SecureOidcClient $client;

    public function __construct(?string $clientId, ?string $clientSecret, ?string $redirectUri, ClientInterface $redis)
    {
        $this->client = new SecureOidcClient(new OidcProviderConfiguration(
            code: 'google',
            issuers: ['https://accounts.google.com', 'accounts.google.com'],
            authorizationEndpoint: 'https://accounts.google.com/o/oauth2/v2/auth',
            tokenEndpoint: 'https://oauth2.googleapis.com/token',
            jwksEndpoint: 'https://www.googleapis.com/oauth2/v3/certs',
            clientId: $clientId,
            clientSecret: $clientSecret,
            redirectUri: $redirectUri,
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
