<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Auth\Application\AuthChallengeRepository;
use App\Core\Auth\Application\CredentialMutationGuard;
use App\Core\Auth\Application\ExternalAuthService;
use App\Core\Auth\Application\ExternalIdentityProfile;
use App\Core\Auth\Application\ExternalIdentityRepository;
use App\Core\Auth\Application\LastAuthenticationMethodException;
use App\Core\Auth\Application\PasskeyRepository;
use App\Core\Auth\Application\PasskeyService;
use App\Core\Auth\Application\WebAuthnPolicy;
use App\Core\Client\Application\ClientContextResolver;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use App\Tests\Support\WebAuthnTestAuthenticator;
use HttpSoft\Message\ServerRequest;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;
use PHPUnit\Framework\Assert;

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
        $db->createCommand(
            "DELETE FROM core.persons WHERE given_name = 'Passkey Concurrency' AND family_name = 'Fixture' AND NOT EXISTS (SELECT 1 FROM core.accounts WHERE person_id = core.persons.id)",
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
        $personId = (string) $db->createCommand(
            "INSERT INTO core.persons (given_name, family_name) VALUES ('Passkey Concurrency', 'Fixture') RETURNING id::text",
        )->queryScalar();
        $accountId = (string) $db->createCommand(
            "INSERT INTO core.accounts (person_id, account_type, status, label) VALUES (CAST(:person AS uuid), 'human', 'active', 'Passkey Concurrency Account') RETURNING id::text",
            [':person' => $personId],
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
        [$accountId, $sessionId] = $this->createAccount($db1);

        $start = $service1->startRegistration($accountId, $sessionId, $ctx1);
        $authenticator = new WebAuthnTestAuthenticator();
        $responseJson = $authenticator->makeRegistrationResponse($start['challenge']);

        $this->race($db1, 'register', $start['challenge'], $responseJson, $accountId, $sessionId, $ctx1);
        assertSame(1, (int) $db1->createCommand(
            "SELECT count(*) FROM core.audit_events WHERE action='auth.passkey_registration.completed' AND actor_account_id=CAST(:id AS uuid)",
            [':id' => $accountId],
        )->queryScalar(), 'Only one registration audit effect may be committed.');

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

        $this->race($db1, 'authenticate', $startAuth['challenge'], $authJson, $accountId, $sessionId, $ctx1);
        assertSame(2, (int) $db1->createCommand(
            'SELECT count(*) FROM core.auth_sessions WHERE account_id=CAST(:id AS uuid)',
            [':id' => $accountId],
        )->queryScalar(), 'The original session plus exactly one passkey session must exist.');
    }

    /**
     * Sequential last-method regression: removing a passkey must leave the final identity protected.
     */
    public function testRevokeThenUnlinkPreservesLastAuthenticationMethod(FunctionalTester $I): void
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
        try {
            $extService1->unlink('google', $accountId, $sessionId, $ctx1);
            Assert::fail('Unlinking the last remaining login method must fail.');
        } catch (LastAuthenticationMethodException) {
            // Expected.
        }

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

    /** Two PHP processes, separate DB sessions and an observed lock wait before release. */
    private function race(
        ConnectionInterface $db,
        string $operation,
        string $challenge,
        string $response,
        string $account,
        string $session,
        \App\Core\Client\Domain\ClientContext $context,
    ): void {
        \App\Core\Shared\Infrastructure\TestRuntimeGuard::assertDestructiveOperationsAllowed($db);
        $workers = [];
        $transaction = $db->beginTransaction();
        try {
            $id = $db->createCommand(
                "SELECT id FROM core.auth_challenges WHERE secret_hash=decode(:hash,'hex') FOR UPDATE",
                [':hash' => hash('sha256', $challenge)],
            )->queryScalar();
            assertTrue(is_string($id), 'The contested challenge must exist.');
            $pids = [];
            for ($i = 0; $i < 2; ++$i) {
                $process = proc_open(
                    [PHP_BINARY, dirname(__DIR__) . '/Support/passkey-race-worker.php'],
                    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                    $pipes,
                );
                assertTrue(is_resource($process));
                $workers[] = [$process, $pipes];
                stream_set_timeout($pipes[1], 20);
                fwrite($pipes[0], json_encode(['operation' => $operation, 'account' => $account,
                    'session' => $session, 'response' => $response, 'ip' => $context->ipAddress,
                    'userAgent' => $context->userAgent], JSON_THROW_ON_ERROR) . "\n");
                fflush($pipes[0]);
                $ready = trim((string) fgets($pipes[1]));
                assertSame(1, preg_match('/^READY:([0-9]+)$/', $ready, $matches), $ready);
                $pids[] = (int) $matches[1];
            }
            assertTrue($pids[0] !== $pids[1], 'Workers must use independent database connections.');
            foreach ($workers as [, $pipes]) {
                fwrite($pipes[0], "GO\n");
                fflush($pipes[0]);
            }
            $deadline = microtime(true) + 10;
            $waiting = 0;
            do {
                $db->createCommand('SELECT pg_stat_clear_snapshot()')->queryScalar();
                $waiting = (int) $db->createCommand(
                    "SELECT count(*) FROM pg_stat_activity WHERE pid IN (CAST(:a AS integer),CAST(:b AS integer)) AND wait_event_type='Lock'",
                    [':a' => $pids[0], ':b' => $pids[1]],
                )->queryScalar();
                if ($waiting === 2) {
                    break;
                }
                usleep(20_000);
            } while (microtime(true) < $deadline);
            assertSame(2, $waiting, 'Both requests must reach database lock contention before release.');
            $transaction->commit();
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                fclose($pipes[0]);
                $result = json_decode((string) fgets($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                assertSame(0, proc_close($process), (string) $stderr);
                $results[] = $result['result'];
            }
            sort($results);
            assertSame(['rejected', 'won'], $results);
        } finally {
            if ($transaction->isActive()) {
                $transaction->rollBack();
            }
            foreach ($workers as [$process, $pipes]) {
                if (is_resource($process)) {
                    proc_terminate($process, 9);
                }
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process)) {
                    proc_close($process);
                }
            }
        }
    }

}
