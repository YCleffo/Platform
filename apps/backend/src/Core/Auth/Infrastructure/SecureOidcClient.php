<?php

declare(strict_types=1);

namespace App\Core\Auth\Infrastructure;

use App\Core\Auth\Application\ExternalIdentityProfile;
use App\Core\Auth\Application\OidcProviderConfiguration;
use JsonException;
use Predis\ClientInterface;
use RuntimeException;

final readonly class SecureOidcClient
{
    public function __construct(
        private OidcProviderConfiguration $configuration,
        private ClientInterface $redis,
    ) {}

    public function providerCode(): string
    {
        return $this->configuration->code;
    }

    public function authorizationUrl(string $state, string $nonce, string $codeChallenge): string
    {
        $this->configuration->assertConfigured();
        return $this->configuration->authorizationEndpoint . '?' . http_build_query([
            'client_id' => $this->configuration->clientId,
            'redirect_uri' => $this->configuration->redirectUri,
            'response_type' => 'code',
            'scope' => $this->configuration->scope,
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeAuthorizationCode(string $code, string $nonce, string $codeVerifier): ExternalIdentityProfile
    {
        $this->configuration->assertConfigured();
        if ($code === '' || strlen($code) > 4096) {
            throw new RuntimeException('OIDC authorization code is invalid.');
        }
        $parameters = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->configuration->redirectUri,
            'code_verifier' => $codeVerifier,
        ];
        $headers = [];
        if ($this->configuration->tokenEndpointAuthMethod === 'client_secret_basic') {
            $credentials = rawurlencode((string) $this->configuration->clientId)
                . ':' . rawurlencode((string) $this->configuration->clientSecret);
            $headers[] = 'Authorization: Basic ' . base64_encode($credentials);
        } else {
            $parameters['client_id'] = $this->configuration->clientId;
            $parameters['client_secret'] = $this->configuration->clientSecret;
        }
        $response = $this->request(
            $this->configuration->tokenEndpoint,
            'POST',
            http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
            $headers,
        );
        $payload = self::jsonObject($response['body']);
        $idToken = $payload['id_token'] ?? null;
        if (!is_string($idToken)) {
            throw new RuntimeException('OIDC token response did not contain an ID token.');
        }
        $claims = $this->validateIdToken($idToken, $nonce);
        $subject = $claims['sub'] ?? null;
        if (!is_string($subject)) {
            throw new RuntimeException('OIDC subject is missing.');
        }
        $email = isset($claims['email']) && is_string($claims['email']) ? $claims['email'] : null;
        $displayName = isset($claims['name']) && is_string($claims['name']) ? $claims['name'] : null;
        $avatar = isset($claims['picture']) && is_string($claims['picture']) ? $claims['picture'] : null;
        $safeClaims = [];
        foreach (['locale' => 'locale', 'preferred_username' => 'username', 'given_name' => 'givenName', 'family_name' => 'familyName'] as $source => $target) {
            if (isset($claims[$source]) && is_string($claims[$source])) {
                $safeClaims[$target] = mb_substr($claims[$source], 0, 255);
            }
        }

        return new ExternalIdentityProfile(
            subject: $subject,
            email: $email,
            emailVerified: ($claims['email_verified'] ?? false) === true,
            displayName: $displayName,
            avatarUrl: $avatar,
            claims: $safeClaims,
        );
    }

    /** @return array<string, mixed> */
    private function validateIdToken(string $token, string $nonce): array
    {
        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            throw new RuntimeException('OIDC ID token is malformed.');
        }
        [$encodedHeader, $encodedClaims, $encodedSignature] = $segments;
        $header = self::jsonObject(self::base64UrlDecode($encodedHeader));
        $claims = self::jsonObject(self::base64UrlDecode($encodedClaims));
        if (($header['alg'] ?? null) !== 'RS256' || !is_string($header['kid'] ?? null)) {
            throw new RuntimeException('OIDC ID token algorithm or key ID is invalid.');
        }
        $key = $this->signingKey((string) $header['kid']);
        $verified = openssl_verify(
            $encodedHeader . '.' . $encodedClaims,
            self::base64UrlDecode($encodedSignature),
            $key,
            OPENSSL_ALGO_SHA256,
        );
        if ($verified !== 1) {
            throw new RuntimeException('OIDC ID token signature is invalid.');
        }
        $issuer = $claims['iss'] ?? null;
        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];
        $tokenNonce = $claims['nonce'] ?? null;
        $now = time();
        if (!is_string($issuer) || !in_array($issuer, $this->configuration->issuers, true)
            || !in_array($this->configuration->clientId, $audiences, true)
            || !is_int($claims['exp'] ?? null) || $claims['exp'] <= $now
            || !is_int($claims['iat'] ?? null) || $claims['iat'] > $now + 60 || $claims['iat'] < $now - 86_400
            || !is_string($tokenNonce) || !hash_equals($nonce, $tokenNonce)
        ) {
            throw new RuntimeException('OIDC ID token claims are invalid.');
        }
        if (count($audiences) > 1 && ($claims['azp'] ?? null) !== $this->configuration->clientId) {
            throw new RuntimeException('OIDC authorized party is invalid.');
        }

        return $claims;
    }

    private function signingKey(string $kid): string
    {
        $cacheKey = 'platform:oidc:jwks:' . hash('sha256', $this->configuration->jwksEndpoint);
        $json = null;
        try {
            $cached = $this->redis->get($cacheKey);
            $json = is_string($cached) ? $cached : null;
        } catch (\Throwable) {
            // A cache outage must not weaken validation; fetching trusted JWKS is safe.
        }
        if ($json !== null) {
            try {
                $cachedKey = self::keyFromJwks($json, $kid);
                if ($cachedKey !== null) {
                    return $cachedKey;
                }
            } catch (RuntimeException) {
                // A malformed/stale cache entry is replaced from the fixed endpoint.
            }
        }
        $response = $this->request($this->configuration->jwksEndpoint, 'GET');
        $json = $response['body'];
        $ttl = self::cacheTtl($response['headers']['cache-control'] ?? null);
        try {
            $this->redis->setex($cacheKey, $ttl, $json);
        } catch (\Throwable) {
            // Verification may proceed with the freshly fetched keys.
        }
        return self::keyFromJwks($json, $kid)
            ?? throw new RuntimeException('OIDC signing key was not found.');
    }

    private static function keyFromJwks(string $json, string $kid): ?string
    {
        $jwks = self::jsonObject($json);
        $keys = $jwks['keys'] ?? null;
        if (!is_array($keys)) {
            throw new RuntimeException('OIDC JWKS response is invalid.');
        }
        foreach ($keys as $candidate) {
            if (is_array($candidate)
                && ($candidate['kid'] ?? null) === $kid
                && ($candidate['kty'] ?? null) === 'RSA'
                && is_string($candidate['n'] ?? null)
                && is_string($candidate['e'] ?? null)
            ) {
                return self::rsaPublicKey((string) $candidate['n'], (string) $candidate['e']);
            }
        }
        return null;
    }

    /**
     * @param list<string> $additionalHeaders
     * @return array{body: string, headers: array<string, string>}
     */
    private function request(string $url, string $method, ?string $body = null, array $additionalHeaders = []): array
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('OIDC HTTP client rejected a non-HTTPS URL.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('OIDC HTTP client could not initialize.');
        }
        $headers = [];
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => static function (mixed $_handle, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => [...$additionalHeaders, 'Accept: application/json'],
        ]);
        if ($method === 'POST') {
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body ?? '',
                CURLOPT_HTTPHEADER => [
                    ...$additionalHeaders,
                    'Accept: application/json',
                    'Content-Type: application/x-www-form-urlencoded',
                ],
            ]);
        }
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($response) || $status < 200 || $status >= 300 || strlen($response) > 1_048_576) {
            throw new RuntimeException(sprintf('OIDC provider request failed (%d, %s).', $status, $error));
        }

        return ['body' => $response, 'headers' => $headers];
    }

    private static function cacheTtl(?string $cacheControl): int
    {
        if ($cacheControl !== null && preg_match('/(?:^|,)\s*max-age=(\d+)/i', $cacheControl, $matches) === 1) {
            return max(60, min(86_400, (int) $matches[1]));
        }
        return 300;
    }

    /** @return array<string, mixed> */
    private static function jsonObject(string $json): array
    {
        try {
            $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('OIDC JSON response is invalid.', previous: $exception);
        }
        if (!is_array($value)) {
            throw new RuntimeException('OIDC JSON object is required.');
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    private static function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/\A[A-Za-z0-9_-]+\z/', $value) !== 1) {
            throw new RuntimeException('OIDC base64url value is invalid.');
        }
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if (!is_string($decoded)) {
            throw new RuntimeException('OIDC base64url value is invalid.');
        }
        return $decoded;
    }

    private static function rsaPublicKey(string $modulus, string $exponent): string
    {
        $rsa = self::derSequence(
            self::derInteger(self::base64UrlDecode($modulus))
            . self::derInteger(self::base64UrlDecode($exponent)),
        );
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        if (!is_string($algorithm)) {
            throw new RuntimeException('RSA algorithm identifier is invalid.');
        }
        $subjectPublicKeyInfo = self::derSequence($algorithm . "\x03" . self::derLength(strlen($rsa) + 1) . "\x00" . $rsa);
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derSequence(string $bytes): string
    {
        return "\x30" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $encoded = '';
        while ($length > 0) {
            $encoded = chr($length & 0xff) . $encoded;
            $length >>= 8;
        }
        return chr(0x80 | strlen($encoded)) . $encoded;
    }
}
