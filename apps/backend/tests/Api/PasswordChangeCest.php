<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

final readonly class PasswordChangeCest
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

    public function selfChangeRequiresCurrentPasswordAndKeepsOnlyCurrentSession(ApiTester $I): void
    {
        $fixture = $I->havePasswordAccount();
        $first = $this->login($I, $fixture['email'], $fixture['password']);
        $other = $this->login($I, $fixture['email'], $fixture['password']);
        $I->haveHttpHeader('Authorization', 'Bearer ' . $first['accessToken']);
        $I->sendPOST('/api/auth/me/password/change', ['currentPassword' => 'incorrect', 'newPassword' => 'New secure password 123']);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->sendPOST('/api/auth/me/password/change', ['currentPassword' => $fixture['password'], 'newPassword' => 'short']);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->sendPOST('/api/auth/me/password/change', ['currentPassword' => $fixture['password'], 'newPassword' => $fixture['password']]);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);

        $before = ApiTester::getDb()->createCommand('SELECT security_stamp::text FROM core.accounts WHERE id = CAST(:id AS uuid)', [':id' => $fixture['accountId']])->queryScalar();
        $I->sendPOST('/api/auth/me/password/change', ['currentPassword' => $fixture['password'], 'newPassword' => 'New secure password 123']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $after = ApiTester::getDb()->createCommand('SELECT security_stamp::text FROM core.accounts WHERE id = CAST(:id AS uuid)', [':id' => $fixture['accountId']])->queryScalar();
        assertNotSame($before, $after);
        $I->sendGET('/api/auth/me');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->haveHttpHeader('Authorization', 'Bearer ' . $other['accessToken']);
        $I->sendGET('/api/auth/me');
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $audit = ApiTester::getDb()->createCommand("SELECT count(*) FROM core.audit_events WHERE action = 'auth.password.changed' AND resource_id = :id", [':id' => $fixture['accountId']])->queryScalar();
        assertSame(1, (int) $audit);
        $email = ApiTester::getDb()->createCommand("SELECT count(*) FROM core.outbox_events WHERE aggregate_id = :id AND payload #>> '{templateCode}' = 'auth.password.changed'", [':id' => $fixture['accountId']])->queryScalar();
        assertSame(1, (int) $email);
    }

    public function forcedChangeIssuesShortLivedChallengeAndNoSessionBeforeCompletion(ApiTester $I): void
    {
        $fixture = $I->havePasswordAccount();
        ApiTester::getDb()->createCommand('UPDATE core.auth_password_credentials SET must_change_password = true WHERE account_id = CAST(:id AS uuid)', [':id' => $fixture['accountId']])->execute();
        $first = $this->login($I, $fixture['email'], $fixture['password']);
        assertSame('password_change_required', $first['status']);
        assertArrayNotHasKey('accessToken', $first);
        $sessions = ApiTester::getDb()->createCommand('SELECT count(*) FROM core.auth_sessions WHERE account_id = CAST(:id AS uuid)', [':id' => $fixture['accountId']])->queryScalar();
        assertSame(0, (int) $sessions);
        $I->sendPOST('/api/auth/password/change-required', [
            'challengeToken' => $first['challengeToken'],
            'currentPassword' => $fixture['password'],
            'newPassword' => 'Changed required password 123',
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $completed = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data'];
        assertSame('authenticated', $completed['status']);
        $flag = ApiTester::getDb()->createCommand('SELECT must_change_password FROM core.auth_password_credentials WHERE account_id = CAST(:id AS uuid)', [':id' => $fixture['accountId']])->queryScalar();
        assertSame(false, $flag === true || $flag === 't');
        $I->sendPOST('/api/auth/password/change-required', [
            'challengeToken' => $first['challengeToken'],
            'currentPassword' => $fixture['password'],
            'newPassword' => 'Another secure password 123',
        ]);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
    }

    /** @return array<string, mixed> */
    private function login(ApiTester $I, string $email, string $password): array
    {
        $I->sendPOST('/api/auth/login', ['identifierType' => 'email', 'identifier' => $email, 'password' => $password]);
        $I->seeResponseCodeIs(HttpCode::OK);
        return json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data'];
    }
}
