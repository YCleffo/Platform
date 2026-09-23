<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use App\Tests\Support\WebAuthnTestAuthenticator;
use Codeception\Util\HttpCode;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;
use App\Environment;

use function dirname;
use function json_decode;
use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

use const JSON_THROW_ON_ERROR;

final readonly class PasskeyApiCest
{
    public function _before(ApiTester $I): void
    {
        $I->cleanupCoreTestFixtures();
        try {
            $redis = new \Predis\Client([
                'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('REDIS_PORT') ?: 6379),
            ]);
            $redis->flushall();
        } catch (\Throwable) {
        }
    }

    public function _after(ApiTester $I): void
    {
        $I->cleanupCoreTestFixtures();
    }

    /** @return array<string, mixed> */
    private function response(ApiTester $I): array
    {
        /** @var array<string, mixed> */
        return json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function registrationStartRequiresAuthentication(ApiTester $I): void
    {
        $I->sendPOST('/api/auth/passkeys/registration/start');
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $I->seeResponseContainsJson(['error' => ['code' => 'auth.required']]);
    }

    public function registrationCeremonyEndToEnd(ApiTester $I): void
    {
        $fixture = $I->havePasswordAccount();
        $I->amAuthenticatedAccount($fixture['accountId']);

        // 1. Start Registration
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/auth/passkeys/registration/start');
        $I->seeResponseCodeIs(HttpCode::OK);
        $startData = $this->response($I)['data'];
        assertArrayHasKey('challenge', $startData);
        assertArrayHasKey('rp', $startData);
        assertArrayHasKey('user', $startData);
        assertEquals('localhost', $startData['rp']['id']);

        // 2. Complete Registration
        $authenticator = new WebAuthnTestAuthenticator();
        $regResponseJson = $authenticator->makeRegistrationResponse(
            $startData['challenge'],
            backupEligible: true,
            backupState: true,
        );

        $I->sendPOST('/api/auth/passkeys/registration/complete', [
            'responseJson' => $regResponseJson,
            'label' => 'MacBook Touch ID',
        ]);
        $I->seeResponseCodeIs(HttpCode::CREATED);
        $createdData = $this->response($I)['data'];
        assertArrayHasKey('id', $createdData);
        assertSame('MacBook Touch ID', $createdData['label']);
        assertTrue($createdData['backupEligible']);
        assertTrue($createdData['backupState']);

        // 3. Duplicate Registration is rejected with 409
        $I->sendPOST('/api/auth/passkeys/registration/start');
        $I->seeResponseCodeIs(HttpCode::OK);
        $start2 = $this->response($I)['data'];

        $dupResponseJson = $authenticator->makeRegistrationResponse($start2['challenge']);
        $I->sendPOST('/api/auth/passkeys/registration/complete', [
            'responseJson' => $dupResponseJson,
            'label' => 'Duplicate Key',
        ]);
        $I->seeResponseCodeIs(HttpCode::CONFLICT);
        $I->seeResponseContainsJson(['error' => ['code' => 'auth.passkey.conflict']]);

        // Verify Russian localization
        $I->haveHttpHeader('Accept-Language', 'ru-RU');
        $I->sendPOST('/api/auth/passkeys/registration/complete', [
            'responseJson' => $dupResponseJson,
            'label' => 'Duplicate Key RU',
        ]);
        $I->seeResponseCodeIs(HttpCode::CONFLICT);
        $I->seeResponseContainsJson([
            'error' => [
                'code' => 'auth.passkey.conflict',
                'message' => 'Этот ключ доступа (Passkey) уже зарегистрирован.',
            ],
        ]);
    }

    public function registrationReplayAndInvalidAttestationRejected(ApiTester $I): void
    {
        $fixture = $I->havePasswordAccount();
        $I->amAuthenticatedAccount($fixture['accountId']);

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/auth/passkeys/registration/start');
        $I->seeResponseCodeIs(HttpCode::OK);
        $startData = $this->response($I)['data'];

        $authenticator = new WebAuthnTestAuthenticator();
        $regResponseJson = $authenticator->makeRegistrationResponse($startData['challenge']);

        // Complete successfully
        $I->sendPOST('/api/auth/passkeys/registration/complete', [
            'responseJson' => $regResponseJson,
            'label' => 'Valid Key',
        ]);
        $I->seeResponseCodeIs(HttpCode::CREATED);

        // Replay same response -> 400 Bad Request
        $I->sendPOST('/api/auth/passkeys/registration/complete', [
            'responseJson' => $regResponseJson,
            'label' => 'Replay Key',
        ]);
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContainsJson(['error' => ['code' => 'auth.passkey.invalid']]);
    }

    public function discoverableAuthenticationFlow(ApiTester $I): void
    {
        $fixture = $I->havePasswordAccount();
        $I->amAuthenticatedAccount($fixture['accountId']);

        // Register passkey
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/auth/passkeys/registration/start');
        $startReg = $this->response($I)['data'];
        $authenticator = new WebAuthnTestAuthenticator();
        $regJson = $authenticator->makeRegistrationResponse($startReg['challenge']);
        $I->sendPOST('/api/auth/passkeys/registration/complete', [
            'responseJson' => $regJson,
            'label' => 'Auth Key',
        ]);
        $I->seeResponseCodeIs(HttpCode::CREATED);

        // Reset Authorization header (unauthenticated client)
        $I->haveHttpHeader('Authorization', '');

        // 1. Start Authentication
        $I->sendPOST('/api/auth/passkeys/authentication/start');
        $I->seeResponseCodeIs(HttpCode::OK);
        $startAuth = $this->response($I)['data'];
        assertArrayHasKey('challenge', $startAuth);
        assertEquals('localhost', $startAuth['rpId']);

        // 2. Compute opaque deterministic userHandle
        $runner = new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv());
        $policy = $runner->getContainer()->get(\App\Core\Auth\Application\WebAuthnPolicy::class);
        $userHandle = $policy->makeUserHandle($fixture['accountId']);

        // 3. Failed Authentication (tampered signature) -> 401
        $badAuthJson = $authenticator->makeAuthenticationResponse(
            $startAuth['challenge'],
            $userHandle,
            signCount: 1,
            tamperSig: true,
        );
        $I->sendPOST('/api/auth/passkeys/authentication/complete', [
            'responseJson' => $badAuthJson,
        ]);
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $I->seeResponseContainsJson(['error' => ['code' => 'auth.invalid_credentials']]);

        // 4. Successful Authentication
        $I->sendPOST('/api/auth/passkeys/authentication/start');
        $startAuth2 = $this->response($I)['data'];
        $goodAuthJson = $authenticator->makeAuthenticationResponse(
            $startAuth2['challenge'],
            $userHandle,
            signCount: 1,
        );
        $I->sendPOST('/api/auth/passkeys/authentication/complete', [
            'responseJson' => $goodAuthJson,
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $authData = $this->response($I)['data'];
        assertSame('authenticated', $authData['status']);
        assertArrayHasKey('accessToken', $authData);
        assertArrayHasKey('refreshToken', $authData);
        assertArrayHasKey('sessionId', $authData);
    }

    public function authenticationWithTotpMfaRequiresSecondFactor(ApiTester $I): void
    {
        $fixture = $I->havePasswordAccount();
        $I->amAuthenticatedAccount($fixture['accountId']);

        // Enable TOTP MFA
        $I->enableTotp($fixture['accountId']);

        // Register passkey
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/auth/passkeys/registration/start');
        $startReg = $this->response($I)['data'];
        $authenticator = new WebAuthnTestAuthenticator();
        $regJson = $authenticator->makeRegistrationResponse($startReg['challenge']);
        $I->sendPOST('/api/auth/passkeys/registration/complete', [
            'responseJson' => $regJson,
            'label' => 'MFA Key',
        ]);
        $I->seeResponseCodeIs(HttpCode::CREATED);

        // Reset auth header and authenticate with passkey
        $I->haveHttpHeader('Authorization', '');
        $I->sendPOST('/api/auth/passkeys/authentication/start');
        $startAuth = $this->response($I)['data'];

        $runner = new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv());
        $policy = $runner->getContainer()->get(\App\Core\Auth\Application\WebAuthnPolicy::class);
        $userHandle = $policy->makeUserHandle($fixture['accountId']);
        $authJson = $authenticator->makeAuthenticationResponse($startAuth['challenge'], $userHandle, signCount: 1);

        $I->sendPOST('/api/auth/passkeys/authentication/complete', [
            'responseJson' => $authJson,
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $data = $this->response($I)['data'];
        assertSame('mfa_required', $data['status']);
        assertArrayHasKey('challengeToken', $data);
        assertArrayNotHasKey('accessToken', $data);
        assertArrayNotHasKey('refreshToken', $data);
    }

    public function passkeyManagementListRenameAndRevoke(ApiTester $I): void
    {
        $accountA = $I->havePasswordAccount();
        $accountB = $I->havePasswordAccount();

        // Register passkey for Account A
        $I->amAuthenticatedAccount($accountA['accountId']);
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/auth/passkeys/registration/start');
        $startReg = $this->response($I)['data'];
        $authenticator = new WebAuthnTestAuthenticator();
        $regJson = $authenticator->makeRegistrationResponse($startReg['challenge']);
        $I->sendPOST('/api/auth/passkeys/registration/complete', [
            'responseJson' => $regJson,
            'label' => 'Account A Key',
        ]);
        $I->seeResponseCodeIs(HttpCode::CREATED);
        $passkeyId = (string) $this->response($I)['data']['id'];

        // 1. GET /api/auth/me/passkeys returns list
        $I->sendGET('/api/auth/me/passkeys');
        $I->seeResponseCodeIs(HttpCode::OK);
        $list = $this->response($I)['data']['items'];
        assertCount(1, $list);
        assertSame($passkeyId, $list[0]['id']);
        assertSame('Account A Key', $list[0]['label']);

        // 2. Account B cannot see, rename, or revoke Account A's passkey
        $I->amAuthenticatedAccount($accountB['accountId']);
        $I->sendGET('/api/auth/me/passkeys');
        $I->seeResponseCodeIs(HttpCode::OK);
        assertCount(0, $this->response($I)['data']['items']);

        $I->sendPATCH('/api/auth/me/passkeys/' . $passkeyId, ['label' => 'Hacked']);
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->seeResponseContainsJson(['error' => ['code' => 'auth.passkey.not_found']]);

        $I->sendDELETE('/api/auth/me/passkeys/' . $passkeyId);
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->seeResponseContainsJson(['error' => ['code' => 'auth.passkey.not_found']]);

        // 3. Account A renames passkey
        $I->amAuthenticatedAccount($accountA['accountId']);
        $I->sendPATCH('/api/auth/me/passkeys/' . $passkeyId, ['label' => 'Renamed Key']);
        $I->seeResponseCodeIs(HttpCode::OK);
        assertSame('Renamed Key', $this->response($I)['data']['label']);

        // 4. Account A revokes passkey
        $I->sendDELETE('/api/auth/me/passkeys/' . $passkeyId);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => ['status' => 'revoked']]);

        // List is now empty
        $I->sendGET('/api/auth/me/passkeys');
        $I->seeResponseCodeIs(HttpCode::OK);
        assertCount(0, $this->response($I)['data']['items']);
    }

    public function lastAuthenticationMethodCannotBeRevoked(ApiTester $I): void
    {
        // Account with passkey only (no password, no external identity)
        $runner = new HttpApplicationRunner(rootPath: dirname(__DIR__, 2), environment: Environment::appEnv());
        $db = $runner->getContainer()->get(ConnectionInterface::class);

        $accountId = (string) $db->createCommand(
            "INSERT INTO core.accounts (account_type, status, label) VALUES ('service', 'active', 'Only Passkey Account') RETURNING id::text",
        )->queryScalar();

        $I->amAuthenticatedAccount($accountId);
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/auth/passkeys/registration/start');
        $startReg = $this->response($I)['data'];
        $authenticator = new WebAuthnTestAuthenticator();
        $regJson = $authenticator->makeRegistrationResponse($startReg['challenge']);
        $I->sendPOST('/api/auth/passkeys/registration/complete', [
            'responseJson' => $regJson,
            'label' => 'Only Key',
        ]);
        $I->seeResponseCodeIs(HttpCode::CREATED);
        $passkeyId = (string) $this->response($I)['data']['id'];

        // Attempting to delete this passkey should return 409 Conflict with auth.identity.last_method
        $I->sendDELETE('/api/auth/me/passkeys/' . $passkeyId);
        $I->seeResponseCodeIs(HttpCode::CONFLICT);
        $I->seeResponseContainsJson(['error' => ['code' => 'auth.identity.last_method']]);
    }
}
