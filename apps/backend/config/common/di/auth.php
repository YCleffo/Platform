<?php

declare(strict_types=1);

use App\Environment;
use App\Console\DbCheckCommand;
use App\Core\Auth\Application\AuthRuntimePolicy;
use App\Core\Auth\Application\AuthCleanupPolicy;
use App\Core\Auth\Application\AuthenticationPayloadCipher;
use App\Core\Auth\Application\CredentialMutationGuard;
use App\Core\Auth\Application\OidcClientRegistry;
use App\Core\Auth\Application\OidcClientRegistryInterface;
use App\Core\Auth\Application\AuthRateLimitPolicy;
use App\Core\Auth\Application\AuthLinkBuilder;
use App\Core\Auth\Application\PasswordHasherInterface;
use App\Core\Auth\Application\SessionActivityService;
use App\Core\Auth\Application\TotpSecretCipher;
use App\Core\Auth\Application\TotpProvisioningPolicy;
use App\Core\Auth\Application\WebAuthnPolicy;
use App\Core\Auth\Infrastructure\NativePasswordHasher;
use App\Core\Auth\Infrastructure\GoogleOidcClient;
use App\Core\Auth\Infrastructure\TelegramOidcClient;
use App\Core\Auth\Infrastructure\SodiumAuthenticationPayloadCipher;
use App\Core\Auth\Infrastructure\SodiumTotpSecretCipher;
use App\Core\Security\Application\IdentifierDigestService;
use App\Core\Security\Application\RateLimiterInterface;
use App\Core\Security\Infrastructure\RedisRateLimiter;
use Yiisoft\Definitions\Reference;
use Predis\Client;
use Predis\ClientInterface;

/** @var array<string, mixed> $params */
$auth = $params['auth'];
/** @var array<string, mixed> $redis */
$redis = $params['redis'];

return [
    PasswordHasherInterface::class => ['class' => NativePasswordHasher::class],
    TotpSecretCipher::class => [
        'class' => SodiumTotpSecretCipher::class,
        '__construct()' => [
            'base64Key' => $auth['totpEncryptionKey'],
            'production' => Environment::isProd(),
        ],
    ],
    AuthenticationPayloadCipher::class => [
        'class' => SodiumAuthenticationPayloadCipher::class,
        '__construct()' => [
            'base64Key' => $auth['totpEncryptionKey'],
            'production' => Environment::isProd(),
        ],
    ],
    OidcClientRegistryInterface::class => ['class' => OidcClientRegistry::class],
    GoogleOidcClient::class => [
        '__construct()' => [
            'clientId' => $auth['providers']['google']['clientId'],
            'clientSecret' => $auth['providers']['google']['clientSecret'],
            'redirectUri' => $auth['providers']['google']['redirectUri'],
        ],
    ],
    TelegramOidcClient::class => [
        '__construct()' => [
            'clientId' => $auth['providers']['telegram']['clientId'],
            'clientSecret' => $auth['providers']['telegram']['clientSecret'],
            'redirectUri' => $auth['providers']['telegram']['redirectUri'],
        ],
    ],
    IdentifierDigestService::class => [
        '__construct()' => [
            'hmacKey' => $auth['identifierHmacKey'],
        ],
    ],
    AuthRuntimePolicy::class => [
        '__construct()' => [
            'accessTokenTtlSeconds' => $auth['accessTokenTtlSeconds'],
            'sessionTtlSeconds' => $auth['sessionTtlSeconds'],
            'activationTtlSeconds' => $auth['activationTtlSeconds'],
            'passwordResetTtlSeconds' => $auth['passwordResetTtlSeconds'],
            'mfaChallengeTtlSeconds' => $auth['mfaChallengeTtlSeconds'],
            'mfaRecoveryTtlSeconds' => $auth['mfaRecoveryTtlSeconds'],
            'totpSetupTtlSeconds' => $auth['totpSetupTtlSeconds'],
            'recentAuthenticationSeconds' => $auth['recentAuthenticationSeconds'],
        ],
    ],
    AuthCleanupPolicy::class => [
        '__construct()' => $auth['cleanup'],
    ],
    AuthLinkBuilder::class => [
        '__construct()' => ['publicBaseUrl' => $auth['publicBaseUrl']],
    ],
    TotpProvisioningPolicy::class => [
        '__construct()' => ['issuer' => $auth['totpIssuer']],
    ],
    AuthRateLimitPolicy::class => [
        '__construct()' => $auth['rateLimit'],
    ],
    WebAuthnPolicy::class => [
        '__construct()' => [
            'rpId' => $auth['webauthn']['rpId'],
            'rpName' => $auth['webauthn']['rpName'],
            'origins' => $auth['webauthn']['origins'],
            'challengeTtlSeconds' => $auth['webauthn']['challengeTtlSeconds'],
            'digests' => Reference::to(IdentifierDigestService::class),
        ],
    ],
    CredentialMutationGuard::class => [], // all dependencies auto-wired
    ClientInterface::class => static fn(): Client => new Client([
        'scheme' => 'tcp',
        'host' => $redis['host'],
        'port' => $redis['port'],
        'database' => $redis['database'],
        'timeout' => 1.0,
        'read_write_timeout' => 1.0,
    ]),
    RateLimiterInterface::class => [
        'class' => RedisRateLimiter::class,
        '__construct()' => ['prefix' => $redis['rateLimitPrefix']],
    ],
    SessionActivityService::class => [
        '__construct()' => [
            'touchIntervalSeconds' => $auth['sessionTouchIntervalSeconds'],
        ],
    ],
    DbCheckCommand::class => [
        '__construct()' => [
            'externalProviderConfiguration' => $auth['providers'],
        ],
    ],
];
