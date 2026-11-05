<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Core\Auth\Application\ExternalAuthenticationException;
use App\Core\Auth\Application\ExternalIdentityProfile;
use App\Core\Auth\Application\OidcClientInterface;
use App\Core\Auth\Application\OidcClientRegistryInterface;
use Predis\ClientInterface;

/** Explicit disposable transport fixture, never a replacement for production Google/Telegram verification. */
final readonly class BrowserOidcFixture implements OidcClientRegistryInterface
{
    public function __construct(private ClientInterface $redis, private string $base) {}
    public function get(string $provider): OidcClientInterface
    {
        if (!in_array($provider, ['google', 'telegram'], true)) throw new ExternalAuthenticationException();
        return new class($provider, $this->redis, $this->base) implements OidcClientInterface {
            public function __construct(private string $provider, private ClientInterface $redis, private string $base) {}
            public function providerCode(): string { return $this->provider; }
            public function authorizationUrl(string $state, string $nonce, string $codeChallenge): string
            {
                $base = $this->provider === 'google' ? 'https://accounts.google.com/o/oauth2/v2/auth' : 'https://oauth.telegram.org/auth';
                return $base . '?' . http_build_query(['state' => $state, 'nonce' => $nonce, 'code_challenge' => $codeChallenge,
                    'code_challenge_method' => 'S256', 'response_type' => 'code', 'redirect_uri' => rtrim($this->base, '/') . '/auth/callback/' . $this->provider]);
            }
            public function exchangeAuthorizationCode(string $code, string $nonce, string $codeVerifier): ExternalIdentityProfile
            {
                $raw = $this->redis->getdel('platform:core:e2e:oidc:' . hash('sha256', $code));
                $data = is_string($raw) ? json_decode($raw, true, 16, JSON_THROW_ON_ERROR) : null;
                $challenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
                if (!is_array($data) || ($data['provider'] ?? null) !== $this->provider || ($data['nonce'] ?? null) !== $nonce
                    || ($data['codeChallenge'] ?? null) !== $challenge || !is_string($data['subject'] ?? null)) throw new ExternalAuthenticationException();
                return new ExternalIdentityProfile($data['subject'], null, false, 'Isolated provider fixture', null, []);
            }
        };
    }
}
