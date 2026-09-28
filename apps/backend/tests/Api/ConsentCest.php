<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class ConsentCest
{
    public function _before(ApiTester $I): void
    {
        $I->cleanupCoreTestFixtures();
        $I->haveHttpHeader('Content-Type', 'application/json');
    }

    public function _after(ApiTester $I): void
    {
        $I->cleanupCoreTestFixtures();
    }

    public function selfConsentLifecycleAndAuthorization(ApiTester $I): void
    {
        $I->sendGET('/api/auth/me/consents');
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $fixture = $I->amAuthenticatedSuperadmin();
        $I->sendGET('/api/auth/me/consents');
        $I->seeResponseCodeIs(HttpCode::OK);
        $data = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data'];
        assertSame('test.processing', $data['definitions'][0]['code']);

        $I->sendPOST('/api/auth/me/consents/unknown.type', []);
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->sendPOST('/api/auth/me/consents/test.processing', ['account_id' => '00000000-0000-4000-8000-000000000000']);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->sendPOST('/api/auth/me/consents/test.processing', []);
        $I->seeResponseCodeIs(HttpCode::OK);
        $first = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data'];
        assertTrue($first['current']);
        $I->sendPOST('/api/auth/me/consents/test.processing', []);
        $I->seeResponseCodeIs(HttpCode::OK);
        assertSame($first['id'], json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['id']);
        $count = ApiTester::getDb()->createCommand('SELECT count(*) FROM core.account_consents WHERE account_id = CAST(:id AS uuid)', [':id' => $fixture['accountId']])->queryScalar();
        assertSame(1, (int) $count);

        $I->sendDELETE('/api/auth/me/consents/test.required');
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->sendDELETE('/api/auth/me/consents/test.processing');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendGET('/api/auth/me/consents');
        $I->seeResponseCodeIs(HttpCode::OK);
        $state = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['consents'][0];
        assertFalse($state['current']);
    }
}
