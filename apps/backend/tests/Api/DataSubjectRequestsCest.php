<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

use function PHPUnit\Framework\assertSame;

final readonly class DataSubjectRequestsCest
{
    public function operatorDeliveryIsLimitedToVerifiedOfflineIntake(ApiTester $I): void
    {
        $owner = $I->havePasswordAccount();
        $person = (string) ApiTester::getDb()->createCommand('SELECT person_id::text FROM core.accounts WHERE id=CAST(:id AS uuid)', [':id' => $owner['accountId']])->queryScalar();
        $I->amAuthenticatedSuperadmin();
        $I->sendPOST('/api/core/privacy/requests/intake', [
            'subjectPersonId' => $person, 'requestType' => 'access',
            'receivedAt' => gmdate('Y-m-d\TH:i:s\Z', time() - 60),
            'identityVerificationCode' => 'document_checked',
            'identityEvidenceReference' => 'document://verified-intake', 'sourceChannel' => 'paper',
        ]);
        $I->seeResponseCodeIs(HttpCode::CREATED);
        $offline = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['id'];
        $I->sendGET('/api/core/privacy/requests/' . $offline);
        $I->seeResponseCodeIs(HttpCode::OK);
        $view = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data'];
        assertSame('paper', $view['intakeChannel']);
        assertSame(false, array_key_exists('identityEvidenceReference', $view));
        foreach (['in_review', 'in_progress'] as $status) {
            $I->sendPOST('/api/core/privacy/requests/' . $offline . '/transition', ['toStatus' => $status]);
            $I->seeResponseCodeIs(HttpCode::OK);
        }
        $I->sendGET('/api/core/privacy/requests/' . $offline . '/data');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->amAuthenticatedAccount($owner['accountId']);
        $I->sendPOST('/api/auth/me/privacy/requests', ['requestType' => 'access']);
        $I->seeResponseCodeIs(HttpCode::CREATED);
        $online = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['id'];
        $I->amAuthenticatedSuperadmin();
        foreach (['in_review', 'in_progress'] as $status) {
            $I->sendPOST('/api/core/privacy/requests/' . $online . '/transition', ['toStatus' => $status]);
            $I->seeResponseCodeIs(HttpCode::OK);
        }
        $I->sendGET('/api/core/privacy/requests/' . $online);
        $I->seeResponseCodeIs(HttpCode::OK);
        assertSame(null, json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['intakeChannel']);
        $I->sendGET('/api/core/privacy/requests/' . $online . '/data');
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
    }

    public function operatorOptionsRequireReadPermissionAndExposeOnlyCodes(ApiTester $I): void
    {
        $account = $I->havePasswordAccount();
        $I->amAuthenticatedAccount($account['accountId']);
        $I->sendGET('/api/core/privacy/requests/options');
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->amAuthenticatedSuperadmin();
        $I->sendGET('/api/core/privacy/requests/options');
        $I->seeResponseCodeIs(HttpCode::OK);
        $data = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data'];
        assertSame(['purposeCodes' => ['test.processing', 'test.required']], $data);
    }

    public function _before(ApiTester $I): void
    {
        $I->cleanupCoreTestFixtures();
        $I->haveHttpHeader('Content-Type', 'application/json');
    }

    public function _after(ApiTester $I): void
    {
        $I->cleanupCoreTestFixtures();
    }

    public function ownPaginationPreservesLegacyShapeAndIsolation(ApiTester $I): void
    {
        $owner = $I->havePasswordAccount();
        $other = $I->havePasswordAccount();
        $I->amAuthenticatedAccount($owner['accountId']);
        $ids = [];
        foreach (['access', 'export', 'processing_information'] as $type) {
            $I->sendPOST('/api/auth/me/privacy/requests', ['requestType' => $type]);
            $I->seeResponseCodeIs(HttpCode::CREATED);
            $ids[] = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['id'];
        }
        $I->sendGET('/api/auth/me/privacy/requests', ['limit' => 2, 'offset' => 0]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $first = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data'];
        assertSame(2, count($first));
        assertSame(null, $first[0]['representationId']);
        $I->sendGET('/api/auth/me/privacy/requests', ['limit' => 2, 'offset' => 2]);
        $second = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data'];
        assertSame(1, count($second));
        assertSame(3, count(array_unique([...array_column($first, 'id'), ...array_column($second, 'id')])));
        $I->sendGET('/api/auth/me/privacy/requests');
        assertSame(3, count(json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']));
        foreach ([['limit' => 0], ['limit' => 101], ['offset' => -1], ['limit' => 'invalid']] as $query) {
            $I->sendGET('/api/auth/me/privacy/requests', $query);
            $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        }
        $I->amAuthenticatedAccount($other['accountId']);
        $I->sendGET('/api/auth/me/privacy/requests');
        assertSame([], json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']);
        $I->sendGET('/api/auth/me/privacy/requests/' . $ids[0]);
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
    }

    public function ownRepresentationDiscoveryIsActiveScopedAndDoesNotExposeEvidence(ApiTester $I): void
    {
        $I->sendGET('/api/auth/me/privacy/representations');
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $owner = $I->havePasswordAccount();
        $subject = $I->havePasswordAccount();
        $other = $I->havePasswordAccount();
        $db = ApiTester::getDb();
        $person = static fn(string $id): string => (string) $db->createCommand('SELECT person_id::text FROM core.accounts WHERE id=CAST(:id AS uuid)', [':id' => $id])->queryScalar();
        $validId = '';
        foreach (['valid', 'expired', 'unverified', 'other', 'future', 'revoked', 'module'] as $mode) {
            $status = $mode === 'unverified' ? 'declared' : ($mode === 'revoked' ? 'revoked' : 'verified');
            $id = (string) $db->createCommand(<<<'SQL'
                INSERT INTO core.legal_representations
                    (subject_person_id, representative_person_id, authority_type, status, valid_from, valid_until,
                     verified_at, verified_by_account_id, evidence_type, evidence_reference,
                     revoked_at, revoked_by_account_id, revocation_reason_code)
                VALUES (CAST(:subject AS uuid),CAST(:representative AS uuid),'legal_guardian','declared',
                    CASE WHEN :mode='future' THEN now()+interval '1 day' ELSE now()-interval '2 days' END,
                    CASE WHEN :mode='expired' THEN now()-interval '1 day' ELSE now()+interval '10 days' END,
                    NULL, NULL,
                    'fixture','vault://private-proof-not-for-self-service',
                    NULL, NULL, NULL)
                RETURNING id::text
                SQL, [':subject' => $person($subject['accountId']), ':representative' => $person(($mode === 'other' ? $other : $owner)['accountId']),
                ':mode' => $mode])->queryScalar();
            foreach ($mode === 'module' ? ['module.unrelated'] : ['privacy.request.submit', 'privacy.request.view', 'privacy.data.access'] as $scope) {
                $db->createCommand('INSERT INTO core.legal_representation_scopes(representation_id,scope_code) VALUES(CAST(:id AS uuid),:scope)', [':id' => $id, ':scope' => $scope])->execute();
            }
            if ($status !== 'declared') {
                $db->createCommand(<<<'SQL'
                    UPDATE core.legal_representations SET status=CAST(:status AS varchar(32)), verified_at=valid_from, verified_by_account_id=CAST(:actor AS uuid),
                        revoked_at=CASE WHEN :status='revoked' THEN now() ELSE NULL END,
                        revoked_by_account_id=CASE WHEN :status='revoked' THEN CAST(:actor AS uuid) ELSE NULL END,
                        revocation_reason_code=CASE WHEN :status='revoked' THEN 'fixture_revocation' ELSE NULL END
                    WHERE id=CAST(:id AS uuid)
                    SQL, [':status' => $status, ':actor' => $owner['accountId'], ':id' => $id])->execute();
            }
            if ($mode === 'valid') {
                $validId = $id;
            }
        }
        $I->amAuthenticatedAccount($owner['accountId']);
        $I->sendGET('/api/auth/me/privacy/representations');
        $I->seeResponseCodeIs(HttpCode::OK);
        $items = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['items'];
        assertSame(1, count($items));
        assertSame($validId, $items[0]['id']);
        assertSame(['id', 'subjectPersonId', 'subjectName', 'validUntil', 'scopes'], array_keys($items[0]));
        $I->sendPOST('/api/auth/me/privacy/representations/' . $validId . '/requests', ['subjectPersonId' => $person($subject['accountId']), 'requestType' => 'access']);
        $I->seeResponseCodeIs(HttpCode::CREATED);
        $requestId = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['id'];
        $I->sendGET('/api/auth/me/privacy/requests/' . $requestId);
        assertSame($validId, json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['representationId']);
        $I->amAuthenticatedSuperadmin();
        $I->sendPOST('/api/core/privacy/representations/' . $validId . '/revoke', ['reasonCode' => 'fixture_revocation']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->amAuthenticatedAccount($owner['accountId']);
        $I->sendGET('/api/auth/me/privacy/representations');
        assertSame([], json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['items']);
        $I->sendGET('/api/auth/me/privacy/requests/' . $requestId);
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
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
        $I->sendPOST(
            '/api/auth/me/privacy/representations/' . $authority . '/requests',
            ['subjectPersonId' => $subject, 'requestType' => 'erasure'],
        );
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->sendPOST(
            '/api/auth/me/privacy/representations/' . $authority . '/requests',
            ['subjectPersonId' => $subject, 'requestType' => 'access'],
        );
        $I->seeResponseCodeIs(HttpCode::CREATED);
        $requestId = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['id'];
        $I->sendGET('/api/auth/me/privacy/requests/' . $requestId);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->amAuthenticatedSuperadmin();
        $I->sendPOST(
            '/api/core/privacy/requests/' . $requestId . '/blocks',
            ['scopeType' => 'subject', 'reasonCode' => 'accuracy_review'],
        );
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
