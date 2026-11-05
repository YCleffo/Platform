<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use function PHPUnit\Framework\assertSame;

final class PrivacyWorkItemsCest
{
    public function _before(ApiTester $I): void
    {
        $I->cleanupCoreTestFixtures();
        $I->haveHttpHeader('Content-Type', 'application/json');
    }
    public function _after(ApiTester $I): void { $I->cleanupCoreTestFixtures(); }

    public function directoriesRequireTheirOwnPermissionAndReturnMinimalMetadata(ApiTester $I): void
    {
        foreach (['representation', 'erasure', 'incident', 'distribution'] as $kind) {
            $I->sendGET('/api/core/privacy/work-items/' . $kind);
            $I->seeResponseCodeIs(401);
        }
        $db = ApiTester::getDb();
        $person = (string) $db->createCommand("INSERT INTO core.persons (given_name,family_name) VALUES ('Directory','Subject') RETURNING id::text")->queryScalar();
        $account = (string) $db->createCommand("INSERT INTO core.accounts (person_id,account_type,status) VALUES (CAST(:id AS uuid),'human','active') RETURNING id::text", [':id' => $person])->queryScalar();
        $I->amAuthenticatedAccount($account);
        foreach (['representation', 'erasure', 'incident'] as $kind) {
            $I->sendGET('/api/core/privacy/work-items/' . $kind);
            $I->seeResponseCodeIs(403);
        }
        $I->sendGET('/api/core/privacy/work-items/distribution');
        $I->seeResponseCodeIs(200);
        assertSame([], json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['items']);
        $I->amAuthenticatedSuperadmin();
        foreach (['representation', 'erasure', 'incident', 'distribution'] as $kind) {
            $I->sendGET('/api/core/privacy/work-items/' . $kind . '?limit=10&offset=0');
            $I->seeResponseCodeIs(200);
            $data = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data'];
            foreach ($data['items'] as $row) assertSame(['id', 'name', 'status'], array_keys($row));
        }
    }
}
