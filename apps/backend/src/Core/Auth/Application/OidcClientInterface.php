<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

interface OidcClientInterface
{
    public function providerCode(): string;

    public function authorizationUrl(string $state, string $nonce, string $codeChallenge): string;

    public function exchangeAuthorizationCode(string $code, string $nonce, string $codeVerifier): ExternalIdentityProfile;
}
