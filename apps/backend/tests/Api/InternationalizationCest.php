<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

final readonly class InternationalizationCest
{
    public function _before(ApiTester $I): void
    {
        $I->cleanupCoreTestFixtures();
    }

    public function _after(ApiTester $I): void
    {
        $I->cleanupCoreTestFixtures();
    }

    public function sameErrorCodeHasDifferentRuAndEnMessages(ApiTester $I): void
    {
        $I->haveHttpHeader('Accept-Language', 'ru-RU');
        $I->sendGET('/not-found-i18n');
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $russian = $this->response($I);
        $I->seeHttpHeader('Content-Language', 'ru-RU');
        $I->seeHttpHeader('Vary', 'Accept-Language, X-Client-Locale');

        $I->haveHttpHeader('Accept-Language', 'en-US');
        $I->sendGET('/not-found-i18n');
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $english = $this->response($I);
        $I->seeHttpHeader('Content-Language', 'en-US');

        assertSame('http.not_found', $russian['error']['code']);
        assertSame($russian['error']['code'], $english['error']['code']);
        assertNotSame($russian['error']['message'], $english['error']['message']);
        assertSame(array_keys($russian['error']), array_keys($english['error']));
    }

    public function localizesFrameworkAndValidationErrors(ApiTester $I): void
    {
        $I->haveHttpHeader('Accept-Language', 'ru-RU');
        $I->sendGET('/api/auth/me');
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $I->seeResponseContainsJson(['error' => ['code' => 'auth.required']]);

        $I->sendPOST('/health');
        $I->seeResponseCodeIs(HttpCode::METHOD_NOT_ALLOWED);
        $I->seeResponseContainsJson(['error' => ['code' => 'http.method_not_allowed']]);
        $I->seeHttpHeader('Content-Language', 'ru-RU');

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/auth/login', '{"broken":');
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContainsJson(['error' => ['code' => 'http.bad_request']]);

        $I->sendPOST('/api/auth/login', []);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->seeResponseContainsJson([
            'error' => [
                'code' => 'validation.failed',
                'fields' => [
                    'identifier' => [['code' => 'validation.required']],
                    'password' => [['code' => 'validation.required']],
                ],
            ],
        ]);
    }

    public function defaultsAndUnsupportedLanguagesFallBackSafely(ApiTester $I): void
    {
        $I->sendGET('/not-found-default-locale');
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->seeHttpHeader('Content-Language', 'ru-RU');

        $I->haveHttpHeader('Accept-Language', 'de-DE, fr;q=0.8');
        $I->sendGET('/not-found-unsupported-locale');
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->seeHttpHeader('Content-Language', 'en-US');
    }

    public function accountLocaleIsUsedAndExplicitHeaderOverridesIt(ApiTester $I): void
    {
        $I->amAuthenticatedSuperadmin('en-US');
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendGET('/api/auth/me');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeHttpHeader('Content-Language', 'en-US');

        $I->haveHttpHeader('Accept-Language', 'ru-RU');
        $I->sendGET('/api/auth/me');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeHttpHeader('Content-Language', 'en-US');

        $I->haveHttpHeader('X-Client-Locale', 'ru-RU');
        $I->sendGET('/api/auth/me');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeHttpHeader('Content-Language', 'ru-RU');

        $I->sendPATCH('/api/auth/me/preferences', ['locale' => 'ru-RU']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->deleteHeader('X-Client-Locale');
        $I->haveHttpHeader('Accept-Language', 'en-US');
        $I->sendGET('/api/auth/me');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeHttpHeader('Content-Language', 'ru-RU');
    }

    /** @return array<string, mixed> */
    private function response(ApiTester $I): array
    {
        /** @var array<string, mixed> */
        return json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
    }
}
