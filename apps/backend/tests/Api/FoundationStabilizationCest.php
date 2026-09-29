<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

final readonly class FoundationStabilizationCest
{
    public function _before(ApiTester $I): void { $I->cleanupCoreTestFixtures(); }
    public function _after(ApiTester $I): void { $I->cleanupCoreTestFixtures(); }

    public function legacyServiceAndSystemSessionsCannotAuthenticate(ApiTester $I): void
    {
        foreach (['service', 'system'] as $type) {
            $fixture = $I->havePasswordAccount();
            $I->haveHttpHeader('Content-Type', 'application/json');
            $I->sendPOST('/api/auth/login', [
                'identifierType' => 'email', 'identifier' => $fixture['email'], 'password' => $fixture['password'],
            ]);
            $I->seeResponseCodeIs(HttpCode::OK);
            $tokens = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data'];
            ApiTester::getDb()->createCommand(
                'UPDATE core.accounts SET account_type = CAST(:type AS core.account_type) WHERE id = CAST(:id AS uuid)',
                [':type' => $type, ':id' => $fixture['accountId']],
            )->execute();
            $I->haveHttpHeader('Authorization', 'Bearer ' . $tokens['accessToken']);
            $I->sendGET('/api/auth/me');
            $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
            $I->deleteHeader('Authorization');
            $I->sendPOST('/api/auth/refresh', ['refreshToken' => $tokens['refreshToken']]);
            $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
            $I->sendPOST('/api/auth/login', [
                'identifierType' => 'email', 'identifier' => $fixture['email'], 'password' => $fixture['password'],
            ]);
            $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        }
    }

    public function archivingPersonRevokesLinkedIdentity(ApiTester $I): void
    {
        $fixture = $I->havePasswordAccount();
        $db = ApiTester::getDb();
        $personId = (string) $db->createCommand('SELECT person_id::text FROM core.accounts WHERE id = CAST(:id AS uuid)',
            [':id' => $fixture['accountId']])->queryScalar();
        $oldStamp = (string) $db->createCommand('SELECT security_stamp::text FROM core.accounts WHERE id = CAST(:id AS uuid)',
            [':id' => $fixture['accountId']])->queryScalar();
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/api/auth/login', [
            'identifierType' => 'email', 'identifier' => $fixture['email'], 'password' => $fixture['password'],
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $tokens = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data'];
        $I->amAuthenticatedSuperadmin();
        $I->sendPOST('/api/core/persons/' . $personId . '/archive');
        $I->seeResponseCodeIs(HttpCode::OK);
        $state = $db->createCommand('SELECT status::text, security_stamp::text FROM core.accounts WHERE id = CAST(:id AS uuid)',
            [':id' => $fixture['accountId']])->queryOne();
        assertSame('archived', $state['status']);
        assertNotSame($oldStamp, $state['security_stamp']);
        assertSame(0, (int) $db->createCommand('SELECT count(*) FROM core.auth_sessions WHERE account_id = CAST(:id AS uuid) AND revoked_at IS NULL',
            [':id' => $fixture['accountId']])->queryScalar());
        $I->haveHttpHeader('Authorization', 'Bearer ' . $tokens['accessToken']);
        $I->sendGET('/api/auth/me');
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $I->deleteHeader('Authorization');
        $I->sendPOST('/api/auth/refresh', ['refreshToken' => $tokens['refreshToken']]);
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
    }

    public function lastUsableSuperadminPersonCannotBeArchived(ApiTester $I): void
    {
        $auth = $I->amAuthenticatedSuperadmin();
        $personId = (string) ApiTester::getDb()->createCommand(
            'SELECT person_id::text FROM core.accounts WHERE id = CAST(:id AS uuid)',
            [':id' => $auth['accountId']],
        )->queryScalar();
        $I->sendPOST('/api/core/persons/' . $personId . '/archive');
        $I->seeResponseCodeIs(HttpCode::CONFLICT);
        $I->sendGET('/api/auth/me');
        $I->seeResponseCodeIs(HttpCode::OK);
    }
}
