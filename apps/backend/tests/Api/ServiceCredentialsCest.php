<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Core\Auth\Application\ServiceCredentialService;
use App\Core\Auth\Application\AuthCleanupPolicy;
use App\Core\Auth\Application\AuthCleanupRepository;
use App\Core\Auth\Application\ServiceScopePolicy;
use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;
use DomainException;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringNotContainsString;

final readonly class ServiceCredentialsCest
{
    public function _before(ApiTester $I): void
    {
        $I->cleanupCoreTestFixtures();
        $I->amAuthenticatedSuperadmin();
        $I->haveHttpHeader('Content-Type', 'application/json');
    }

    public function _after(ApiTester $I): void { $I->cleanupCoreTestFixtures(); }

    public function lifecycleAndScope(ApiTester $I): void
    {
        $db = ApiTester::getDb();
        $auth = ApiTester::getTestContainer()->get(ServiceCredentialService::class);
        $policy = ApiTester::getTestContainer()->get(ServiceScopePolicy::class);
        foreach (['service', 'system'] as $type) {
            $account = (string) $db->createCommand(
                "INSERT INTO core.accounts (account_type, status, label) VALUES (CAST(:type AS core.account_type), 'active', 'M2M test') RETURNING id::text",
                [':type' => $type],
            )->queryScalar();
            $url = '/api/core/accounts/' . $account . '/service-credentials';
            $I->sendPOST($url, ['scopes' => ['usage.report']]);
            $I->seeResponseCodeIs(HttpCode::CREATED);
            $created = $this->data($I);
            $secret = $created['secret'];
            $id = $created['credentialId'];
            assertSame(43, strlen($secret));
            $stored = $db->createCommand("SELECT encode(secret_hash, 'hex') FROM core.service_credentials WHERE credential_id = CAST(:id AS uuid)",
                [':id' => $id])->queryScalar();
            assertNotSame($secret, $stored);
            assertSame(hash('sha256', $secret), $stored);
            $identity = $auth->authenticate('Service ' . $id . '.' . $secret, $type . '-client');
            assertSame($account, $identity?->accountId);
            $policy->requireScope($identity, 'usage.report');
            try {
                $policy->requireScope($identity, 'runtime.entitlements.apply');
                throw new \RuntimeException('Missing scope accepted.');
            } catch (DomainException) {}
            assertNull($auth->authenticate('Service ' . $id . '.' . str_repeat('x', 43), $type . '-client'));
            $I->sendGET($url);
            $I->seeResponseCodeIs(HttpCode::OK);
            assertStringNotContainsString($secret, $I->grabResponse());
            assertStringNotContainsString('secret_hash', $I->grabResponse());

            $I->sendPOST($url . '/' . $id . '/rotate');
            $I->seeResponseCodeIs(HttpCode::OK);
            $rotated = $this->data($I);
            assertNotSame($id, $rotated['credentialId']);
            assertNotSame($secret, $rotated['secret']);
            assertNull($auth->authenticate('Service ' . $id . '.' . $secret, $type . '-client'));
            assertSame($account, $auth->authenticate('Service ' . $rotated['credentialId'] . '.' . $rotated['secret'], $type . '-client')?->accountId);
            $I->sendPOST($url . '/' . $rotated['credentialId'] . '/revoke');
            $I->seeResponseCodeIs(HttpCode::OK);
            $I->sendPOST($url . '/' . $rotated['credentialId'] . '/revoke');
            $I->seeResponseCodeIs(HttpCode::OK);
            assertNull($auth->authenticate('Service ' . $rotated['credentialId'] . '.' . $rotated['secret'], $type . '-client'));
        }
        $audit = implode(' ', $db->createCommand("SELECT metadata::text FROM core.audit_events WHERE action LIKE 'service_credential.%'")->queryColumn());
        assertStringNotContainsString($secret, $audit);
        assertStringNotContainsString((string) $stored, $audit);
        $db->createCommand("UPDATE core.service_credentials SET created_at = now() - interval '4 days', revoked_at = now() - interval '3 days' WHERE credential_id = CAST(:id AS uuid)",
            [':id' => $rotated['credentialId']])->execute();
        $cleanup = ApiTester::getTestContainer()->get(AuthCleanupRepository::class);
        $report = $cleanup->cleanup(new AuthCleanupPolicy(30, 30, 100, 1));
        assertSame(1, $report->serviceCredentials);
    }

    public function rejectsHumanUnknownScopesAndExpiredOrDisabledCredentials(ApiTester $I): void
    {
        $db = ApiTester::getDb();
        $actor = (string) $db->createCommand("SELECT id::text FROM core.accounts WHERE account_type = 'human' LIMIT 1")->queryScalar();
        $I->sendPOST('/api/core/accounts/' . $actor . '/service-credentials', ['scopes' => ['usage.report']]);
        $I->seeResponseCodeIs(HttpCode::CONFLICT);
        $account = (string) $db->createCommand(
            "INSERT INTO core.accounts (account_type, status) VALUES ('service', 'active') RETURNING id::text",
        )->queryScalar();
        $url = '/api/core/accounts/' . $account . '/service-credentials';
        $I->sendPOST($url, ['scopes' => ['unknown.scope']]);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->sendPOST($url, ['scopes' => ['usage.report'], 'expiresAt' => '2099-01-01T00:00:00Z']);
        $I->seeResponseCodeIs(HttpCode::CREATED);
        $created = $this->data($I);
        $auth = ApiTester::getTestContainer()->get(ServiceCredentialService::class);
        $header = 'Service ' . $created['credentialId'] . '.' . $created['secret'];
        $db->createCommand(<<<'SQL'
            UPDATE core.service_credentials SET created_at = now() - interval '3 days',
                expires_at = now() - interval '1 day'
            WHERE credential_id = CAST(:id AS uuid)
            SQL, [':id' => $created['credentialId']])->execute();
        assertNull($auth->authenticate($header, 'expired-client'));
        $db->createCommand("UPDATE core.service_credentials SET expires_at = now() + interval '1 day' WHERE credential_id = CAST(:id AS uuid)",
            [':id' => $created['credentialId']])->execute();
        $db->createCommand("UPDATE core.accounts SET status = 'disabled', disabled_at = now() WHERE id = CAST(:id AS uuid)",
            [':id' => $account])->execute();
        assertNull($auth->authenticate($header, 'disabled-client'));
    }

    private function data(ApiTester $I): array
    {
        return json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data'];
    }
}
