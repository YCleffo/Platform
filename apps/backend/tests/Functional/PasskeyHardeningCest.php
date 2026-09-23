<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Account\Application\AccountSecurityRepository;
use App\Core\Auth\Application\AuthChallengeRepository;
use App\Core\Auth\Application\AuthenticationFlowService;
use App\Core\Auth\Application\AuthRateLimitPolicy;
use App\Core\Auth\Application\CredentialMutationGuard;
use App\Core\Auth\Application\InvalidCredentialsException;
use App\Core\Auth\Application\PasskeyRepository;
use App\Core\Auth\Application\PasskeyService;
use App\Core\Auth\Application\RecentAuthenticationPolicy;
use App\Core\Auth\Application\AccountSecurityNotifier;
use App\Core\Auth\Application\WebAuthnPolicy;
use App\Core\Client\Application\ClientContextResolver;
use App\Core\Security\Application\IdentifierDigestService;
use App\Core\Security\Application\RateLimiterInterface;
use App\Core\Security\Application\RateLimiterUnavailableException;
use App\Core\Security\Application\SecurityAuditService;
use App\Core\Time\Application\ClockInterface;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use App\Tests\Support\WebAuthnTestAuthenticator;
use HttpSoft\Message\ServerRequest;
use InvalidArgumentException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;
use PHPUnit\Framework\Assert;

use function dirname;
use function hash;
use function in_array;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;
use function random_bytes;
use function random_int;
use function str_repeat;

final readonly class PasskeyHardeningCest
{
    public function _before(FunctionalTester $I): void
    {
        $this->cleanup();
    }

    public function _after(FunctionalTester $I): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        [$container, $db] = $this->createContainer();
        $db->createCommand(
            "DELETE FROM core.outbox_events WHERE payload #>> '{recipient,address}' LIKE 'passkey-hard-%@example.test' OR payload->>'templateCode' LIKE 'auth.passkey.%'",
        )->execute();
        $db->createCommand(
            "DELETE FROM core.accounts WHERE label LIKE 'Passkey Hardening Account%'",
        )->execute();
    }

    private function createContainer(): array
    {
        $runner = new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        );
        $container = $runner->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $passkeyService = $container->get(PasskeyService::class);
        $webAuthnPolicy = $container->get(WebAuthnPolicy::class);
        $challenges = $container->get(AuthChallengeRepository::class);
        $passkeyRepo = $container->get(PasskeyRepository::class);

        $ip = '10.1.' . random_int(1, 250) . '.' . random_int(1, 250);
        $request = new ServerRequest(
            headers: [
                'User-Agent' => 'PasskeyHardeningTester/1.0',
                'X-Client-Instance-ID' => 'a0000000-0000-4000-8000-000000000001',
            ],
            serverParams: ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'PasskeyHardeningTester/1.0'],
            uri: 'http://localhost:5173/api/auth/passkeys',
            method: 'POST',
        );
        $context = $container->get(ClientContextResolver::class)->resolve($request);

        return [$container, $db, $passkeyService, $webAuthnPolicy, $challenges, $passkeyRepo, $context];
    }

    private function createAccount(ConnectionInterface $db): array
    {
        $accountId = (string) $db->createCommand(
            "INSERT INTO core.accounts (account_type, status, label) VALUES ('service', 'active', 'Passkey Hardening Account') RETURNING id::text",
        )->queryScalar();

        $email = 'passkey-hard-' . bin2hex(random_bytes(6)) . '@example.test';
        $db->createCommand(
            <<<'SQL'
            INSERT INTO core.auth_identifiers (
                account_id, identifier_type, value, normalized_value,
                is_primary, can_login, can_recover, verified_at
            )
            VALUES (
                CAST(:account_id AS uuid), 'email', :email, :email,
                true, true, true, now()
            )
            SQL,
            [':account_id' => $accountId, ':email' => $email],
        )->execute();

        $db->createCommand(
            "INSERT INTO core.auth_password_credentials (account_id, password_hash, password_algorithm) VALUES (CAST(:id AS uuid), :hash, 'argon2id')",
            [':id' => $accountId, ':hash' => password_hash('HardPass123!', PASSWORD_ARGON2ID)],
        )->execute();

        $sessionA = $this->createSession($db, $accountId);
        $sessionB = $this->createSession($db, $accountId);

        return [$accountId, $sessionA, $sessionB];
    }

    private function createSession(ConnectionInterface $db, string $accountId): string
    {
        return (string) $db->createCommand(
            <<<'SQL'
            INSERT INTO core.auth_sessions (
                account_id, session_token_hash, refresh_token_hash, security_stamp,
                authentication_method, client_type, access_expires_at, expires_at
            )
            VALUES (
                CAST(:account_id AS uuid), decode(:s_hash, 'hex'), decode(:r_hash, 'hex'),
                gen_random_uuid(), 'password', 'web', now() + interval '1 hour', now() + interval '30 days'
            )
            RETURNING id::text
            SQL,
            [
                ':account_id' => $accountId,
                ':s_hash' => hash('sha256', random_bytes(32)),
                ':r_hash' => hash('sha256', random_bytes(32)),
            ],
        )->queryScalar();
    }

    /**
     * Ceremony started in session A must reject completion in session B.
     */
    public function testRegistrationBoundToSession(FunctionalTester $I): void
    {
        [$container, $db, $service, $policy, $challenges, $passkeyRepo, $context] = $this->createContainer();
        [$accountId, $sessionA, $sessionB] = $this->createAccount($db);

        $start = $service->startRegistration($accountId, $sessionA, $context);
        $authenticator = new WebAuthnTestAuthenticator();
        $responseJson = $authenticator->makeRegistrationResponse($start['challenge']);

        try {
            $service->completeRegistration($accountId, $sessionB, $responseJson, 'Key', $context);
            Assert::fail('Session mismatch must be rejected.');
        } catch (InvalidArgumentException $e) {
            assertSame('Registration challenge session mismatch.', $e->getMessage());
        }
    }

    /**
     * Ceremony started from client context A must reject completion from a different client binding.
     */
    public function testRegistrationBoundToClientBinding(FunctionalTester $I): void
    {
        [$container, $db, $service, $policy, $challenges, $passkeyRepo, $contextA] = $this->createContainer();
        [$accountId, $sessionA] = $this->createAccount($db);

        $start = $service->startRegistration($accountId, $sessionA, $contextA);
        $authenticator = new WebAuthnTestAuthenticator();
        $responseJson = $authenticator->makeRegistrationResponse($start['challenge']);

        // Context B has a different client instance ID and user agent
        $requestB = new ServerRequest(
            headers: [
                'User-Agent' => 'AnotherBrowser/2.0',
                'X-Client-Instance-ID' => 'b0000000-0000-4000-8000-000000000002',
            ],
            serverParams: ['REMOTE_ADDR' => '10.2.3.4', 'HTTP_USER_AGENT' => 'AnotherBrowser/2.0'],
            uri: 'http://localhost:5173/api/auth/passkeys',
            method: 'POST',
        );
        $contextB = $container->get(ClientContextResolver::class)->resolve($requestB);

        try {
            $service->completeRegistration($accountId, $sessionA, $responseJson, 'Key', $contextB);
            Assert::fail('Client binding mismatch must be rejected.');
        } catch (InvalidArgumentException $e) {
            assertSame('Registration challenge client binding mismatch.', $e->getMessage());
        }
    }

    /**
     * Failed attempts must be registered; exceeding maxAttempts exhausts the challenge.
     */
    public function testMaxAttemptsExhaustsChallenge(FunctionalTester $I): void
    {
        [$container, $db, $service, $policy, $challenges, $passkeyRepo, $context] = $this->createContainer();
        [$accountId, $sessionA] = $this->createAccount($db);

        $start = $service->startRegistration($accountId, $sessionA, $context);
        $challengeHash = hash('sha256', $start['challenge']);

        $authenticator = new WebAuthnTestAuthenticator();
        $validResponseJson = $authenticator->makeRegistrationResponse($start['challenge']);

        // Fabricate a malformed response that passes JSON decode but fails WebAuthn validation
        $malformedResponseJson = $authenticator->makeRegistrationResponse(
            $start['challenge'],
            rpId: 'wrong-rp-id.example',
        );

        // 3 failed attempts
        for ($i = 0; $i < 3; ++$i) {
            try {
                $service->completeRegistration($accountId, $sessionA, $malformedResponseJson, 'Key', $context);
            } catch (InvalidArgumentException) {
                // Expected failure for each attempt
            }
        }

        // Challenge should now be cancelled/exhausted in DB
        $stored = $challenges->findActiveBySecretHashHex($challengeHash);
        assertSame(null, $stored, 'Challenge must be exhausted after 3 failed attempts.');

        // Attempting with the valid response must now fail because challenge is gone
        try {
            $service->completeRegistration($accountId, $sessionA, $validResponseJson, 'Key', $context);
            Assert::fail('Attempt on exhausted challenge must fail.');
        } catch (InvalidArgumentException $e) {
            assertSame('Invalid or expired registration challenge.', $e->getMessage());
        }
    }

    /**
     * Registration must enforce User Verification = required; userVerified=false must be rejected.
     */
    public function testUserVerificationRequiredRejectsUVFalseOnRegistration(FunctionalTester $I): void
    {
        [$container, $db, $service, $policy, $challenges, $passkeyRepo, $context] = $this->createContainer();
        [$accountId, $sessionA] = $this->createAccount($db);

        $start = $service->startRegistration($accountId, $sessionA, $context);
        $authenticator = new WebAuthnTestAuthenticator();

        // Response without the UV bit set
        $noUvResponseJson = $authenticator->makeRegistrationResponse(
            $start['challenge'],
            userVerified: false,
        );

        try {
            $service->completeRegistration($accountId, $sessionA, $noUvResponseJson, 'Key', $context);
            Assert::fail('Registration with UV=false must be rejected.');
        } catch (InvalidArgumentException) {
            // Expected.
        }
    }

    /**
     * Authentication must enforce User Verification = required; userVerified=false must be rejected.
     */
    public function testUserVerificationRequiredRejectsUVFalseOnAuthentication(FunctionalTester $I): void
    {
        [$container, $db, $service, $policy, $challenges, $passkeyRepo, $context] = $this->createContainer();
        [$accountId, $sessionA] = $this->createAccount($db);

        // Register with UV=true
        $startReg = $service->startRegistration($accountId, $sessionA, $context);
        $authenticator = new WebAuthnTestAuthenticator();
        $regResponse = $authenticator->makeRegistrationResponse($startReg['challenge'], userVerified: true);
        $service->completeRegistration($accountId, $sessionA, $regResponse, 'UV Key', $context);

        // Start authentication
        $startAuth = $service->startAuthentication($context);
        $userHandle = $policy->makeUserHandle($accountId);

        // Authenticate with UV=false (UP=true, UV=false)
        $noUvAuthResponse = $authenticator->makeAuthenticationResponse(
            $startAuth['challenge'],
            $userHandle,
            userVerified: false,
        );

        try {
            $service->completeAuthentication($noUvAuthResponse, $context);
            Assert::fail('Authentication with UV=false must be rejected.');
        } catch (InvalidCredentialsException) {
            // Expected.
        }
    }

    /**
     * WebAuthn response exceeding 32 KiB must be immediately rejected before JSON parsing.
     */
    public function testOversizedPayloadRejected(FunctionalTester $I): void
    {
        [$container, $db, $service, $policy, $challenges, $passkeyRepo, $context] = $this->createContainer();
        [$accountId, $sessionA] = $this->createAccount($db);

        $start = $service->startRegistration($accountId, $sessionA, $context);

        // Payload with 35 KiB size
        $oversizedPayload = '{"id":"test","response":{"clientDataJSON":"' . str_repeat('a', 36_000) . '"}}';
        assertTrue(strlen($oversizedPayload) > 32_768);

        try {
            $service->completeRegistration($accountId, $sessionA, $oversizedPayload, 'Key', $context);
            Assert::fail('Oversized registration payload must be rejected.');
        } catch (InvalidArgumentException $e) {
            assertSame('WebAuthn response payload too large.', $e->getMessage());
        }

        try {
            $service->completeAuthentication($oversizedPayload, $context);
            Assert::fail('Oversized authentication payload must be rejected.');
        } catch (InvalidCredentialsException) {
            // Expected: completeAuthentication converts oversized payload to InvalidCredentialsException.
        }
    }

    /**
     * Non-standard / unknown transports must be filtered out; only allowed transports stored.
     */
    public function testUnknownTransportNotStored(FunctionalTester $I): void
    {
        [$container, $db, $service, $policy, $challenges, $passkeyRepo, $context] = $this->createContainer();
        [$accountId, $sessionA] = $this->createAccount($db);

        $start = $service->startRegistration($accountId, $sessionA, $context);
        $authenticator = new WebAuthnTestAuthenticator();

        // Pass mix of valid and unknown transports
        $responseJson = $authenticator->makeRegistrationResponse(
            $start['challenge'],
            transports: ['internal', 'fido-u2f-custom', 'smart-card', 'malicious-transport', 'hybrid'],
        );

        $passkey = $service->completeRegistration($accountId, $sessionA, $responseJson, 'Transport Key', $context);

        assertTrue(in_array('internal', $passkey->transports, true));
        assertTrue(in_array('smart-card', $passkey->transports, true));
        assertTrue(in_array('hybrid', $passkey->transports, true));
        assertFalse(in_array('fido-u2f-custom', $passkey->transports, true));
        assertFalse(in_array('malicious-transport', $passkey->transports, true));
    }

    /**
     * RateLimiter outage must fail-closed (RateLimiterUnavailableException propagated).
     */
    public function testRateLimiterOutageFailsClosed(FunctionalTester $I): void
    {
        [$container, $db, $service, $policy, $challenges, $passkeyRepo, $context] = $this->createContainer();
        [$accountId, $sessionA] = $this->createAccount($db);

        // Create failing rate limiter
        $failingLimiter = new class implements RateLimiterInterface {
            public function consume(string $scope, string $subjectDigest, int $limit, int $windowSeconds): bool
            {
                throw new RateLimiterUnavailableException('Redis cluster connection timed out.');
            }
        };

        // Construct service with failing limiter
        $runner = new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        );
        $container = $runner->getContainer();

        $failingService = new PasskeyService(
            passkeys: $passkeyRepo,
            challenges: $challenges,
            accounts: $container->get(AccountSecurityRepository::class),
            recentAuthentication: $container->get(RecentAuthenticationPolicy::class),
            notifications: $container->get(AccountSecurityNotifier::class),
            rateLimiter: $failingLimiter,
            ratePolicy: $container->get(AuthRateLimitPolicy::class),
            digests: $container->get(IdentifierDigestService::class),
            audit: $container->get(SecurityAuditService::class),
            clock: $container->get(ClockInterface::class),
            db: $db,
            webAuthnPolicy: $policy,
            mutationGuard: $container->get(CredentialMutationGuard::class),
            authFlow: $container->get(AuthenticationFlowService::class),
        );

        // startAuthentication must fail closed
        try {
            $failingService->startAuthentication($context);
            Assert::fail('Outage must fail closed.');
        } catch (RateLimiterUnavailableException) {
            // Expected.
        }

        // completeRegistration must fail closed
        $start = $service->startRegistration($accountId, $sessionA, $context);
        $authenticator = new WebAuthnTestAuthenticator();
        $responseJson = $authenticator->makeRegistrationResponse($start['challenge']);

        try {
            $failingService->completeRegistration($accountId, $sessionA, $responseJson, 'Key', $context);
            Assert::fail('Outage must fail closed.');
        } catch (RateLimiterUnavailableException) {
            // Expected.
        }

        // completeAuthentication must fail closed
        try {
            $failingService->completeAuthentication($responseJson, $context);
            Assert::fail('Outage must fail closed.');
        } catch (RateLimiterUnavailableException) {
            // Expected.
        }
    }
}
