<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringNotContainsString;

final readonly class OrganizationsCest
{
    public function _before(ApiTester $I): void
    {
        $I->cleanupCoreTestFixtures();
        $I->amAuthenticatedSuperadmin();
        $I->haveHttpHeader('Content-Type', 'application/json');
    }

    public function _after(ApiTester $I): void
    {
        $I->cleanupCoreTestFixtures();
    }

    public function membershipLifecycleAndMultipleOrganizations(ApiTester $I): void
    {
        $person = $this->person();
        $a = $this->organization($I, 'test.organization.a');
        $b = $this->organization($I, 'test.organization.b');
        $I->sendGET('/api/core/organizations');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => [['id' => $a], ['id' => $b]]]);
        $I->sendPOST('/api/core/organizations', ['code' => 'test.organization.a', 'name' => 'Duplicate']);
        $I->seeResponseCodeIs(HttpCode::CONFLICT);
        $I->sendPOST('/api/core/organizations', ['code' => 'test.invalid', 'name' => 'Invalid', 'status' => 'active']);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);

        foreach ([$a, $b] as $organization) {
            $I->sendPOST('/api/core/organizations/' . $organization . '/members', ['personId' => $person]);
            $I->seeResponseCodeIs(HttpCode::CREATED);
        }
        $I->sendPOST('/api/core/organizations/' . $a . '/members', ['personId' => $person]);
        $I->seeResponseCodeIs(HttpCode::CONFLICT);
        $I->sendPOST('/api/core/organizations/' . $a . '/members', ['personId' => $person, 'role' => 'student']);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->sendGET('/api/core/organizations/' . $b . '/members');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => [['personId' => $person, 'organizationId' => $b]]]);

        $I->sendPOST('/api/core/organizations/' . $a . '/members/' . $person . '/leave');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => ['status' => 'left']]);
        $I->sendPOST('/api/core/organizations/' . $a . '/members/' . $person . '/leave');
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->sendPOST('/api/core/organizations/' . $a . '/members', ['personId' => $person]);
        $I->seeResponseCodeIs(HttpCode::CREATED);
    }

    public function scopeAndPermissionMustBothHold(ApiTester $I): void
    {
        $actor = $this->person();
        $target = $this->person();
        $limited = $this->person();
        $outsider = $this->person();
        $a = $this->organization($I, 'test.scope.a');
        $b = $this->organization($I, 'test.scope.b');
        foreach ([[$a, $actor], [$a, $limited], [$b, $target], [$b, $outsider]] as [$organization, $person]) {
            $I->sendPOST('/api/core/organizations/' . $organization . '/members', ['personId' => $person]);
            $I->seeResponseCodeIs(HttpCode::CREATED);
        }
        $auth = $I->amAuthenticatedWithPermissions([
            'core.organizations.read', 'core.organization_memberships.read', 'core.organization_memberships.manage',
        ]);
        $this->attachPerson($auth['accountId'], $actor);
        $I->sendGET('/api/core/organizations/' . $a . '/members');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendGET('/api/core/organizations/' . $b . '/members');
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->sendPOST('/api/core/organizations/' . $b . '/members', ['personId' => $actor]);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->sendGET('/api/core/organizations');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => [['id' => $a]]]);

        $withoutPermission = $I->amAuthenticatedWithPermissions(['core.organizations.read']);
        $this->attachPerson($withoutPermission['accountId'], $limited);
        $I->sendGET('/api/core/organizations/' . $a . '/members');
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);

        $withoutScope = $I->amAuthenticatedWithPermissions(['core.organization_memberships.read']);
        $this->attachPerson($withoutScope['accountId'], $outsider);
        $I->sendGET('/api/core/organizations/' . $a . '/members');
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
    }

    public function createPermissionAndMutationAudit(ApiTester $I): void
    {
        $I->amAuthenticatedSuperadmin();
        $person = $this->person();
        $organization = $this->organization($I, 'test.audit.first');
        $I->sendPOST('/api/core/organizations/' . $organization . '/members', ['personId' => $person]);
        $I->seeResponseCodeIs(HttpCode::CREATED);
        $I->sendPOST('/api/core/organizations/' . $organization . '/members/' . $person . '/leave');
        $I->seeResponseCodeIs(HttpCode::OK);
        $rows = ApiTester::getDb()->createCommand(<<<'SQL'
            SELECT action, metadata::text FROM core.audit_events
            WHERE action IN ('organization.created', 'organization_membership.added', 'organization_membership.left')
            ORDER BY id
            SQL)->queryAll();
        assertCount(3, $rows);
        assertSame(['organization.created', 'organization_membership.added', 'organization_membership.left'],
            array_column($rows, 'action'));
        foreach ($rows as $row) {
            assertStringNotContainsString('name', $row['metadata']);
            assertStringNotContainsString('email', $row['metadata']);
            assertStringNotContainsString('phone', $row['metadata']);
        }

        $I->amAuthenticatedWithPermissions(['core.organizations.manage']);
        $I->sendPOST('/api/core/organizations', ['code' => 'test.audit.allowed', 'name' => 'Allowed']);
        $I->seeResponseCodeIs(HttpCode::CREATED);
        $I->amAuthenticatedWithPermissions([]);
        $I->sendPOST('/api/core/organizations', ['code' => 'test.audit.denied', 'name' => 'Denied']);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
    }

    private function person(): string
    {
        return (string) ApiTester::getDb()->createCommand(
            "INSERT INTO core.persons (given_name, family_name) VALUES ('Test', 'Member') RETURNING id::text",
        )->queryScalar();
    }

    private function attachPerson(string $accountId, string $personId): void
    {
        ApiTester::getDb()->createCommand(
            'UPDATE core.accounts SET person_id = CAST(:person AS uuid) WHERE id = CAST(:account AS uuid)',
            [':person' => $personId, ':account' => $accountId],
        )->execute();
    }

    private function organization(ApiTester $I, string $code): string
    {
        $I->sendPOST('/api/core/organizations', ['code' => $code, 'name' => $code]);
        $I->seeResponseCodeIs(HttpCode::CREATED);
        $response = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
        return (string) $response['data']['id'];
    }
}
