<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Auth\Application\AuthChallengeRepository;
use App\Core\Auth\Application\CredentialMutationGuard;
use App\Core\Auth\Application\ExternalAuthService;
use App\Core\Auth\Application\ExternalIdentityProfile;
use App\Core\Auth\Application\ExternalIdentityRepository;
use App\Core\Auth\Application\InvalidCredentialsException;
use App\Core\Auth\Application\LastAuthenticationMethodException;
use App\Core\Auth\Application\PasskeyRepository;
use App\Core\Auth\Application\PasskeyService;
use App\Core\Auth\Application\WebAuthnPolicy;
use App\Core\Client\Application\ClientContextResolver;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use App\Tests\Support\WebAuthnTestAuthenticator;
use HttpSoft\Message\ServerRequest;
use InvalidArgumentException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function hash;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;
use function random_bytes;
use function random_int;

final readonly class PasskeyConcurrencyCest
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
        [$container, $db] = $this->createRuntime();
        $db->createCommand(
            "DELETE FROM core.outbox_events WHERE payload #>> '{recipient,address}' LIKE 'passkey-conc-%@example.test' OR payload->>'templateCode' LIKE 'auth.passkey.%'",
        )->execute();
        $db->createCommand(
            "DELETE FROM core.accounts WHERE label LIKE 'Passkey Concurrency Account%'",
        )->execute();
    }

    private function createRuntime(): array
    {
        $runner = new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        );
        $container = $runner->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $passkeyService = $container->get(PasskeyService::class);
        $externalAuthService = $container->get(ExternalAuthService::class);
        $webAuthnPolicy = $container->get(WebAuthnPolicy::class);
        $challenges = $container->get(AuthChallengeRepository::class);
        $passkeyRepo = $container->get(PasskeyRepository::class);
        $mutationGuard = $container->get(CredentialMutationGuard::class);

        $ip = '10.2.' . random_int(1, 250) . '.' . random_int(1, 250);
        $request = new ServerRequest(
            serverParams: ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'PasskeyConcurrencyTester/1.0'],
            uri: 'http://localhost:5173/api/auth/passkeys',
            method: 'POST',
        );
        $context = $container->get(ClientContextResolver::class)->resolve($request);

        return [
            $container,
            $db,
            $passkeyService,
            $externalAuthService,
            $webAuthnPolicy,
            $challenges,
            $passkeyRepo,
            $mutationGuard,
            $context,
        ];
    }

    private function createAccount(ConnectionInterface $db, bool $withPassword = true): array
    {
        $accountId = (string) $db->createCommand(
            "INSERT INTO core.accounts (account_type, status, label) VALUES ('service', 'active', 'Passkey Concurrency Account') RETURNING id::text",
        )->queryScalar();

        $email = 'passkey-conc-' . bin2hex(random_bytes(6)) . '@example.test';
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

        if ($withPassword) {
            $db->createCommand(
                "INSERT INTO core.auth_password_credentials (account_id, password_hash, password_algorithm) VALUES (CAST(:id AS uuid), :hash, 'argon2id')",
                [':id' => $accountId, ':hash' => password_hash('ConcPass123!', PASSWORD_ARGON2ID)],
            )->execute();
        }

        $sessionId = (string) $db->createCommand(
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

        return [$accountId, $sessionId];
    }

    /**
     * Item 1: Atomic CAS registration challenge consumption.
     * When two concurrent requests arrive with the same challenge, exactly ONE wins and
     * creates the passkey; the other is rejected because challenge is already consumed.
     */
    public function testConcurrentRegistrationChallengeOneWins(FunctionalTester $I): void
    {
        [$c1, $db1, $service1, , $policy1, $challenges1, , , $ctx1] = $this->createRuntime();
        [, , $service2] = $this->createRuntime();
        [$accountId, $sessionId] = $this->createAccount($db1);

        $start = $service1->startRegistration($accountId, $sessionId, $ctx1);
        $authenticator = new WebAuthnTestAuthenticator();
        $responseJson = $authenticator->makeRegistrationResponse($start['challenge']);

        $successCount = 0;
        $rejectionCount = 0;

        // Thread 1
        try {
            $service1->completeRegistration($accountId, $sessionId, $responseJson, 'Key 1', $ctx1);
            ++$successCount;
        } catch (InvalidArgumentException) {
            ++$rejectionCount;
        }

        // Thread 2 (concurrent attempt with same challenge)
        try {
            $service2->completeRegistration($accountId, $sessionId, $responseJson, 'Key 2', $ctx1);
            ++$successCount;
        } catch (InvalidArgumentException) {
            ++$rejectionCount;
        }

        assertSame(1, $successCount, 'Exactly one registration completion must succeed.');
        assertSame(1, $rejectionCount, 'The duplicate registration request must be rejected.');

        // Verify in DB that only 1 passkey was created
        $count = (int) $db1->createCommand(
            'SELECT count(*) FROM core.auth_passkeys WHERE account_id = CAST(:id AS uuid)',
            [':id' => $accountId],
        )->queryScalar();
        assertSame(1, $count);
    }

    /**
     * Item 1: Atomic CAS authentication challenge consumption.
     * When two concurrent assertion completions arrive with the same challenge,
     * exactly ONE succeeds in issuing a session; the second is rejected.
     */
    public function testConcurrentAuthenticationChallengeOneWins(FunctionalTester $I): void
    {
        [$c1, $db1, $service1, , $policy1, , , , $ctx1] = $this->createRuntime();
        [, , $service2] = $this->createRuntime();
        [$accountId, $sessionId] = $this->createAccount($db1);

        // Register passkey
        $startReg = $service1->startRegistration($accountId, $sessionId, $ctx1);
        $authenticator = new WebAuthnTestAuthenticator();
        $regJson = $authenticator->makeRegistrationResponse($startReg['challenge']);
        $service1->completeRegistration($accountId, $sessionId, $regJson, 'Auth Key', $ctx1);

        // Start authentication
        $startAuth = $service1->startAuthentication($ctx1);
        $userHandle = $policy1->makeUserHandle($accountId);
        $authJson = $authenticator->makeAuthenticationResponse($startAuth['challenge'], $userHandle, signCount: 1);

        $successCount = 0;
        $rejectionCount = 0;

        // Concurrent completions
        try {
            $service1->completeAuthentication($authJson, $ctx1);
            ++$successCount;
        } catch (InvalidCredentialsException) {
            ++$rejectionCount;
        }

        try {
            $service2->completeAuthentication($authJson, $ctx1);
            ++$successCount;
        } catch (InvalidCredentialsException) {
            ++$rejectionCount;
        }

        assertSame(1, $successCount, 'Exactly one authentication completion must succeed.');
        assertSame(1, $rejectionCount, 'The duplicate authentication request must be rejected.');
    }

    /**
     * Item 4: Last-method invariant race safety with CredentialMutationGuard.
     * Account has exactly 2 methods: 1 Passkey + 1 External Identity (no password).
     * If revoke and unlink run concurrently, at most one can succeed.
     * The account is NEVER left with zero authentication methods.
     */
    public function testConcurrentPasskeyRevokeAndIdentityUnlink(FunctionalTester $I): void
    {
        [$c1, $db1, $service1, $extService1, , , $passkeys1, $guard1, $ctx1] = $this->createRuntime();
        [$c2, $db2, $service2, $extService2] = $this->createRuntime();

        // Create account with NO password
        [$accountId, $sessionId] = $this->createAccount($db1, withPassword: false);

        // Method 1: Register Passkey
        $startReg = $service1->startRegistration($accountId, $sessionId, $ctx1);
        $authenticator = new WebAuthnTestAuthenticator();
        $regJson = $authenticator->makeRegistrationResponse($startReg['challenge']);
        $passkey = $service1->completeRegistration($accountId, $sessionId, $regJson, 'Only Passkey', $ctx1);

        // Method 2: Link Google External Identity
        $providerId = (string) $db1->createCommand(
            "SELECT id FROM core.auth_providers WHERE code = 'google'",
        )->queryScalar();
        $profile = new ExternalIdentityProfile(
            subject: 'conc-google-' . bin2hex(random_bytes(8)),
            email: 'conc-google@example.test',
            emailVerified: true,
            displayName: 'Conc Google User',
            avatarUrl: null,
            claims: [],
        );
        $c1->get(ExternalIdentityRepository::class)->link($accountId, $providerId, $profile);

        // Initial sanity check: exactly 2 methods
        $methodsCount = (int) $db1->createCommand(
            <<<'SQL'
            SELECT (
                (SELECT count(*) FROM core.auth_password_credentials WHERE account_id = CAST(:id AS uuid)) +
                (SELECT count(*) FROM core.auth_external_identities WHERE account_id = CAST(:id AS uuid)) +
                (SELECT count(*) FROM core.auth_passkeys WHERE account_id = CAST(:id AS uuid) AND revoked_at IS NULL)
            )
            SQL,
            [':id' => $accountId],
        )->queryScalar();
        assertSame(2, $methodsCount, 'Account must start with exactly 2 methods.');

        // Step 1: Revoke passkey (removes 1 method -> 1 method remains)
        $service1->revoke($passkey->id, $accountId, $sessionId, $ctx1);

        // Step 2: Attempt to unlink the only remaining identity -> MUST be rejected!
        $I->expectThrowable(
            LastAuthenticationMethodException::class,
            static function () use ($extService1, $accountId, $sessionId, $ctx1): void {
                $extService1->unlink('google', $accountId, $sessionId, $ctx1);
            },
        );

        // Final invariant check: account STILL has exactly 1 method (the Google identity)
        $remainingMethods = (int) $db1->createCommand(
            <<<'SQL'
            SELECT (
                (SELECT count(*) FROM core.auth_password_credentials WHERE account_id = CAST(:id AS uuid)) +
                (SELECT count(*) FROM core.auth_external_identities WHERE account_id = CAST(:id AS uuid)) +
                (SELECT count(*) FROM core.auth_passkeys WHERE account_id = CAST(:id AS uuid) AND revoked_at IS NULL)
            )
            SQL,
            [':id' => $accountId],
        )->queryScalar();
        assertSame(1, $remainingMethods, 'The last authentication method must remain active.');
    }

    /**
     * Item 6: Counter compare-and-set for hardware passkeys.
     * Tests PasskeyRepository::recordUsageCompareSet directly.
     * When two concurrent assertions arrive with expectedCounter = 10:
     * one updates to 11 (returns true); the second with expectedCounter = 10 returns false.
     */
    public function testCounterCompareSetPreventsStaleUpdate(FunctionalTester $I): void
    {
        [$c1, $db1, , , , , $passkeyRepo1] = $this->createRuntime();
        [, , , , , , $passkeyRepo2] = $this->createRuntime();
        [$accountId, $sessionId] = $this->createAccount($db1);

        // Create hardware passkey with sign_count = 10
        $credentialIdHex = bin2hex(random_bytes(32));
        $publicKeyHex = bin2hex(random_bytes(65));
        $passkey = $passkeyRepo1->create(
            accountId: $accountId,
            credentialIdHex: $credentialIdHex,
            publicKeyHex: $publicKeyHex,
            signCount: 10,
            aaguid: null,
            transports: ['usb'],
            label: 'YubiKey Hardware',
            backupEligible: false,
            backupState: false,
        );

        // First update: expected=10, new=11 -> succeeds
        $firstWins = $passkeyRepo1->recordUsageCompareSet(
            $passkey->id,
            expectedCounter: 10,
            newCounter: 11,
            newBackupState: false,
        );
        assertTrue($firstWins, 'First CAS update with expected=10 must succeed.');

        // Second update: still expected=10, new=12 -> must FAIL because DB sign_count is now 11
        $secondFails = $passkeyRepo2->recordUsageCompareSet(
            $passkey->id,
            expectedCounter: 10,
            newCounter: 12,
            newBackupState: false,
        );
        assertFalse($secondFails, 'Stale CAS update with expected=10 must return false.');

        // Verify DB counter is 11, NOT overwritten by 12
        $stored = $passkeyRepo1->findById($passkey->id);
        assertNotNull($stored);
        assertSame(11, $stored->signCount);
    }
}
