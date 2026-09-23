<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

final readonly class HealthCest
{
    public function _before(ApiTester $I): void
    {
        $I->cleanupCoreTestFixtures();
    }

    public function _after(ApiTester $I): void
    {
        $I->cleanupCoreTestFixtures();
    }

    public function health(ApiTester $I): void
    {
        $I->sendGET('/health');

        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseIsJson();
        $I->seeHttpHeader('X-Content-Type-Options', 'nosniff');
        $I->seeHttpHeader('Referrer-Policy', 'no-referrer');
        $I->seeHttpHeader('X-Frame-Options', 'DENY');
        $I->dontSeeHttpHeader('Access-Control-Allow-Origin');

        $I->seeResponseContainsJson([
            'status' => 'success',
            'data' => [
                'status' => 'ok',
                'coreTables' => 23,
            ],
        ]);
    }
}
