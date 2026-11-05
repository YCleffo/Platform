<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Application\DataSubjectRequestService;
use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

use function PHPUnit\Framework\assertSame;

final readonly class PrivacyLegalHoldB3Cest
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

    public function holdManagementRequiresPermissionAndStrictCodes(ApiTester $I): void
    {
        $path = '/api/core/privacy/legal-holds';
        $I->sendPOST($path, []);
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $db = ApiTester::getDb();
        $subject = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('B3', 'Hold') RETURNING id::text")->queryScalar();
        $unprivilegedPerson = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('Unprivileged', 'Hold') RETURNING id::text")->queryScalar();
        $account = (string) $db->createCommand("INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:person AS uuid), 'human', 'active') RETURNING id::text", [':person' => $unprivilegedPerson])->queryScalar();
        $I->amAuthenticatedAccount($account);
        $I->sendGET($path);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->sendPOST($path, ['subjectPersonId' => $subject, 'scopeType' => 'subject', 'reasonCode' => 'legal_review']);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->amAuthenticatedSuperadmin();
        $body = ['subjectPersonId' => $subject, 'scopeType' => 'subject', 'reasonCode' => 'legal_review'];
        $I->sendPOST($path, $body + ['name' => 'forbidden']);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->sendPOST($path, $body + ['sourceReference' => 'person@example.com']);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->sendPOST($path, $body);
        $I->seeResponseCodeIs(HttpCode::CREATED);
        $id = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['id'];
        $I->sendPOST($path, $body);
        $I->seeResponseCodeIs(HttpCode::CREATED);
        assertSame($id, json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['id']);
        $I->sendGET($path . '/' . $id);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendGET($path . '?subjectPersonId=' . $subject);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendGET($path);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendPOST($path . '/' . $id . '/release', ['reasonCode' => 'review_complete']);
        $I->seeResponseCodeIs(HttpCode::OK);
        assertSame(true, json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['changed']);
        $I->sendPOST($path . '/' . $id . '/release', ['reasonCode' => 'review_complete']);
        $I->seeResponseCodeIs(HttpCode::OK);
        assertSame(false, json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['changed']);
    }

    public function terminationEndpointKeepsRequestOpenWithoutContributor(ApiTester $I): void
    {
        $db = ApiTester::getDb();
        $subject = (string) $db->createCommand("INSERT INTO core.persons (given_name, family_name) VALUES ('B3', 'Request') RETURNING id::text")->queryScalar();
        $account = (string) $db->createCommand(
            "INSERT INTO core.accounts (person_id, account_type, status) VALUES (CAST(:subject AS uuid), 'human', 'active') RETURNING id::text",
            [':subject' => $subject],
        )->queryScalar();
        $actor = new AuthenticatedIdentity(
            $account,
            $subject,
            '018f2892-aaaa-7aaa-8aaa-eeeeeeeeeeee',
            ['core.superadmin'],
            [],
            null,
            null,
        );
        $requests = ApiTester::getTestContainer()->get(DataSubjectRequestService::class);
        $id = $requests->submitSelf($actor, 'processing_termination');
        $requests->transition($actor, $id, 'in_review', null);
        $requests->transition($actor, $id, 'in_progress', null);
        $I->amAuthenticatedSuperadmin();
        $I->sendPOST('/api/core/privacy/requests/' . $id . '/processing-termination/execute', []);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        assertSame('in_progress', $requests->getVisible($actor, $id, true)?->status);
    }
}
