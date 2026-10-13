<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

use function PHPUnit\Framework\assertSame;

final readonly class DataSubjectRequestsCest
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

    public function selfAndManagementBoundaries(ApiTester $I): void
    {
        $path = '/api/auth/me/privacy/requests';
        $I->sendGET($path);
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $db = ApiTester::getDb();
        $serviceAccount = (string) $db->createCommand("INSERT INTO core.accounts (account_type, status) VALUES ('service', 'active') RETURNING id::text")->queryScalar();
        $I->amAuthenticatedAccount($serviceAccount);
        $I->sendPOST($path, ['requestType' => 'access']);
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $person = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Request', 'Owner') RETURNING id::text")->queryScalar();
        $other = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Request', 'Other') RETURNING id::text")->queryScalar();
        $account = (string) $db->createCommand(
            "INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:person AS uuid), 'human', 'active') RETURNING id::text",
            [':person' => $person],
        )->queryScalar();
        $otherAccount = (string) $db->createCommand(
            "INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:person AS uuid), 'human', 'active') RETURNING id::text",
            [':person' => $other],
        )->queryScalar();
        $I->amAuthenticatedAccount($account);
        $I->sendPOST($path, ['requestType' => 'access', 'subjectPersonId' => $other]);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->sendPOST($path, ['requestType' => 'access']);
        $I->seeResponseCodeIs(HttpCode::CREATED);
        $id = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['id'];
        $I->sendGET($path . '/' . $id);
        $I->seeResponseCodeIs(HttpCode::OK);
        assertSame($person, json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['subjectPersonId']);
        $I->sendGET('/api/core/privacy/requests/' . $id);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->amAuthenticatedAccount($otherAccount);
        $I->sendGET($path . '/' . $id);
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->amAuthenticatedSuperadmin();
        $I->sendGET('/api/core/privacy/requests/' . $id);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendPOST('/api/core/privacy/requests/' . $id . '/transition', ['toStatus' => 'in_review']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendPOST('/api/core/privacy/requests/' . $id . '/deadlines/access_response/extend', ['reasonCode' => 'complex_review']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendPOST('/api/core/privacy/requests/' . $id . '/transition', ['toStatus' => 'in_progress']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendPOST('/api/core/privacy/requests/' . $id . '/transition', ['toStatus' => 'completed']);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
    }

    public function representativeScopeAndBlockEndpoints(ApiTester $I): void
    {
        $db = ApiTester::getDb();
        $subject = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Subject', 'Rep') RETURNING id::text")->queryScalar();
        $representative = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Representative', 'Rep') RETURNING id::text")->queryScalar();
        $repAccount = (string) $db->createCommand(
            "INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:person AS uuid), 'human', 'active') RETURNING id::text",
            [':person' => $representative],
        )->queryScalar();
        $I->amAuthenticatedSuperadmin();
        $I->sendPOST('/api/core/privacy/representations', [
            'subjectPersonId' => $subject, 'representativePersonId' => $representative,
            'authorityType' => 'legal_guardian', 'validFrom' => '2020-01-01T00:00:00Z',
            'evidenceType' => 'test_proof', 'evidenceReference' => 'fixture:dsar',
            'scopes' => ['privacy.request.submit', 'privacy.request.view'],
        ]);
        $I->seeResponseCodeIs(HttpCode::CREATED);
        $authority = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['id'];
        $I->sendPOST('/api/core/privacy/representations/' . $authority . '/verify', []);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->amAuthenticatedAccount($repAccount);
        $I->sendPOST('/api/auth/me/privacy/representations/' . $authority . '/requests',
            ['subjectPersonId' => $subject, 'requestType' => 'erasure']);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->sendPOST('/api/auth/me/privacy/representations/' . $authority . '/requests',
            ['subjectPersonId' => $subject, 'requestType' => 'access']);
        $I->seeResponseCodeIs(HttpCode::CREATED);
        $requestId = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['id'];
        $I->sendGET('/api/auth/me/privacy/requests/' . $requestId);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->amAuthenticatedSuperadmin();
        $I->sendPOST('/api/core/privacy/requests/' . $requestId . '/blocks',
            ['scopeType' => 'subject', 'reasonCode' => 'accuracy_review']);
        $I->seeResponseCodeIs(HttpCode::CREATED);
        $blockId = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['id'];
        $I->sendGET('/api/core/privacy/requests/' . $requestId . '/blocks');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendPOST('/api/core/privacy/blocks/' . $blockId . '/release', ['reasonCode' => 'review_complete']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendPOST('/api/core/privacy/representations/' . $authority . '/revoke', ['reasonCode' => 'test_revocation']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->amAuthenticatedAccount($repAccount);
        $I->sendGET('/api/auth/me/privacy/requests/' . $requestId);
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
    }

    public function exportAndCorrectionEndpoints(ApiTester $I): void
    {
        $db = ApiTester::getDb();
        $subject = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('ApiExport', 'Subject') RETURNING id::text")->queryScalar();
        $account = (string) $db->createCommand("INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:person AS uuid), 'human', 'active') RETURNING id::text", [':person' => $subject])->queryScalar();
        $I->amAuthenticatedAccount($account);
        $I->sendPOST('/api/auth/me/privacy/requests', ['requestType' => 'access']);
        $I->seeResponseCodeIs(HttpCode::CREATED);
        $access = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['id'];
        $I->sendGET('/api/auth/me/privacy/requests/' . $access . '/data');
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->amAuthenticatedSuperadmin();
        foreach (['in_review', 'in_progress'] as $status) {
            $I->sendPOST('/api/core/privacy/requests/' . $access . '/transition', ['toStatus' => $status]);
            $I->seeResponseCodeIs(HttpCode::OK);
        }
        $I->amAuthenticatedAccount($account);
        $I->sendGET('/api/auth/me/privacy/requests/' . $access . '/data');
        $I->seeResponseCodeIs(HttpCode::OK);
        $body = $I->grabResponse();
        assertSame('1', json_decode($body, true, flags: JSON_THROW_ON_ERROR)['schemaVersion']);
        assertSame($subject, json_decode($body, true, flags: JSON_THROW_ON_ERROR)['subjectPersonId']);
        $I->sendGET('/api/auth/me/privacy/requests/' . $access . '/data');
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);

        $I->sendPOST('/api/auth/me/privacy/requests', ['requestType' => 'correction']);
        $I->seeResponseCodeIs(HttpCode::CREATED);
        $correction = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['id'];
        $I->amAuthenticatedSuperadmin();
        foreach (['in_review', 'in_progress'] as $status) {
            $I->sendPOST('/api/core/privacy/requests/' . $correction . '/transition', ['toStatus' => $status]);
            $I->seeResponseCodeIs(HttpCode::OK);
        }
        $I->sendPOST('/api/core/privacy/requests/' . $correction . '/correction-evidence', []);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendPOST('/api/core/privacy/requests/' . $correction . '/corrections', [
            'contributorCode' => 'core.person', 'resourceId' => $subject,
            'changes' => ['givenName' => 'ApiCorrected'],
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        assertSame('ApiCorrected', $db->createCommand('SELECT given_name FROM core.persons WHERE id = CAST(:id AS uuid)', [':id' => $subject])->queryScalar());
    }
}
