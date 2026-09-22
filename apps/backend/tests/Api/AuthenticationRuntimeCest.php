<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

final readonly class AuthenticationRuntimeCest
{
    public function _after(ApiTester $I): void
    {
        $I->cleanupCoreTestFixtures();
    }

    public function loginRefreshMeAndLogout(ApiTester $I): void
    {
        $fixture = $I->havePasswordAccount();
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->haveHttpHeader('X-Client-Locale', 'en-US');
        $I->sendPOST('/api/auth/login', [
            'identifierType' => 'email',
            'identifier' => strtoupper($fixture['email']),
            'password' => $fixture['password'],
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $login = $this->response($I)['data'];
        $access = (string) $login['accessToken'];
        $refresh = (string) $login['refreshToken'];
        assertNotSame($access, $refresh);
        assertSame(43, strlen($access));

        $I->haveHttpHeader('Authorization', 'Bearer ' . $access);
        $I->sendGET('/api/auth/me');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => ['account' => ['id' => $fixture['accountId']]]]);
        $I->dontSeeResponseContains('securityStamp');
        $I->dontSeeResponseContains('TokenHash');

        $I->sendPATCH('/api/auth/me/preferences', [
            'locale' => 'en-US',
            'timezone' => 'Europe/Berlin',
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => ['locale' => 'en-US', 'timezone' => 'Europe/Berlin']]);
        $I->sendGET('/api/auth/sessions');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => [['current' => true, 'locale' => 'en-US']]]);
        $I->dontSeeResponseContains('tokenHash');
        $I->dontSeeResponseContains('fingerprintHash');

        $I->deleteHeader('Authorization');
        $I->sendPOST('/api/auth/refresh', ['refreshToken' => $refresh]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $rotated = $this->response($I)['data'];
        assertNotSame($access, $rotated['accessToken']);
        assertNotSame($refresh, $rotated['refreshToken']);

        $I->sendPOST('/api/auth/refresh', ['refreshToken' => $refresh]);
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);

        $I->haveHttpHeader('Authorization', 'Bearer ' . $rotated['accessToken']);
        $I->sendPOST('/api/auth/logout');
        $I->seeResponseCodeIs(HttpCode::NO_CONTENT);
        $I->sendGET('/api/auth/me');
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $I->deleteHeader('Authorization');
        $I->sendPOST('/api/auth/refresh', ['refreshToken' => $rotated['refreshToken']]);
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
    }

    public function invalidCredentialsAreGenericAndStateIsEnforced(ApiTester $I): void
    {
        $fixture = $I->havePasswordAccount();
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/auth/login', [
            'identifierType' => 'email',
            'identifier' => 'missing-' . $fixture['email'],
            'password' => 'Wrong Password 123',
        ]);
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $unknown = $this->response($I)['error'];
        unset($unknown['requestId']);

        $I->sendPOST('/api/auth/login', [
            'identifierType' => 'email',
            'identifier' => $fixture['email'],
            'password' => 'Wrong Password 123',
        ]);
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $known = $this->response($I)['error'];
        unset($known['requestId']);
        assertSame($unknown, $known);
        assertSame('auth.invalid_credentials', $known['code']);

        foreach ([
            ['disabled', false],
            ['archived', false],
            ['pending_verification', false],
            ['active', true],
        ] as [$status, $locked]) {
            $blocked = $I->havePasswordAccount($status, $locked);
            $I->sendPOST('/api/auth/login', [
                'identifierType' => 'email',
                'identifier' => $blocked['email'],
                'password' => $blocked['password'],
            ]);
            $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
            $I->seeResponseContainsJson(['error' => ['code' => 'auth.invalid_credentials']]);
        }
    }

    public function authenticationAndAuthorizationBoundaries(ApiTester $I): void
    {
        $I->sendGET('/api/core/persons');
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $I->seeHttpHeader('WWW-Authenticate', 'Bearer');

        $I->haveHttpHeader('Authorization', 'Bearer malformed');
        $I->sendGET('/api/core/persons');
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);

        $fixture = $I->havePasswordAccount();
        $I->sendPOST('/api/auth/login', [
            'identifierType' => 'email',
            'identifier' => $fixture['email'],
            'password' => $fixture['password'],
        ]);
        $access = (string) $this->response($I)['data']['accessToken'];
        $I->haveHttpHeader('Authorization', 'Bearer ' . $access);
        $I->sendGET('/api/core/persons');
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
    }

    public function expiryRevocationAndSecurityStampAreKillSwitches(ApiTester $I): void
    {
        foreach (['access_expired', 'absolute_expired', 'revoked', 'stamp'] as $mode) {
            $fixture = $I->havePasswordAccount();
            $I->haveHttpHeader('Content-Type', 'application/json');
            $I->deleteHeader('Authorization');
            $I->sendPOST('/api/auth/login', [
                'identifierType' => 'email',
                'identifier' => $fixture['email'],
                'password' => $fixture['password'],
            ]);
            $tokens = $this->response($I)['data'];
            $I->invalidateAuthentication($fixture['accountId'], $mode);

            $I->haveHttpHeader('Authorization', 'Bearer ' . $tokens['accessToken']);
            $I->sendGET('/api/auth/me');
            $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
            $I->deleteHeader('Authorization');
            $I->sendPOST('/api/auth/refresh', ['refreshToken' => $tokens['refreshToken']]);
            $I->seeResponseCodeIs(
                $mode === 'access_expired' ? HttpCode::OK : HttpCode::UNAUTHORIZED,
            );
        }
    }

    /** @return array<string, mixed> */
    private function response(ApiTester $I): array
    {
        /** @var array<string, mixed> */
        return json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
    }
}
