<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

final readonly class NotFoundCest
{
    public function _before(ApiTester $I): void
    {
        $I->cleanupCoreTestFixtures();
    }

    public function _after(ApiTester $I): void
    {
        $I->cleanupCoreTestFixtures();
    }

    public function testNotFoundPage(ApiTester $I): void
    {
        $I->haveHttpHeader('Accept-Language', 'en-US');
        $I->sendGET('/not_found_page');
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->seeResponseIsJson();
        $I->seeResponseContainsJson(
            [
                'error' => [
                    'code' => 'http.not_found',
                    'message' => 'The requested resource was not found.',
                ],
            ],
        );
        $I->seeHttpHeader('Content-Language', 'en-US');
        $I->seeHttpHeader('Vary', 'Accept-Language, X-Client-Locale');
    }
}
