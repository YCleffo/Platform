<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

use function PHPUnit\Framework\assertSame;

final readonly class CredentialLifecycleCest
{
    public function _after(ApiTester $I): void
    {
        $I->cleanupCoreTestFixtures();
    }

    public function invitationActivatesHumanAccount(ApiTester $I): void
    {
        $account = $I->haveInvitableHumanAccount();
        $I->amAuthenticatedSuperadmin();
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/core/accounts/' . $account['accountId'] . '/invite', [
            'email' => $account['email'],
        ]);
        $I->seeResponseCodeIs(HttpCode::ACCEPTED);
        $token = $I->grabAuthActionToken($account['email']);

        $I->deleteHeader('Authorization');
        $I->sendPOST('/api/auth/activate', [
            'token' => $token,
            'password' => 'Activated Password 123',
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendPOST('/api/auth/activate', [
            'token' => $token,
            'password' => 'Activated Password 123',
        ]);
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);

        $I->sendPOST('/api/auth/login', [
            'identifierType' => 'email',
            'identifier' => $account['email'],
            'password' => 'Activated Password 123',
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
    }

    public function administratorInvitationUsesInvitedAccountLocale(ApiTester $I): void
    {
        $account = $I->haveInvitableHumanAccount('en-US');
        $I->amAuthenticatedSuperadmin('ru-RU');
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->haveHttpHeader('X-Client-Locale', 'ru-RU');
        $I->sendPOST('/api/core/accounts/' . $account['accountId'] . '/invite', [
            'email' => $account['email'],
        ]);
        $I->seeResponseCodeIs(HttpCode::ACCEPTED);
        assertSame('en-US', $I->grabLatestAuthEmailLocale($account['email']));
    }

    public function passwordResetIsNonEnumeratingAndRevokesSessions(ApiTester $I): void
    {
        $account = $I->havePasswordAccount();
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/auth/login', [
            'identifierType' => 'email',
            'identifier' => $account['email'],
            'password' => $account['password'],
        ]);
        $access = (string) $this->response($I)['data']['accessToken'];

        $I->sendPOST('/api/auth/password-reset/request', ['email' => $account['email']]);
        $I->seeResponseCodeIs(HttpCode::ACCEPTED);
        $knownResponse = $I->grabResponse();
        $token = $I->grabAuthActionToken($account['email']);

        $I->sendPOST('/api/auth/password-reset/request', ['email' => 'unknown-' . $account['email']]);
        $I->seeResponseCodeIs(HttpCode::ACCEPTED);
        assertSame($knownResponse, $I->grabResponse());

        $I->sendPOST('/api/auth/password-reset/confirm', [
            'token' => $token,
            'password' => 'Replacement Password 123',
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendPOST('/api/auth/password-reset/confirm', [
            'token' => $token,
            'password' => 'Replacement Password 123',
        ]);
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);

        $I->haveHttpHeader('Authorization', 'Bearer ' . $access);
        $I->sendGET('/api/auth/me');
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $I->deleteHeader('Authorization');
        $I->sendPOST('/api/auth/login', [
            'identifierType' => 'email',
            'identifier' => $account['email'],
            'password' => 'Replacement Password 123',
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
    }

    public function passwordResetEmailUsesCurrentUiLocaleInsteadOfSavedPreference(ApiTester $I): void
    {
        $russianAccount = $I->havePasswordAccount(locale: 'ru-RU');
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->haveHttpHeader('X-Client-Locale', 'en-US');
        $I->sendPOST('/api/auth/password-reset/request', ['email' => $russianAccount['email']]);
        $I->seeResponseCodeIs(HttpCode::ACCEPTED);
        assertSame('en-US', $I->grabLatestAuthEmailLocale($russianAccount['email']));

        $englishAccount = $I->havePasswordAccount(locale: 'en-US');
        $I->haveHttpHeader('X-Client-Locale', 'ru-RU');
        $I->sendPOST('/api/auth/password-reset/request', ['email' => $englishAccount['email']]);
        $I->seeResponseCodeIs(HttpCode::ACCEPTED);
        assertSame('ru-RU', $I->grabLatestAuthEmailLocale($englishAccount['email']));
    }

    /** @return array<string, mixed> */
    private function response(ApiTester $I): array
    {
        /** @var array<string, mixed> */
        return json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
    }
}
