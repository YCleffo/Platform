<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

use function PHPUnit\Framework\assertSame;

final readonly class CustomRolesCest
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

    public function customRoleLifecycleAndProtectedSystemRoles(ApiTester $I): void
    {
        $I->sendPOST('/api/core/roles', ['code' => 'custom.editor', 'name' => 'Editor']);
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $I->amAuthenticatedSuperadmin();
        $I->sendPOST('/api/core/roles', ['code' => 'custom.editor', 'name' => 'Editor']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $role = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data'];
        assertSame('custom.editor', $role['code']);
        $id = $role['id'];
        $I->sendPUT('/api/core/roles/' . $id . '/permissions', ['permissions' => ['core.accounts.read', 'core.rbac.read']]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendGET('/api/core/roles/' . $id . '/permissions');
        $I->seeResponseCodeIs(HttpCode::OK);
        assertSame(['core.accounts.read', 'core.rbac.read'], json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['permissions']);
        $I->sendPATCH('/api/core/roles/' . $id, ['name' => 'Senior Editor']);
        $I->seeResponseCodeIs(HttpCode::OK);
        assertSame('Senior Editor', json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR)['data']['name']);
        $I->sendPUT('/api/core/roles/' . $id . '/permissions', ['permissions' => ['missing.permission']]);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);

        $system = ApiTester::getDb()->createCommand("SELECT id::text FROM core.roles WHERE code = 'core.superadmin'")->queryScalar();
        $I->sendPATCH('/api/core/roles/' . $system, ['name' => 'Renamed']);
        $I->seeResponseCodeIs(HttpCode::CONFLICT);
        $I->sendDELETE('/api/core/roles/' . $system);
        $I->seeResponseCodeIs(HttpCode::CONFLICT);
        $I->sendPUT('/api/core/roles/' . $system . '/permissions', ['permissions' => []]);
        $I->seeResponseCodeIs(HttpCode::CONFLICT);

        $I->sendDELETE('/api/core/roles/' . $id);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendGET('/api/core/roles/' . $id . '/permissions');
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
    }
}
