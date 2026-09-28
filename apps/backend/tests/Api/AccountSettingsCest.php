<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class AccountSettingsCest
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

    public function authenticationBoundaryAndDefaultRead(ApiTester $I): void
    {
        $I->sendGET('/api/auth/me/settings');
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);

        $I->amAuthenticatedSuperadmin();
        $I->sendGET('/api/auth/me/settings');
        $I->seeResponseCodeIs(HttpCode::OK);
        $data = $this->response($I)['data'];
        assertSame(2, $data['schema_version']);
        assertFalse($data['settings']['privacy']['show_online_status']);
        assertFalse(isset($data['settings']['privacy']['profile_visibility']));
    }

    public function patchDeepMergesPrivacyAndNullResets(ApiTester $I): void
    {
        $fixture = $I->amAuthenticatedSuperadmin();
        $securityBefore = ApiTester::getDb()->createCommand(
            <<<'SQL'
            SELECT
                account.security_stamp::text AS security_stamp,
                (SELECT count(*) FROM core.account_roles WHERE account_id = account.id) AS roles,
                (SELECT count(*) FROM core.auth_sessions WHERE account_id = account.id) AS sessions
            FROM core.accounts account
            WHERE account.id = CAST(:id AS uuid)
            SQL,
            [':id' => $fixture['accountId']],
        )->queryOne();
        $I->sendPATCH('/api/auth/me/settings', [
            'notifications' => ['browser' => false],
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $settings = $this->response($I)['data']['settings'];
        assertFalse($settings['notifications']['browser']);
        assertTrue($settings['notifications']['sound']);

        $I->sendPATCH('/api/auth/me/settings', ['privacy' => ['show_online_status' => true]]);
        $I->sendPATCH('/api/auth/me/settings', ['privacy' => ['show_online_status' => null]]);
        $I->seeResponseCodeIs(HttpCode::OK);
        assertFalse($this->response($I)['data']['settings']['privacy']['show_online_status']);

        $row = ApiTester::getDb()->createCommand(
            "SELECT count(*) FROM core.audit_events WHERE action = 'core.account_settings.updated' AND actor_account_id = CAST(:id AS uuid)",
            [':id' => $fixture['accountId']],
        )->queryScalar();
        assertSame(3, (int) $row);
        $securityAfter = ApiTester::getDb()->createCommand(
            <<<'SQL'
            SELECT
                account.security_stamp::text AS security_stamp,
                (SELECT count(*) FROM core.account_roles WHERE account_id = account.id) AS roles,
                (SELECT count(*) FROM core.auth_sessions WHERE account_id = account.id) AS sessions
            FROM core.accounts account
            WHERE account.id = CAST(:id AS uuid)
            SQL,
            [':id' => $fixture['accountId']],
        )->queryOne();
        assertSame($securityBefore, $securityAfter);
    }

    public function rejectsUnknownTypesTargetingMalformedAndOversizedPayloads(ApiTester $I): void
    {
        $fixture = $I->amAuthenticatedSuperadmin();
        $other = ApiTester::getDb()->createCommand(
            "INSERT INTO core.accounts (account_type, status, label) VALUES ('service', 'active', 'other settings target') RETURNING id::text",
        )->queryScalar();

        foreach ([
            ['privacy' => ['unknown' => false]],
            ['privacy' => ['show_online_status' => 1]],
            ['account_id' => $other, 'privacy' => ['show_online_status' => false]],
            ['security' => ['sessions' => false]],
        ] as $invalid) {
            $I->sendPATCH('/api/auth/me/settings', $invalid);
            $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
            $I->seeResponseContainsJson(['error' => ['code' => 'validation.failed']]);
        }

        $I->sendPATCH('/api/auth/me/settings', '{"privacy":');
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);

        $I->sendPATCH('/api/auth/me/settings', '{"unknown":"' . str_repeat('x', 33_000) . '"}');
        $I->seeResponseCodeIs(413);

        $count = ApiTester::getDb()->createCommand(
            'SELECT count(*) FROM core.account_settings WHERE account_id IN (CAST(:self AS uuid), CAST(:other AS uuid))',
            [':self' => $fixture['accountId'], ':other' => $other],
        )->queryScalar();
        assertSame(0, (int) $count);
    }

    /** @return array<string, mixed> */
    private function response(ApiTester $I): array
    {
        return json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
    }
}
