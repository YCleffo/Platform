<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

final readonly class AccountsCest
{
    public function _before(ApiTester $I): void
    {
        $I->cleanupCoreTestFixtures();
        $I->amAuthenticatedSuperadmin();
    }

    public function _after(ApiTester $I): void
    {
        $I->cleanupCoreTestFixtures();
    }

    public function createReadAndListSafeAccount(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/core/accounts', [
            'accountType' => 'service',
            'label' => 'API integration service',
        ]);

        $I->seeResponseCodeIs(HttpCode::CREATED);
        $created = $this->response($I);
        $id = (string) $created['data']['id'];
        $I->dontSeeResponseContains('securityStamp');

        $I->sendGET('/api/core/accounts/' . $id);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson([
            'data' => [
                'id' => $id,
                'accountType' => 'service',
                'label' => 'API integration service',
            ],
        ]);
        $I->dontSeeResponseContains('securityStamp');

        $I->sendGET('/api/core/accounts?limit=10&offset=0');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson([
            'data' => [
                'pagination' => [
                    'limit' => 10,
                    'offset' => 0,
                ],
            ],
        ]);
    }

    public function rejectsHumanAccountWithoutPerson(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/core/accounts', ['accountType' => 'human']);

        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->seeResponseContainsJson(['error' => ['code' => 'validation.failed']]);
    }

    public function rejectsUnsafeAccountLabelsAtValidationBoundary(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        foreach (['   ', "bad\0label", "bad\nlabel", str_repeat('a', 161)] as $label) {
            $I->sendPOST('/api/core/accounts', ['accountType' => 'service', 'label' => $label]);
            $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
            $I->seeResponseContainsJson(['error' => ['code' => 'validation.failed']]);
        }
    }

    public function securityLifecycleUsesNamedOperations(ApiTester $I): void
    {
        $target = $I->havePasswordAccount();

        $I->sendPOST('/api/core/accounts/' . $target['accountId'] . '/disable');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => ['status' => 'disabled']]);

        $I->sendPOST('/api/core/accounts/' . $target['accountId'] . '/enable');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => ['status' => 'active']]);

        $I->sendPOST('/api/core/accounts/' . $target['accountId'] . '/archive');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => ['status' => 'archived']]);
    }

    /** @return array<string, mixed> */
    private function response(ApiTester $I): array
    {
        /** @var array<string, mixed> */
        return json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
    }
}
