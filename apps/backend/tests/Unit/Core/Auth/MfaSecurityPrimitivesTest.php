<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Auth;

use App\Core\Auth\Application\AuthenticationMethod;
use App\Core\Auth\Application\Base32;
use App\Core\Auth\Application\ExternalIdentityProfile;
use App\Core\Auth\Application\OidcProviderConfiguration;
use App\Core\Auth\Application\RecoveryCodeService;
use App\Core\Auth\Application\TotpService;
use App\Core\Auth\Infrastructure\SodiumAuthenticationPayloadCipher;
use App\Core\Auth\Infrastructure\SodiumTotpSecretCipher;
use App\Core\Time\Application\ClockInterface;
use Codeception\Test\Unit;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertNotContains;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringStartsWith;

final class MfaSecurityPrimitivesTest extends Unit
{
    public function testRfc6238Sha1VectorAndClockWindow(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('@59');
            }
        };
        $totp = new TotpService($clock);
        $secret = '12345678901234567890';

        assertSame('94287082', $totp->code($secret, 1, 'sha1', 8));
        assertSame(1, $totp->matchingCounter($secret, '94287082', 'sha1', 8, 30));
        assertSame(null, $totp->matchingCounter($secret, '00000000', 'sha1', 8, 30));
    }

    public function testBase32RoundTripAndProvisioningUri(): void
    {
        $secret = random_bytes(20);
        $encoded = Base32::encode($secret);
        assertSame($secret, Base32::decode($encoded));
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('@0');
            }
        };
        assertStringStartsWith('otpauth://totp/Platform%3A', (new TotpService($clock))->provisioningUri('Platform', 'user', $encoded));
    }

    public function testSecretsUseAuthenticatedEncryptionWithDomainSeparation(): void
    {
        $key = base64_encode(random_bytes(32));
        $totpCipher = new SodiumTotpSecretCipher($key, true);
        $transactionCipher = new SodiumAuthenticationPayloadCipher($key, true);
        $totp = $totpCipher->encrypt('same plaintext');
        $transaction = $transactionCipher->encrypt('same plaintext');

        assertNotSame(bin2hex('same plaintext'), $totp->ciphertextHex);
        assertNotSame($totp->ciphertextHex, $transaction->ciphertextHex);
        assertSame('same plaintext', $totpCipher->decrypt($totp->ciphertextHex, $totp->keyVersion));
        assertSame('same plaintext', $transactionCipher->decrypt($transaction->ciphertextHex, $transaction->keyVersion));
    }

    public function testRecoveryCodesAreUniqueAndHashOnlyRepresentationIsStable(): void
    {
        $service = new RecoveryCodeService();
        $codes = $service->generate();
        assertCount(10, $codes);
        assertCount(10, array_unique($codes));
        assertSame($service->hashHex($codes[0]), $service->hashHex(strtolower(str_replace('-', ' ', $codes[0]))));
        assertNotContains($codes[0], array_map($service->hashHex(...), $codes));
    }

    public function testAuthenticationMethodVocabularyRejectsArbitraryValues(): void
    {
        assertSame('google+totp', AuthenticationMethod::withSecondFactor('google', 'totp'));
        $this->expectException(InvalidArgumentException::class);
        AuthenticationMethod::firstFactor('custom-provider');
    }

    public function testExternalProfileAcceptsOnlySafeClaimsAndHttpsAvatar(): void
    {
        $profile = new ExternalIdentityProfile('subject', 'user@example.test', true, 'User', 'https://example.test/avatar', [
            'locale' => 'ru-RU',
            'username' => 'user',
        ]);
        assertSame('subject', $profile->subject);

        $this->expectException(InvalidArgumentException::class);
        new ExternalIdentityProfile('subject', null, false, null, null, ['access_token' => 'forbidden']);
    }

    public function testOidcConfigurationRejectsNonHttpsProviderEndpoints(): void
    {
        $this->expectException(RuntimeException::class);
        new OidcProviderConfiguration(
            code: 'bad',
            issuers: ['https://issuer.example'],
            authorizationEndpoint: 'http://issuer.example/auth',
            tokenEndpoint: 'https://issuer.example/token',
            jwksEndpoint: 'https://issuer.example/jwks',
            clientId: null,
            clientSecret: null,
            redirectUri: null,
        );
    }

    public function testOidcTokenEndpointAuthenticationMethodIsExplicit(): void
    {
        $configuration = new OidcProviderConfiguration(
            code: 'telegram',
            issuers: ['https://oauth.telegram.org'],
            authorizationEndpoint: 'https://oauth.telegram.org/auth',
            tokenEndpoint: 'https://oauth.telegram.org/token',
            jwksEndpoint: 'https://oauth.telegram.org/.well-known/jwks.json',
            clientId: null,
            clientSecret: null,
            redirectUri: null,
            tokenEndpointAuthMethod: 'client_secret_basic',
        );

        assertSame('client_secret_basic', $configuration->tokenEndpointAuthMethod);
    }
}
