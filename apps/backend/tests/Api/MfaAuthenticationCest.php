<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Core\Auth\Application\Base32;
use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class MfaAuthenticationCest
{
    public function _after(ApiTester $I): void
    {
        $I->cleanupCoreTestFixtures();
    }

    public function passwordFirstFactorCannotIssueTokensBeforeTotp(ApiTester $I): void
    {
        $fixture = $I->havePasswordAccount();
        $mfa = $I->enableTotp($fixture['accountId']);
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/auth/login', [
            'identifierType' => 'email',
            'identifier' => $fixture['email'],
            'password' => $fixture['password'],
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $first = $this->response($I)['data'];
        assertSame('mfa_required', $first['status']);
        assertArrayNotHasKey('accessToken', $first);
        assertArrayNotHasKey('refreshToken', $first);

        $I->sendPOST('/api/auth/mfa/verify', [
            'challengeToken' => $first['challengeToken'],
            'totpCode' => '000000',
        ]);
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);

        $I->sendPOST('/api/auth/mfa/verify', [
            'challengeToken' => $first['challengeToken'],
            'totpCode' => $mfa['totpCode'],
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $completed = $this->response($I)['data'];
        assertSame('authenticated', $completed['status']);
        assertTrue($I->sessionHasMfa((string) $completed['sessionId']));

        $I->sendPOST('/api/auth/login', [
            'identifierType' => 'email',
            'identifier' => $fixture['email'],
            'password' => $fixture['password'],
        ]);
        $second = $this->response($I)['data'];
        $I->sendPOST('/api/auth/mfa/verify', [
            'challengeToken' => $second['challengeToken'],
            'totpCode' => $mfa['totpCode'],
        ]);
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
    }

    public function recoveryCodeIsSingleUse(ApiTester $I): void
    {
        $fixture = $I->havePasswordAccount();
        $mfa = $I->enableTotp($fixture['accountId']);
        $I->haveHttpHeader('Content-Type', 'application/json');
        $challenge = $this->loginChallenge($I, $fixture);
        $I->sendPOST('/api/auth/mfa/verify', [
            'challengeToken' => $challenge,
            'recoveryCode' => $mfa['recoveryCode'],
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);

        $challenge = $this->loginChallenge($I, $fixture);
        $I->sendPOST('/api/auth/mfa/verify', [
            'challengeToken' => $challenge,
            'recoveryCode' => $mfa['recoveryCode'],
        ]);
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
    }

    public function totpSetupEncryptsSecretAndSecuresExistingSession(ApiTester $I): void
    {
        $fixture = $I->havePasswordAccount();
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/auth/login', [
            'identifierType' => 'email',
            'identifier' => $fixture['email'],
            'password' => $fixture['password'],
        ]);
        $login = $this->response($I)['data'];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $login['accessToken']);
        $I->sendPOST('/api/auth/mfa/totp/setup', ['label' => 'API setup']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $setup = $this->response($I)['data'];
        $secret = Base32::decode((string) $setup['secret']);
        assertFalse($I->storedTotpEqualsPlaintext($fixture['accountId'], $secret));

        $I->sendPOST('/api/auth/mfa/totp/confirm', [
            'setupId' => $setup['setupId'],
            'code' => $this->totpCode($secret),
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $recoveryCodes = $this->response($I)['data']['recoveryCodes'];
        assertCount(10, $recoveryCodes);
        assertTrue($I->sessionHasMfa((string) $login['sessionId']));

        $I->sendGET('/api/auth/mfa');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => ['totpEnabled' => true, 'recoveryCodesRemaining' => 10]]);
    }

    public function emailRecoveryIsGenericSingleUseAndRevokesSecurityState(ApiTester $I): void
    {
        $fixture = $I->havePasswordAccount();
        $mfa = $I->enableTotp($fixture['accountId']);
        $I->haveHttpHeader('Content-Type', 'application/json');
        $challenge = $this->loginChallenge($I, $fixture);
        $I->sendPOST('/api/auth/mfa/verify', [
            'challengeToken' => $challenge,
            'recoveryCode' => $mfa['recoveryCode'],
        ]);
        $session = $this->response($I)['data'];

        $I->sendPOST('/api/auth/mfa/recovery/request', ['email' => 'missing-' . $fixture['email']]);
        $I->seeResponseCodeIs(HttpCode::ACCEPTED);
        $unknown = $this->response($I);
        $I->sendPOST('/api/auth/mfa/recovery/request', ['email' => $fixture['email']]);
        $I->seeResponseCodeIs(HttpCode::ACCEPTED);
        assertSame($unknown, $this->response($I));
        $token = $I->grabAuthActionToken($fixture['email']);

        $I->sendPOST('/api/auth/mfa/recovery/confirm', ['token' => $token, 'password' => 'wrong']);
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $I->sendPOST('/api/auth/mfa/recovery/confirm', ['token' => $token, 'password' => $fixture['password']]);
        $I->seeResponseCodeIs(HttpCode::OK);
        assertSame(
            ['activeFactors' => 0, 'availableCodes' => 0, 'activeSessions' => 0],
            $I->mfaStorageState($fixture['accountId']),
        );

        $I->haveHttpHeader('Authorization', 'Bearer ' . $session['accessToken']);
        $I->sendGET('/api/auth/me');
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $I->deleteHeader('Authorization');
        $I->sendPOST('/api/auth/refresh', ['refreshToken' => $session['refreshToken']]);
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $I->sendPOST('/api/auth/mfa/recovery/confirm', ['token' => $token, 'password' => $fixture['password']]);
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
    }

    /** @param array{accountId: string, email: string, password: string} $fixture */
    private function loginChallenge(ApiTester $I, array $fixture): string
    {
        $I->sendPOST('/api/auth/login', [
            'identifierType' => 'email',
            'identifier' => $fixture['email'],
            'password' => $fixture['password'],
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        return (string) $this->response($I)['data']['challengeToken'];
    }

    /** @return array<string, mixed> */
    private function response(ApiTester $I): array
    {
        /** @var array<string, mixed> */
        return json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function totpCode(string $secret): string
    {
        $counter = intdiv(time(), 30);
        $hash = hash_hmac('sha1', pack('N2', intdiv($counter, 0x1_0000_0000), $counter % 0x1_0000_0000), $secret, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);
    }
}
