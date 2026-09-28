<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

use function PHPUnit\Framework\assertSame;

final readonly class PrivacyRepresentationCest
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

    public function managementRequiresSpecificPermissionAndVerifiedEvidence(ApiTester $I): void
    {
        $path = '/api/core/privacy/representations';
        $I->sendPOST($path, []);
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);

        $db = ApiTester::getDb();
        $subject = $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Subject', 'Fixture') RETURNING id::text")->queryScalar();
        $representative = $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Representative', 'Fixture') RETURNING id::text")->queryScalar();
        $account = $db->createCommand("INSERT INTO core.accounts (account_type, status) VALUES ('service', 'active') RETURNING id::text")->queryScalar();
        $I->amAuthenticatedAccount((string) $account);
        $body = [
            'subjectPersonId' => $subject,
            'representativePersonId' => $representative,
            'authorityType' => 'legal_guardian',
            'validFrom' => '2020-01-01T00:00:00Z',
            'evidenceType' => 'test_proof',
            'evidenceReference' => 'fixture:protected',
        ];
        $I->sendPOST($path, $body);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);

        $I->amAuthenticatedSuperadmin();
        $I->sendPOST($path, $body + ['unexpected' => 'value']);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->sendPOST($path, $body);
        $I->seeResponseCodeIs(HttpCode::CREATED);
        $id = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['id'];
        $I->sendPOST($path . '/' . $id . '/verify', []);
        $I->seeResponseCodeIs(HttpCode::OK);
        assertSame(true, json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['changed']);
        $I->sendPOST($path . '/' . $id . '/verify', []);
        $I->seeResponseCodeIs(HttpCode::OK);
        assertSame(false, json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['changed']);
        $I->sendPOST($path . '/' . $id . '/revoke', ['reasonCode' => 'test_revocation']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendPOST($path . '/' . $id . '/revoke', ['reasonCode' => 'test_revocation']);
        $I->seeResponseCodeIs(HttpCode::OK);
        assertSame(false, json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['changed']);
    }
}
