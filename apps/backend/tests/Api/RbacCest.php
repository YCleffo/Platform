<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

final readonly class RbacCest
{
    public function _before(ApiTester $I): void
    {
        $I->amAuthenticatedSuperadmin();
    }

    public function _after(ApiTester $I): void
    {
        $I->cleanupCoreTestFixtures();
    }

    public function rolesAndPermissionsArePaginated(ApiTester $I): void
    {
        $I->sendGET('/api/core/roles?limit=10&offset=0');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson([
            'status' => 'success',
            'data' => [
                'pagination' => [
                    'limit' => 10,
                    'offset' => 0,
                ],
            ],
        ]);
        $I->seeResponseContains('core.superadmin');

        $I->sendGET('/api/core/permissions?limit=10&offset=0');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson([
            'status' => 'success',
            'data' => [
                'pagination' => [
                    'limit' => 10,
                    'offset' => 0,
                ],
            ],
        ]);
    }

    public function superadminCanAssignAndRevokeProtectedRole(ApiTester $I): void
    {
        $target = $I->havePasswordAccount();
        $I->sendGET('/api/core/roles?limit=10&offset=0');
        $roles = $this->response($I)['data']['items'];
        $adminRoleId = null;
        foreach ($roles as $role) {
            if (is_array($role) && ($role['code'] ?? null) === 'core.admin') {
                $adminRoleId = $role['id'] ?? null;
            }
        }
        if (!is_string($adminRoleId)) {
            throw new \RuntimeException('The core.admin role fixture is missing.');
        }

        $path = '/api/core/accounts/' . $target['accountId'] . '/roles/' . $adminRoleId;
        $I->sendPOST($path);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => ['changed' => true]]);

        $I->sendGET('/api/core/accounts/' . $target['accountId'] . '/roles');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContains('core.admin');

        $I->sendDELETE($path);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => ['changed' => true]]);
    }

    /** @return array<string, mixed> */
    private function response(ApiTester $I): array
    {
        /** @var array<string, mixed> */
        return json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
    }
}
