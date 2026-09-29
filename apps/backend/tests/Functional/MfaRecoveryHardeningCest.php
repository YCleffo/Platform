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
use App\Core\Auth\Application\ExternalIdentityRepository;
use App\Core\Auth\Application\MfaRecoveryFinalizer;
use App\Core\Auth\Application\MfaRecoveryService;
use App\Core\Auth\Application\MfaVerificationException;
use App\Core\Auth\Application\OidcClientInterface;
use App\Core\Auth\Application\OidcClientRegistryInterface;
use App\Core\Auth\Application\OpaqueTokenService;
use App\Core\Auth\Application\PasskeyRepository;
use App\Core\Auth\Application\PasswordCredentialRepository;
use App\Core\Auth\Application\RecentAuthenticationPolicy;
use App\Core\Auth\Application\RecoveryCodeRepository;
use App\Core\Auth\Application\RecoveryCodeService;
use App\Core\Auth\Application\TotpFactorRepository;
use App\Core\Auth\Application\TotpSecretCipher;
use App\Core\Client\Application\ClientContextResolver;
use App\Core\Client\Domain\ClientContext;
use App\Core\Security\Application\IdentifierDigestService;
use App\Core\Security\Application\RateLimiterInterface;
use App\Core\Security\Application\SecurityAuditService;
use App\Core\Time\Application\ClockInterface;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use DateTimeImmutable;
use HttpSoft\Message\ServerRequest;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

final readonly class MfaRecoveryHardeningCest
{
    public function googleOnlyAccountRequiresGoogleReauthAndResetsMfaWithoutSession(FunctionalTester $tester): void
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

            $email = 'google-mfa-' . bin2hex(random_bytes(8)) . '@example.test';
            $accountId = $this->account($db, 'Google-only MFA Account');
            $this->addEmail($db, $accountId, $email);

            $googleProfile = new ExternalIdentityProfile(
                subject: 'google-sub-' . bin2hex(random_bytes(8)),
                email: $email,
                emailVerified: true,
                displayName: 'Google Recovery User',
                avatarUrl: null,
                claims: [],
            );
            $container->get(ExternalIdentityRepository::class)->link($accountId, $providerId, $googleProfile);
            $this->enableTotp($container, $accountId);
            $existingSessionId = $this->session($db, $accountId, 'google');
            $initialStamp = (string) $db->createCommand(
                'SELECT security_stamp::text FROM core.accounts WHERE id = CAST(:id AS uuid)',
                [':id' => $accountId],
            )->queryScalar();

            $context = $this->context($container);
            $recoveryService = $container->get(MfaRecoveryService::class);
            $externalService = $this->service($container, $this->registry('google', $googleProfile));

            // Step 1: Request MFA recovery email
            $recoveryService->request($email, $context);
            $emailToken = $this->grabAuthActionToken($container, $db, $email);

            // Step 2: Confirm email token (no password) -> must require external reauth
            $confirmResult = $recoveryService->confirm($emailToken, null, $context);
            assertSame('external_reauth_required', $confirmResult->status);
            assertSame(['google'], $confirmResult->providers);
            assertNotNull($confirmResult->recoveryToken);

            // MFA must NOT be reset yet
            assertNotNull($container->get(TotpFactorRepository::class)->findActive($accountId));
            assertSame(
                $initialStamp,
                (string) $db->createCommand('SELECT security_stamp::text FROM core.accounts WHERE id = CAST(:id AS uuid)', [':id' => $accountId])->queryScalar(),
            );

            // Step 3: Start recovery reauthentication
            $start = $externalService->startRecoveryReauthentication('google', $confirmResult->recoveryToken, $context);
            parse_str((string) parse_url($start->authorizationUrl, PHP_URL_QUERY), $query);
            $state = (string) $query['state'];

            // Recovery token is single-use: cannot restart reauth
            try {
                $externalService->startRecoveryReauthentication('google', $confirmResult->recoveryToken, $context);
                $tester->fail('Consumed recovery token must not be reusable.');
            } catch (ExternalAuthenticationException) {
                // Expected.
            }

            // Step 4: Complete recovery reauthentication
            $externalService->completeRecoveryReauthentication('google', $state, 'fake-auth-code', $context);

            // Verify MFA is reset:
            // 1. TOTP factor revoked
            assertNull($container->get(TotpFactorRepository::class)->findActive($accountId));
            // 2. Recovery codes revoked
            assertSame(0, $container->get(RecoveryCodeRepository::class)->countAvailable($accountId));
            // 3. Security stamp rotated
            $newStamp = (string) $db->createCommand(
                'SELECT security_stamp::text FROM core.accounts WHERE id = CAST(:id AS uuid)',
                [':id' => $accountId],
            )->queryScalar();
            assertFalse($newStamp === $initialStamp);
            // 4. Previous sessions revoked
            $sessionRevokedAt = $db->createCommand(
                'SELECT revoked_at FROM core.auth_sessions WHERE id = CAST(:id AS uuid)',
                [':id' => $existingSessionId],
            )->queryScalar();
            assertNotNull($sessionRevokedAt);
            // 5. NO NEW Platform session issued during recovery reauth
            $activeSessions = (int) $db->createCommand(
                'SELECT count(*) FROM core.auth_sessions WHERE account_id = CAST(:id AS uuid) AND revoked_at IS NULL',
                [':id' => $accountId],
            )->queryScalar();
            assertSame(0, $activeSessions);

            // 6. Security notification email queued
            $notifyCount = (int) $db->createCommand(
                "SELECT count(*) FROM core.outbox_events WHERE producer_module = 'core.auth' AND payload ->> 'templateCode' = 'auth.mfa.recovery_completed'",
            )->queryScalar();
            assertSame(1, $notifyCount);
        } finally {
            $transaction->rollBack();
        }
    }

    public function reauthWithWrongGoogleSubjectIsRejectedAndDoesNotResetMfa(FunctionalTester $tester): void
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

            $emailA = 'account-a-' . bin2hex(random_bytes(8)) . '@example.test';
            $accountA = $this->account($db, 'Account A');
            $this->addEmail($db, $accountA, $emailA);
            $profileA = new ExternalIdentityProfile(
                subject: 'google-sub-a-' . bin2hex(random_bytes(8)),
                email: $emailA,
                emailVerified: true,
                displayName: 'User A',
                avatarUrl: null,
                claims: [],
            );
            $container->get(ExternalIdentityRepository::class)->link($accountA, $providerId, $profileA);
            $this->enableTotp($container, $accountA);

            // Account B with different Google identity
            $emailB = 'account-b-' . bin2hex(random_bytes(8)) . '@example.test';
            $accountB = $this->account($db, 'Account B');
            $profileB = new ExternalIdentityProfile(
                subject: 'google-sub-b-' . bin2hex(random_bytes(8)),
                email: $emailB,
                emailVerified: true,
                displayName: 'User B',
                avatarUrl: null,
                claims: [],
            );
            $container->get(ExternalIdentityRepository::class)->link($accountB, $providerId, $profileB);

            $context = $this->context($container);
            $recoveryService = $container->get(MfaRecoveryService::class);

            // Account A requests recovery
            $recoveryService->request($emailA, $context);
            $emailToken = $this->grabAuthActionToken($container, $db, $emailA);
            $confirmResult = $recoveryService->confirm($emailToken, null, $context);

            // Attacker or confused user completes OAuth callback returning Account B's profile
            $wrongService = $this->service($container, $this->registry('google', $profileB));
            $start = $wrongService->startRecoveryReauthentication('google', (string) $confirmResult->recoveryToken, $context);
            parse_str((string) parse_url($start->authorizationUrl, PHP_URL_QUERY), $query);
            $state = (string) $query['state'];

            try {
                $wrongService->completeRecoveryReauthentication('google', $state, 'fake-code', $context);
                $tester->fail('Foreign provider identity must be rejected for recovery reauthentication.');
            } catch (ExternalAuthenticationException) {
                // Expected.
            }

            // Verify Account A MFA is still active
            assertNotNull($container->get(TotpFactorRepository::class)->findActive($accountA));

            // Verify Account B's identity was not moved
            $bIdentityAccount = (string) $db->createCommand(
                'SELECT account_id::text FROM core.auth_external_identities WHERE provider_subject = :sub',
                [':sub' => $profileB->subject],
            )->queryScalar();
            assertSame($accountB, $bIdentityAccount);

            // Verify audit failure was logged
            $failedAuditCount = (int) $db->createCommand(
                "SELECT count(*) FROM core.audit_events WHERE action = 'auth.mfa_recovery.external_reauth_failed' AND actor_account_id = CAST(:id AS uuid)",
                [':id' => $accountA],
            )->queryScalar();
            assertSame(1, $failedAuditCount);
        } finally {
            $transaction->rollBack();
        }
    }

    public function telegramOnlyAccountRequiresTelegramReauth(FunctionalTester $tester): void
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

            $email = 'telegram-mfa-' . bin2hex(random_bytes(8)) . '@example.test';
            $accountId = $this->account($db, 'Telegram-only MFA Account');
            $this->addEmail($db, $accountId, $email);

            $telegramProfile = new ExternalIdentityProfile(
                subject: 'telegram-sub-' . bin2hex(random_bytes(8)),
                email: null,
                emailVerified: false,
                displayName: 'Telegram Recovery User',
                avatarUrl: null,
                claims: ['username' => 'tg_rec_user'],
            );
            $container->get(ExternalIdentityRepository::class)->link($accountId, $providerId, $telegramProfile);
            $this->enableTotp($container, $accountId);

            $context = $this->context($container);
            $recoveryService = $container->get(MfaRecoveryService::class);
            $externalService = $this->service($container, $this->registry('telegram', $telegramProfile));

            $recoveryService->request($email, $context);
            $emailToken = $this->grabAuthActionToken($container, $db, $email);

            $confirmResult = $recoveryService->confirm($emailToken, null, $context);
            assertSame('external_reauth_required', $confirmResult->status);
            assertSame(['telegram'], $confirmResult->providers);

            $start = $externalService->startRecoveryReauthentication('telegram', (string) $confirmResult->recoveryToken, $context);
            parse_str((string) parse_url($start->authorizationUrl, PHP_URL_QUERY), $query);
            $state = (string) $query['state'];

            $externalService->completeRecoveryReauthentication('telegram', $state, 'tg-code', $context);
            assertNull($container->get(TotpFactorRepository::class)->findActive($accountId));
        } finally {
            $transaction->rollBack();
        }
    }

    public function recoveryCallbackReplayIsRejected(FunctionalTester $tester): void
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

            $email = 'replay-' . bin2hex(random_bytes(8)) . '@example.test';
            $accountId = $this->account($db, 'Replay test account');
            $this->addEmail($db, $accountId, $email);
            $profile = new ExternalIdentityProfile(
                subject: 'google-sub-replay-' . bin2hex(random_bytes(8)),
                email: $email,
                emailVerified: true,
                displayName: 'Replay User',
                avatarUrl: null,
                claims: [],
            );
            $container->get(ExternalIdentityRepository::class)->link($accountId, $providerId, $profile);
            $this->enableTotp($container, $accountId);

            $context = $this->context($container);
            $recoveryService = $container->get(MfaRecoveryService::class);
            $externalService = $this->service($container, $this->registry('google', $profile));

            $recoveryService->request($email, $context);
            $emailToken = $this->grabAuthActionToken($container, $db, $email);
            $confirmResult = $recoveryService->confirm($emailToken, null, $context);

            $start = $externalService->startRecoveryReauthentication('google', (string) $confirmResult->recoveryToken, $context);
            parse_str((string) parse_url($start->authorizationUrl, PHP_URL_QUERY), $query);
            $state = (string) $query['state'];

            // First completion succeeds
            $externalService->completeRecoveryReauthentication('google', $state, 'replay-code', $context);

            // Replay callback with the same state must fail
            try {
                $externalService->completeRecoveryReauthentication('google', $state, 'replay-code', $context);
                $tester->fail('Replaying consumed callback state must be rejected.');
            } catch (ExternalAuthenticationException) {
                // Expected.
            }
        } finally {
            $transaction->rollBack();
        }
    }

    public function passwordAccountUsesPasswordProofWithoutExternalReauth(FunctionalTester $tester): void
    {
        $runner = new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        );
        $container = $runner->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            $email = 'password-rec-' . bin2hex(random_bytes(8)) . '@example.test';
            $accountId = $this->account($db, 'Password recovery account');
            $this->addEmail($db, $accountId, $email);
            $password = 'RecoveryPass#123';
            $db->createCommand(
                "INSERT INTO core.auth_password_credentials (account_id, password_hash, password_algorithm) VALUES (CAST(:id AS uuid), :hash, 'argon2id')",
                [':id' => $accountId, ':hash' => password_hash($password, PASSWORD_ARGON2ID)],
            )->execute();
            $this->enableTotp($container, $accountId);

            $context = $this->context($container);
            $recoveryService = $container->get(MfaRecoveryService::class);

            $recoveryService->request($email, $context);
            $emailToken = $this->grabAuthActionToken($container, $db, $email);

            // Wrong password fails
            try {
                $recoveryService->confirm($emailToken, 'wrong-password', $context);
                $tester->fail('Wrong password must fail.');
            } catch (MfaVerificationException) {
                // Expected.
            }

            // Correct password succeeds directly
            $result = $recoveryService->confirm($emailToken, $password, $context);
            assertSame('completed', $result->status);
            assertNull($container->get(TotpFactorRepository::class)->findActive($accountId));
        } finally {
            $transaction->rollBack();
        }
    }

    public function emailOnlyAccountWithoutPasswordOrExternalProviderReturnsAdditionalRecoveryRequired(FunctionalTester $tester): void
    {
        $runner = new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        );
        $container = $runner->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();
        try {
            // Account with recovery email and TOTP, but NO password and NO external identities
            $email = 'no-methods-' . bin2hex(random_bytes(8)) . '@example.test';
            $accountId = $this->account($db, 'No-method account');
            $this->addEmail($db, $accountId, $email);
            $this->enableTotp($container, $accountId);

            $context = $this->context($container);
            $recoveryService = $container->get(MfaRecoveryService::class);

            $recoveryService->request($email, $context);
            $emailToken = $this->grabAuthActionToken($container, $db, $email);

            $result = $recoveryService->confirm($emailToken, null, $context);
            assertSame('additional_recovery_required', $result->status);

            // MFA must NOT be reset
            assertNotNull($container->get(TotpFactorRepository::class)->findActive($accountId));

            // Audit record for additional_recovery_required failure
            $auditCount = (int) $db->createCommand(
                "SELECT count(*) FROM core.audit_events WHERE action = 'auth.mfa_recovery.external_reauth_failed' AND actor_account_id = CAST(:id AS uuid)",
                [':id' => $accountId],
            )->queryScalar();
            assertSame(1, $auditCount);
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

    private function addEmail(ConnectionInterface $db, string $accountId, string $email): void
    {
        $db->createCommand(
            <<<'SQL'
            INSERT INTO core.auth_identifiers (
                account_id, identifier_type, value, normalized_value,
                is_primary, can_login, can_recover, verified_at
            ) VALUES (CAST(:account_id AS uuid), 'email', :email, :email, true, true, true, now())
            SQL,
            [':account_id' => $accountId, ':email' => $email],
        )->execute();
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

    private function enableTotp(ContainerInterface $container, string $accountId): void
    {
        $cipher = $container->get(TotpSecretCipher::class);
        $secret = $cipher->encrypt(random_bytes(20));
        $factor = $container->get(TotpFactorRepository::class)->createPending(new CreateTotpFactorCommand(
            accountId: $accountId,
            label: 'Test MFA',
            secretEncryptedHex: $secret->ciphertextHex,
            algorithm: 'sha1',
            digits: 6,
            periodSeconds: 30,
            secretKeyVersion: $secret->keyVersion,
            setupExpiresAt: (new DateTimeImmutable('+10 minutes'))->format('Y-m-d\TH:i:s\Z'),
        ));
        $container->get(TotpFactorRepository::class)->activate($factor->id, $accountId, 0);

        $recovery = new RecoveryCodeService();
        $rawCodes = $recovery->generate(5);
        $container->get(RecoveryCodeRepository::class)->store(
            $accountId,
            array_map($recovery->hashHex(...), $rawCodes),
        );
    }

    private function context(ContainerInterface $container): ClientContext
    {
        $ip = sprintf('10.%d.%d.%d', mt_rand(1, 250), mt_rand(1, 250), mt_rand(1, 250));
        return $container->get(ClientContextResolver::class)->resolve(new ServerRequest(
            serverParams: ['REMOTE_ADDR' => $ip],
            headers: ['User-Agent' => 'Recovery Hardening functional test'],
        ));
    }

    private function grabAuthActionToken(ContainerInterface $container, ConnectionInterface $db, string $email): string
    {
        $payload = $db->createCommand(
            <<<'SQL'
            SELECT payload::text
            FROM core.outbox_events
            WHERE producer_module = 'core.auth'
              AND payload #>> '{recipient,address}' = :email
            ORDER BY id DESC
            LIMIT 1
            SQL,
            [':email' => $email],
        )->queryScalar();
        if (!is_string($payload)) {
            throw new RuntimeException('Authentication email event was not queued.');
        }
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $variables = $decoded['variables'] ?? [];
        assertFalse(isset($variables['actionUrl']));
        assertFalse(str_contains($payload, 'token='));
        $url = isset($variables['actionUrlCiphertext'], $variables['actionUrlKeyVersion'])
            ? $container->get(AuthenticationPayloadCipher::class)->decrypt($variables['actionUrlCiphertext'], $variables['actionUrlKeyVersion'])
            : ($variables['actionUrl'] ?? null);
        if (!is_string($url)) {
            throw new RuntimeException('Authentication action URL is missing.');
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $token = $query['token'] ?? null;
        if (!is_string($token)) {
            throw new RuntimeException('Authentication action token is missing.');
        }
        return $token;
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
            mfaRecoveryFinalizer: $container->get(MfaRecoveryFinalizer::class),
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
