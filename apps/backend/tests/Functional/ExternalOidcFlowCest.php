<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Auth\Application\AccountSecurityNotifier;
use App\Core\Auth\Application\AuthChallengeRepository;
use App\Core\Auth\Application\AuthenticationFlowService;
use App\Core\Auth\Application\AuthenticationPayloadCipher;
use App\Core\Auth\Application\AuthProviderRepository;
use App\Core\Auth\Application\AuthRateLimitPolicy;
use App\Core\Auth\Application\AuthRuntimePolicy;
use App\Core\Auth\Application\CreateTotpFactorCommand;
use App\Core\Auth\Application\ExternalAuthenticationException;
use App\Core\Auth\Application\ExternalAuthService;
use App\Core\Auth\Application\ExternalIdentityProfile;
use App\Core\Auth\Application\ExternalIdentityLastMethodException;
use App\Core\Auth\Application\ExternalIdentityRepository;
use App\Core\Auth\Application\OidcClientInterface;
use App\Core\Auth\Application\OidcClientRegistryInterface;
use App\Core\Auth\Application\OpaqueTokenService;
use App\Core\Auth\Application\PasskeyRepository;
use App\Core\Auth\Application\PasswordCredentialRepository;
use App\Core\Auth\Application\RecentAuthenticationPolicy;
use App\Core\Auth\Application\TotpFactorRepository;
use App\Core\Auth\Application\TotpSecretCipher;
use App\Core\Client\Application\ClientContextResolver;
use App\Core\Security\Application\IdentifierDigestService;
use App\Core\Security\Application\RateLimiterInterface;
use App\Core\Security\Application\SecurityAuditService;
use App\Core\Time\Application\ClockInterface;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use DateTimeImmutable;
use HttpSoft\Message\ServerRequest;
use Psr\Container\ContainerInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertSame;

final readonly class ExternalOidcFlowCest
{
    public function fakeAdapterProvesExplicitSubjectLoginStateReplayAndNoEmailAutoLink(FunctionalTester $tester): void
    {
        $runner = new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        );
        $container = $runner->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $providerId = (string) $db->createCommand(
                "UPDATE core.auth_providers SET enabled = true WHERE code = 'google' RETURNING id::text",
            )->queryScalar();
            $linkedAccount = $this->account($db, 'OIDC linked account');
            $emailAccount = $this->account($db, 'OIDC matching email account');
            $email = 'oidc-' . bin2hex(random_bytes(8)) . '@example.test';
            $db->createCommand(
                <<<'SQL'
                INSERT INTO core.auth_identifiers (
                    account_id, identifier_type, value, normalized_value,
                    is_primary, can_login, can_recover, verified_at
                ) VALUES (CAST(:account_id AS uuid), 'email', :email, :email, true, true, true, now())
                SQL,
                [':account_id' => $emailAccount, ':email' => $email],
            )->execute();
            $profile = new ExternalIdentityProfile(
                subject: 'google-sub-' . bin2hex(random_bytes(8)),
                email: $email,
                emailVerified: true,
                displayName: 'Provider Snapshot',
                avatarUrl: 'https://example.test/avatar',
                claims: ['locale' => 'en-US'],
            );
            $container->get(ExternalIdentityRepository::class)->link($linkedAccount, $providerId, $profile);
            $context = $container->get(ClientContextResolver::class)->resolve(new ServerRequest(
                serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
                headers: ['User-Agent' => 'OIDC functional test'],
            ));
            $service = $this->service($container, $this->registry('google', $profile));
            $start = $service->startLogin('google', $context);
            parse_str((string) parse_url($start->authorizationUrl, PHP_URL_QUERY), $query);
            $state = (string) ($query['state'] ?? '');
            $result = $service->completeLogin('google', $state, 'fake-code', $context)->toArray();
            assertSame('authenticated', $result['status']);
            $sessionAccount = (string) $db->createCommand(
                'SELECT account_id::text FROM core.auth_sessions WHERE id = CAST(:id AS uuid)',
                [':id' => $result['sessionId']],
            )->queryScalar();
            assertSame($linkedAccount, $sessionAccount);
            assertNotSame($emailAccount, $sessionAccount);

            try {
                $service->completeLogin('google', $state, 'fake-code', $context);
                $tester->fail('Consumed OAuth state must not be reusable.');
            } catch (ExternalAuthenticationException) {
                // Expected.
            }

            $unknown = $this->service($container, $this->registry('google', new ExternalIdentityProfile(
                subject: 'unknown-' . bin2hex(random_bytes(8)),
                email: $email,
                emailVerified: true,
                displayName: null,
                avatarUrl: null,
                claims: [],
            )));
            $unknownStart = $unknown->startLogin('google', $context);
            parse_str((string) parse_url($unknownStart->authorizationUrl, PHP_URL_QUERY), $unknownQuery);
            try {
                $unknown->completeLogin('google', (string) $unknownQuery['state'], 'fake-code', $context);
                $tester->fail('An unlinked subject must not create or auto-link an account.');
            } catch (ExternalAuthenticationException) {
                // Expected.
            }
        } finally {
            $transaction->rollBack();
        }
    }

    public function telegramFirstFactorStillRequiresAccountTotp(FunctionalTester $tester): void
    {
        $runner = new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        );
        $container = $runner->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $providerId = (string) $db->createCommand(
                "UPDATE core.auth_providers SET enabled = true WHERE code = 'telegram' RETURNING id::text",
            )->queryScalar();
            $accountId = $this->account($db, 'Telegram MFA account');
            $profile = new ExternalIdentityProfile(
                subject: 'telegram-sub-' . bin2hex(random_bytes(8)),
                email: null,
                emailVerified: false,
                displayName: 'Telegram Snapshot',
                avatarUrl: null,
                claims: ['username' => 'telegram_user'],
            );
            $container->get(ExternalIdentityRepository::class)->link($accountId, $providerId, $profile);
            $secret = $container->get(TotpSecretCipher::class)->encrypt(random_bytes(20));
            $factor = $container->get(TotpFactorRepository::class)->createPending(new CreateTotpFactorCommand(
                accountId: $accountId,
                label: 'Telegram MFA',
                secretEncryptedHex: $secret->ciphertextHex,
                algorithm: 'sha1',
                digits: 6,
                periodSeconds: 30,
                secretKeyVersion: $secret->keyVersion,
                setupExpiresAt: (new DateTimeImmutable('+10 minutes'))->format('Y-m-d\TH:i:s\Z'),
            ));
            $container->get(TotpFactorRepository::class)->activate($factor->id, $accountId, 0);
            $context = $container->get(ClientContextResolver::class)->resolve(new ServerRequest(
                serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
                headers: ['User-Agent' => 'Telegram OIDC functional test'],
            ));
            $service = $this->service($container, $this->registry('telegram', $profile));
            $start = $service->startLogin('telegram', $context);
            parse_str((string) parse_url($start->authorizationUrl, PHP_URL_QUERY), $query);
            $result = $service->completeLogin('telegram', (string) $query['state'], 'fake-code', $context)->toArray();
            assertSame('mfa_required', $result['status']);
            assertArrayNotHasKey('accessToken', $result);
            assertArrayNotHasKey('refreshToken', $result);
        } finally {
            $transaction->rollBack();
        }
    }

    public function explicitLinkAndSafeUnlinkUseRecentAccountBoundSession(FunctionalTester $tester): void
    {
        $runner = new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        );
        $container = $runner->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $providerId = (string) $db->createCommand(
                "UPDATE core.auth_providers SET enabled = true WHERE code = 'google' RETURNING id::text",
            )->queryScalar();
            $accountId = $this->account($db, 'OIDC link account');
            $db->createCommand(
                "INSERT INTO core.auth_password_credentials (account_id, password_hash, password_algorithm) VALUES (CAST(:id AS uuid), :hash, 'argon2id')",
                [':id' => $accountId, ':hash' => password_hash('Link Password 123', PASSWORD_ARGON2ID)],
            )->execute();
            $sessionId = $this->session($db, $accountId, 'password');
            $profile = new ExternalIdentityProfile(
                subject: 'link-sub-' . bin2hex(random_bytes(8)),
                email: 'snapshot@example.test',
                emailVerified: true,
                displayName: 'Linked Snapshot',
                avatarUrl: null,
                claims: [],
            );
            $context = $container->get(ClientContextResolver::class)->resolve(new ServerRequest(
                serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
                headers: ['User-Agent' => 'OIDC link functional test'],
            ));
            $service = $this->service($container, $this->registry('google', $profile));
            $start = $service->startLink('google', $accountId, $sessionId, $context);
            parse_str((string) parse_url($start->authorizationUrl, PHP_URL_QUERY), $query);
            $linked = $service->completeLink(
                'google',
                (string) $query['state'],
                'fake-link-code',
                $accountId,
                $sessionId,
                $context,
            );
            assertSame($accountId, $linked->accountId);
            $service->unlink('google', $accountId, $sessionId, $context);
            assertSame([], $service->identities($accountId));

            $externalOnly = $this->account($db, 'OIDC external-only account');
            $externalSession = $this->session($db, $externalOnly, 'google');
            $container->get(ExternalIdentityRepository::class)->link($externalOnly, $providerId, new ExternalIdentityProfile(
                subject: 'last-method-' . bin2hex(random_bytes(8)),
                email: null,
                emailVerified: false,
                displayName: null,
                avatarUrl: null,
                claims: [],
            ));
            try {
                $service->unlink('google', $externalOnly, $externalSession, $context);
                $tester->fail('The last usable login method must not be removed.');
            } catch (ExternalIdentityLastMethodException) {
                // Expected.
            }

            $auditPayload = (string) $db->createCommand(
                "SELECT coalesce(string_agg(metadata::text, ' '), '') FROM core.audit_events WHERE actor_account_id = CAST(:id AS uuid)",
                [':id' => $accountId],
            )->queryScalar();
            assertSame(false, str_contains($auditPayload, 'fake-link-code'));
            assertSame(false, str_contains($auditPayload, $profile->subject));
        } finally {
            $transaction->rollBack();
        }
    }

    private function account(ConnectionInterface $db, string $label): string
    {
        return (string) $db->createCommand(
            "INSERT INTO core.accounts (account_type, status, label) VALUES ('service', 'active', :label) RETURNING id::text",
            [':label' => $label],
        )->queryScalar();
    }

    private function session(ConnectionInterface $db, string $accountId, string $method): string
    {
        return (string) $db->createCommand(
            <<<'SQL'
            INSERT INTO core.auth_sessions (
                account_id, session_token_hash, refresh_token_hash, security_stamp,
                authentication_method, client_type, access_expires_at, expires_at
            )
            SELECT
                id, decode(:access_hash, 'hex'), decode(:refresh_hash, 'hex'), security_stamp,
                :method, 'functional-test', now() + interval '15 minutes', now() + interval '1 hour'
            FROM core.accounts
            WHERE id = CAST(:account_id AS uuid)
            RETURNING id::text
            SQL,
            [
                ':account_id' => $accountId,
                ':access_hash' => hash('sha256', random_bytes(32)),
                ':refresh_hash' => hash('sha256', random_bytes(32)),
                ':method' => $method,
            ],
        )->queryScalar();
    }

    private function registry(string $provider, ExternalIdentityProfile $profile): OidcClientRegistryInterface
    {
        $client = new class ($provider, $profile) implements OidcClientInterface {
            public function __construct(
                private readonly string $provider,
                private readonly ExternalIdentityProfile $profile,
            ) {}
            public function providerCode(): string
            {
                return $this->provider;
            }
            public function authorizationUrl(string $state, string $nonce, string $codeChallenge): string
            {
                return 'https://fake-oidc.example/auth?' . http_build_query([
                    'state' => $state,
                    'nonce' => $nonce,
                    'code_challenge' => $codeChallenge,
                    'code_challenge_method' => 'S256',
                ]);
            }
            public function exchangeAuthorizationCode(string $code, string $nonce, string $codeVerifier): ExternalIdentityProfile
            {
                return $this->profile;
            }
        };
        return new class ($client) implements OidcClientRegistryInterface {
            public function __construct(private readonly OidcClientInterface $client) {}
            public function get(string $provider): OidcClientInterface
            {
                return $this->client;
            }
        };
    }

    private function service(ContainerInterface $container, OidcClientRegistryInterface $registry): ExternalAuthService
    {
        return new ExternalAuthService(
            providers: $container->get(AuthProviderRepository::class),
            identities: $container->get(ExternalIdentityRepository::class),
            passwords: $container->get(PasswordCredentialRepository::class),
            passkeys: $container->get(PasskeyRepository::class),
            challenges: $container->get(AuthChallengeRepository::class),
            clients: $registry,
            cipher: $container->get(AuthenticationPayloadCipher::class),
            tokens: $container->get(OpaqueTokenService::class),
            authenticationFlow: $container->get(AuthenticationFlowService::class),
            recentAuthentication: $container->get(RecentAuthenticationPolicy::class),
            notifications: $container->get(AccountSecurityNotifier::class),
            rateLimiter: $container->get(RateLimiterInterface::class),
            ratePolicy: $container->get(AuthRateLimitPolicy::class),
            digests: $container->get(IdentifierDigestService::class),
            runtimePolicy: $container->get(AuthRuntimePolicy::class),
            audit: $container->get(SecurityAuditService::class),
            clock: $container->get(ClockInterface::class),
            db: $container->get(ConnectionInterface::class),
        );
    }
}
