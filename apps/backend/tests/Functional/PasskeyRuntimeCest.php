<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Auth\Application\AuthChallengeRepository;
use App\Core\Auth\Application\InvalidCredentialsException;
use App\Core\Auth\Application\LastAuthenticationMethodException;
use App\Core\Auth\Application\PasskeyRepository;
use App\Core\Auth\Application\PasskeyService;
use App\Core\Auth\Application\TotpSecretCipher;
use App\Core\Auth\Application\WebAuthnPolicy;
use App\Core\Auth\Domain\Passkey;
use App\Core\Client\Application\ClientContextResolver;
use App\Core\Security\Application\SecurityAction;
use App\Core\Shared\Application\ConflictException;
use App\Core\Shared\Application\NotFoundException;
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
use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;
use function random_bytes;
use function random_int;

final readonly class PasskeyRuntimeCest
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
            "DELETE FROM core.outbox_events WHERE payload #>> '{recipient,address}' LIKE 'passkey-%@example.test' OR payload->>'templateCode' LIKE 'auth.passkey.%'",
        )->execute();
        $db->createCommand(
            "DELETE FROM core.accounts WHERE label LIKE 'Passkey Test Account%' OR label = 'Only Passkey Account'",
        )->execute();
        $db->createCommand(
            "DELETE FROM core.persons WHERE given_name = 'Passkey Runtime' AND family_name = 'Fixture' AND NOT EXISTS (SELECT 1 FROM core.accounts WHERE person_id = core.persons.id)",
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

        $ip = '10.0.' . random_int(1, 250) . '.' . random_int(1, 250);
        $request = new ServerRequest(
            serverParams: ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'PasskeyFunctionalTester/1.0'],
            uri: 'http://localhost:5173/api/auth/passkeys',
            method: 'POST',
        );
        $context = $container->get(ClientContextResolver::class)->resolve($request);

        return [$container, $db, $passkeyService, $webAuthnPolicy, $challenges, $passkeyRepo, $context];
    }

    private function createAccount(ConnectionInterface $db, bool $withPassword = true): array
    {
        $personId = (string) $db->createCommand(
            "INSERT INTO core.persons (given_name, family_name) VALUES ('Passkey Runtime', 'Fixture') RETURNING id::text",
        )->queryScalar();
        $accountId = (string) $db->createCommand(
            "INSERT INTO core.accounts (person_id, account_type, status, label) VALUES (CAST(:person AS uuid), 'human', 'active', 'Passkey Test Account') RETURNING id::text",
            [':person' => $personId],
        )->queryScalar();

        $email = 'passkey-' . bin2hex(random_bytes(6)) . '@example.test';
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
                [':id' => $accountId, ':hash' => password_hash('TestPass123!', PASSWORD_ARGON2ID)],
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

    public function testRegistrationStartAndCompleteSucceeds(FunctionalTester $I): void
    {
        [$container, $db, $service, $policy, $challenges, $passkeyRepo, $context] = $this->createContainer();
        [$accountId, $sessionId] = $this->createAccount($db);

        $start = $service->startRegistration($accountId, $sessionId, $context);
        assertNotNull($start['challenge']);
        assertEquals('localhost', $start['rp']['id']);
        assertFalse(empty($start['pubKeyCredParams']));

        // Verify challenge in DB by secret_hash
        $challengeHashHex = hash('sha256', $start['challenge']);
        $storedChallenge = $challenges->findActiveBySecretHashHex($challengeHashHex);
        assertNotNull($storedChallenge);
        assertSame('passkey_registration', $storedChallenge->challengeType);
        assertSame($accountId, $storedChallenge->accountId);

        // Complete registration
        $authenticator = new WebAuthnTestAuthenticator();
        $responseJson = $authenticator->makeRegistrationResponse($start['challenge'], backupEligible: true, backupState: true);

        $passkey = $service->completeRegistration($accountId, $sessionId, $responseJson, 'Primary Key', $context);
        assertSame('Primary Key', $passkey->label);
        assertSame($accountId, $passkey->accountId);
        assertTrue($passkey->backupEligible);
        assertTrue($passkey->backupState);
        assertSame(0, $passkey->signCount);

        // Verify challenge consumed
        $consumed = $challenges->findActiveBySecretHashHex($challengeHashHex);
        assertNull($consumed);

        // Verify outbox communication queued
        $messages = (int) $db->createCommand(
            "SELECT count(*) FROM core.outbox_events WHERE payload->>'templateCode' = 'auth.passkey.registered'",
        )->queryScalar();
        assertTrue($messages >= 1);
    }

    public function testDuplicateRegistrationFails(FunctionalTester $I): void
    {
        [$container, $db, $service, $policy, $challenges, $passkeyRepo, $context] = $this->createContainer();
        [$accountId, $sessionId] = $this->createAccount($db);

        $start1 = $service->startRegistration($accountId, $sessionId, $context);
        $authenticator = new WebAuthnTestAuthenticator();
        $responseJson1 = $authenticator->makeRegistrationResponse($start1['challenge']);
        $service->completeRegistration($accountId, $sessionId, $responseJson1, 'Key 1', $context);

        // Start another registration ceremony
        $start2 = $service->startRegistration($accountId, $sessionId, $context);
        $responseJson2 = $authenticator->makeRegistrationResponse($start2['challenge']);

        try {
            $service->completeRegistration($accountId, $sessionId, $responseJson2, 'Key 2', $context);
            Assert::fail('Expected ConflictException for duplicate credential ID.');
        } catch (ConflictException) {
            assertTrue(true);
        }
    }

    public function testReplayRegistrationChallengeFails(FunctionalTester $I): void
    {
        [$container, $db, $service, $policy, $challenges, $passkeyRepo, $context] = $this->createContainer();
        [$accountId, $sessionId] = $this->createAccount($db);

        $start = $service->startRegistration($accountId, $sessionId, $context);
        $authenticator = new WebAuthnTestAuthenticator();
        $responseJson = $authenticator->makeRegistrationResponse($start['challenge']);

        $service->completeRegistration($accountId, $sessionId, $responseJson, 'Key 1', $context);

        // Attempt to replay the same registration response
        try {
            $service->completeRegistration($accountId, $sessionId, $responseJson, 'Key 1 Replay', $context);
            Assert::fail('Expected InvalidArgumentException on replayed challenge.');
        } catch (InvalidArgumentException) {
            assertTrue(true);
        }
    }

    public function testExpiredRegistrationChallengeFails(FunctionalTester $I): void
    {
        [$container, $db, $service, $policy, $challenges, $passkeyRepo, $context] = $this->createContainer();
        [$accountId, $sessionId] = $this->createAccount($db);

        $start = $service->startRegistration($accountId, $sessionId, $context);

        // Artificially expire the challenge in DB
        $challengeHashHex = hash('sha256', $start['challenge']);
        $db->createCommand(
            "UPDATE core.auth_challenges SET created_at = now() - interval '2 hours', expires_at = now() - interval '1 hour' WHERE secret_hash = decode(:hash, 'hex')",
            [':hash' => $challengeHashHex],
        )->execute();

        $authenticator = new WebAuthnTestAuthenticator();
        $responseJson = $authenticator->makeRegistrationResponse($start['challenge']);

        try {
            $service->completeRegistration($accountId, $sessionId, $responseJson, 'Key Expired', $context);
            Assert::fail('Expected InvalidArgumentException on expired challenge.');
        } catch (InvalidArgumentException) {
            assertTrue(true);
        }
    }

    public function testRegistrationWithWrongOriginOrRpIdFails(FunctionalTester $I): void
    {
        [$container, $db, $service, $policy, $challenges, $passkeyRepo, $context] = $this->createContainer();
        [$accountId, $sessionId] = $this->createAccount($db);

        // Wrong Origin
        $start1 = $service->startRegistration($accountId, $sessionId, $context);
        $authenticator1 = new WebAuthnTestAuthenticator();
        $responseJson1 = $authenticator1->makeRegistrationResponse($start1['challenge'], origin: 'https://evil.com');

        try {
            $service->completeRegistration($accountId, $sessionId, $responseJson1, 'Wrong Origin', $context);
            Assert::fail('Expected InvalidArgumentException for wrong origin.');
        } catch (InvalidArgumentException) {
            assertTrue(true);
        }

        // Wrong RP ID
        $start2 = $service->startRegistration($accountId, $sessionId, $context);
        $authenticator2 = new WebAuthnTestAuthenticator();
        $responseJson2 = $authenticator2->makeRegistrationResponse($start2['challenge'], rpId: 'attacker.com');

        try {
            $service->completeRegistration($accountId, $sessionId, $responseJson2, 'Wrong RP ID', $context);
            Assert::fail('Expected InvalidArgumentException for wrong RP ID.');
        } catch (InvalidArgumentException) {
            assertTrue(true);
        }
    }

    public function testDiscoverableAuthenticationFlowSuccess(FunctionalTester $I): void
    {
        [$container, $db, $service, $policy, $challenges, $passkeyRepo, $context] = $this->createContainer();
        [$accountId, $sessionId] = $this->createAccount($db);

        $startReg = $service->startRegistration($accountId, $sessionId, $context);
        $authenticator = new WebAuthnTestAuthenticator();
        $regJson = $authenticator->makeRegistrationResponse($startReg['challenge']);
        $service->completeRegistration($accountId, $sessionId, $regJson, 'Auth Test Key', $context);

        // Discoverable Authentication Start
        $startAuth = $service->startAuthentication($context);
        assertNotNull($startAuth['challenge']);

        // Authenticator signs assertion with opaque userHandle
        $userHandle = $policy->makeUserHandle($accountId);
        $authJson = $authenticator->makeAuthenticationResponse($startAuth['challenge'], $userHandle, signCount: 1);

        // Complete Authentication
        $result = $service->completeAuthentication($authJson, $context);
        assertSame('authenticated', $result->status);
        assertNotNull($result->authentication);
        $payload = $result->toArray();
        assertNotNull($payload['accessToken']);
        assertNotNull($payload['refreshToken']);
        assertNotNull($payload['sessionId']);

        // Verify passkey usage recorded
        $passkeys = $passkeyRepo->findActivePasskeysByAccountId($accountId);
        assertSame(1, count($passkeys));
        assertSame(1, $passkeys[0]->signCount);
        assertNotNull($passkeys[0]->lastUsedAt);
    }

    public function testAuthenticationDoesNotBypassTotpMfa(FunctionalTester $I): void
    {
        [$container, $db, $service, $policy, $challenges, $passkeyRepo, $context] = $this->createContainer();
        [$accountId, $sessionId] = $this->createAccount($db);

        // Enable TOTP MFA on account directly in DB
        $cipher = $container->get(TotpSecretCipher::class);
        $encrypted = $cipher->encrypt(random_bytes(20));
        $db->createCommand(
            <<<'SQL'
            INSERT INTO core.auth_totp_factors (
                account_id, label, secret_encrypted, algorithm, digits, period_seconds,
                verified_at, secret_key_version
            )
            VALUES (
                CAST(:account_id AS uuid), 'Authenticator App', decode(:secret, 'hex'),
                'sha1', 6, 30, now(), :key_version
            )
            SQL,
            [
                ':account_id' => $accountId,
                ':secret' => $encrypted->ciphertextHex,
                ':key_version' => $encrypted->keyVersion,
            ],
        )->execute();

        // Register passkey
        $startReg = $service->startRegistration($accountId, $sessionId, $context);
        $authenticator = new WebAuthnTestAuthenticator();
        $regJson = $authenticator->makeRegistrationResponse($startReg['challenge']);
        $service->completeRegistration($accountId, $sessionId, $regJson, 'MFA Test Key', $context);

        // Authenticate with passkey
        $startAuth = $service->startAuthentication($context);
        $userHandle = $policy->makeUserHandle($accountId);
        $authJson = $authenticator->makeAuthenticationResponse($startAuth['challenge'], $userHandle, signCount: 1);

        $result = $service->completeAuthentication($authJson, $context);
        assertSame('mfa_required', $result->status);
        assertNotNull($result->mfaChallengeToken);
        assertNull($result->authentication);
    }

    public function testRevokedPasskeyCannotAuthenticate(FunctionalTester $I): void
    {
        [$container, $db, $service, $policy, $challenges, $passkeyRepo, $context] = $this->createContainer();
        [$accountId, $sessionId] = $this->createAccount($db);

        $startReg = $service->startRegistration($accountId, $sessionId, $context);
        $authenticator = new WebAuthnTestAuthenticator();
        $regJson = $authenticator->makeRegistrationResponse($startReg['challenge']);
        $passkey = $service->completeRegistration($accountId, $sessionId, $regJson, 'Revoked Key', $context);

        // Revoke the passkey
        $service->revoke($passkey->id, $accountId, $sessionId, $context);

        // Try to authenticate
        $startAuth = $service->startAuthentication($context);
        $userHandle = $policy->makeUserHandle($accountId);
        $authJson = $authenticator->makeAuthenticationResponse($startAuth['challenge'], $userHandle, signCount: 1);

        try {
            $service->completeAuthentication($authJson, $context);
            Assert::fail('Expected InvalidCredentialsException for revoked passkey.');
        } catch (InvalidCredentialsException) {
            assertTrue(true);
        }
    }

    public function testUserHandleMismatchRejected(FunctionalTester $I): void
    {
        [$container, $db, $service, $policy, $challenges, $passkeyRepo, $context] = $this->createContainer();
        [$accountA, $sessionA] = $this->createAccount($db);
        [$accountB, $sessionB] = $this->createAccount($db);

        // Register passkey for Account A
        $startReg = $service->startRegistration($accountA, $sessionA, $context);
        $authenticator = new WebAuthnTestAuthenticator();
        $regJson = $authenticator->makeRegistrationResponse($startReg['challenge']);
        $service->completeRegistration($accountA, $sessionA, $regJson, 'Account A Key', $context);

        // Authenticate with userHandle belonging to Account B
        $startAuth = $service->startAuthentication($context);
        $userHandleB = $policy->makeUserHandle($accountB);
        $authJson = $authenticator->makeAuthenticationResponse($startAuth['challenge'], $userHandleB, signCount: 1);

        try {
            $service->completeAuthentication($authJson, $context);
            Assert::fail('Expected InvalidCredentialsException for userHandle mismatch.');
        } catch (InvalidCredentialsException) {
            assertTrue(true);
        }
    }

    public function testSyncedPasskeyZeroCounterSucceeds(FunctionalTester $I): void
    {
        [$container, $db, $service, $policy, $challenges, $passkeyRepo, $context] = $this->createContainer();
        [$accountId, $sessionId] = $this->createAccount($db);

        // Register synced passkey (backupEligible = true, backupState = true)
        $startReg = $service->startRegistration($accountId, $sessionId, $context);
        $authenticator = new WebAuthnTestAuthenticator();
        $regJson = $authenticator->makeRegistrationResponse(
            $startReg['challenge'],
            backupEligible: true,
            backupState: true,
        );
        $service->completeRegistration($accountId, $sessionId, $regJson, 'iCloud Keychain', $context);

        // Login 1 with signCount = 0
        $startAuth1 = $service->startAuthentication($context);
        $userHandle = $policy->makeUserHandle($accountId);
        $authJson1 = $authenticator->makeAuthenticationResponse(
            $startAuth1['challenge'],
            $userHandle,
            signCount: 0,
            backupEligible: true,
            backupState: true,
        );
        $res1 = $service->completeAuthentication($authJson1, $context);
        assertSame('authenticated', $res1->status);

        // Login 2 again with signCount = 0 (standard for synced passkeys)
        $startAuth2 = $service->startAuthentication($context);
        $authJson2 = $authenticator->makeAuthenticationResponse(
            $startAuth2['challenge'],
            $userHandle,
            signCount: 0,
            backupEligible: true,
            backupState: true,
        );
        $res2 = $service->completeAuthentication($authJson2, $context);
        assertSame('authenticated', $res2->status);
    }

    public function testCounterAnomalyRejectedForHardwareKey(FunctionalTester $I): void
    {
        [$container, $db, $service, $policy, $challenges, $passkeyRepo, $context] = $this->createContainer();
        [$accountId, $sessionId] = $this->createAccount($db);

        // Register hardware passkey (backupEligible = false)
        $startReg = $service->startRegistration($accountId, $sessionId, $context);
        $authenticator = new WebAuthnTestAuthenticator();
        $regJson = $authenticator->makeRegistrationResponse(
            $startReg['challenge'],
            backupEligible: false,
            backupState: false,
        );
        $service->completeRegistration($accountId, $sessionId, $regJson, 'YubiKey 5', $context);

        // Login 1 with signCount = 10
        $startAuth1 = $service->startAuthentication($context);
        $userHandle = $policy->makeUserHandle($accountId);
        $authJson1 = $authenticator->makeAuthenticationResponse($startAuth1['challenge'], $userHandle, signCount: 10);
        $res1 = $service->completeAuthentication($authJson1, $context);
        assertSame('authenticated', $res1->status);

        // Login 2 with cloned/repeated counter = 10 (anomaly!)
        $startAuth2 = $service->startAuthentication($context);
        $authJson2 = $authenticator->makeAuthenticationResponse($startAuth2['challenge'], $userHandle, signCount: 10);

        try {
            $service->completeAuthentication($authJson2, $context);
            Assert::fail('Expected InvalidCredentialsException on counter anomaly.');
        } catch (InvalidCredentialsException) {
            assertTrue(true);
        }

        // Verify PASSKEY_COUNTER_ANOMALY audit recorded
        $count = (int) $db->createCommand(
            "SELECT count(*) FROM core.audit_events WHERE action = :action AND actor_account_id = CAST(:id AS uuid)",
            [':action' => SecurityAction::PASSKEY_COUNTER_ANOMALY, ':id' => $accountId],
        )->queryScalar();
        assertTrue($count >= 1);
    }

    public function testManagementRenameAndRevokeOwnOnly(FunctionalTester $I): void
    {
        [$container, $db, $service, $policy, $challenges, $passkeyRepo, $context] = $this->createContainer();
        [$accountA, $sessionA] = $this->createAccount($db);
        [$accountB, $sessionB] = $this->createAccount($db);

        $startReg = $service->startRegistration($accountA, $sessionA, $context);
        $authenticator = new WebAuthnTestAuthenticator();
        $regJson = $authenticator->makeRegistrationResponse($startReg['challenge']);
        $passkey = $service->completeRegistration($accountA, $sessionA, $regJson, 'Original Label', $context);

        // Account B tries to rename Account A's passkey -> NotFoundException
        try {
            $service->rename($passkey->id, $accountB, 'Hacked Label', $context);
            Assert::fail('Expected NotFoundException when renaming another account passkey.');
        } catch (NotFoundException) {
            assertTrue(true);
        }

        // Account A renames its passkey
        $renamed = $service->rename($passkey->id, $accountA, 'New Label', $context);
        assertSame('New Label', $renamed->label);

        // Account B tries to revoke Account A's passkey -> NotFoundException
        try {
            $service->revoke($passkey->id, $accountB, $sessionB, $context);
            Assert::fail('Expected NotFoundException when revoking another account passkey.');
        } catch (NotFoundException) {
            assertTrue(true);
        }

        // Account A revokes its passkey
        $service->revoke($passkey->id, $accountA, $sessionA, $context);
        $list = $service->listPasskeys($accountA);
        assertSame(0, count($list));
    }

    public function testCannotRevokeLastAuthenticationMethod(FunctionalTester $I): void
    {
        [$container, $db, $service, $policy, $challenges, $passkeyRepo, $context] = $this->createContainer();
        // Create account WITHOUT password
        [$accountId, $sessionId] = $this->createAccount($db, withPassword: false);

        $startReg = $service->startRegistration($accountId, $sessionId, $context);
        $authenticator = new WebAuthnTestAuthenticator();
        $regJson = $authenticator->makeRegistrationResponse($startReg['challenge']);
        $passkey = $service->completeRegistration($accountId, $sessionId, $regJson, 'Only Method', $context);

        // Attempting to revoke this only method should throw LastAuthenticationMethodException
        try {
            $service->revoke($passkey->id, $accountId, $sessionId, $context);
            Assert::fail('Expected LastAuthenticationMethodException when revoking the only authentication method.');
        } catch (LastAuthenticationMethodException) {
            assertTrue(true);
        }
    }

    public function testAuditLogsContainNoSecrets(FunctionalTester $I): void
    {
        [$container, $db, $service, $policy, $challenges, $passkeyRepo, $context] = $this->createContainer();
        [$accountId, $sessionId] = $this->createAccount($db);

        $startReg = $service->startRegistration($accountId, $sessionId, $context);
        $authenticator = new WebAuthnTestAuthenticator();
        $regJson = $authenticator->makeRegistrationResponse($startReg['challenge']);
        $passkey = $service->completeRegistration($accountId, $sessionId, $regJson, 'Audit Key', $context);

        $startAuth = $service->startAuthentication($context);
        $userHandle = $policy->makeUserHandle($accountId);
        $authJson = $authenticator->makeAuthenticationResponse($startAuth['challenge'], $userHandle, signCount: 1);
        $service->completeAuthentication($authJson, $context);

        $rows = $db->createCommand(
            "SELECT action, metadata::text FROM core.audit_events WHERE resource_type = 'core.auth_passkey' OR resource_type = 'core.account'",
        )->queryAll();

        foreach ($rows as $row) {
            $meta = (string) $row['metadata'];
            assertFalse(str_contains($meta, 'clientDataJSON'));
            assertFalse(str_contains($meta, 'authenticatorData'));
            assertFalse(str_contains($meta, 'signature'));
            assertFalse(str_contains($meta, 'attestationObject'));
            assertFalse(str_contains($meta, 'private_key'));
            assertFalse(str_contains($meta, 'session_token'));
        }
    }
}
