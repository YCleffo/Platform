<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

final readonly class ConfigurationAuthorizationCest
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

    public function modulesEnforceReadManageAdminAndSuperadminBoundaries(ApiTester $I): void
    {
        $module = $I->haveConfigurationModule();
        $list = '/api/core/modules';
        $enable = '/api/core/modules/' . $module['code'] . '/enable';

        $I->sendGET($list);
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $I->amAuthenticatedWithPermissions([]);
        $I->sendGET($list);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->amAuthenticatedWithPermissions(['core.modules.read']);
        $I->sendGET($list);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendPOST($enable);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->amAuthenticatedWithPermissions(['core.modules.manage']);
        $I->sendPOST($enable);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->amAuthenticatedRole('core.admin');
        $I->sendGET($list);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendPOST('/api/core/modules/' . $module['code'] . '/disable');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->amAuthenticatedSuperadmin();
        $I->sendPOST($enable);
        $I->seeResponseCodeIs(HttpCode::OK);
    }

    public function settingsEnforceReadAndManageBoundaries(ApiTester $I): void
    {
        $module = $I->haveConfigurationModule();
        $systemList = '/api/core/settings/system';
        $systemPut = '/api/core/settings/system/test.configuration.auth';
        $moduleList = '/api/core/modules/' . $module['code'] . '/settings';
        $modulePut = $moduleList . '/test.configuration.auth';

        $I->sendGET($systemList);
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $I->sendGET($moduleList);
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $I->amAuthenticatedWithPermissions([]);
        $I->sendGET($systemList);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->sendGET($moduleList);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->amAuthenticatedWithPermissions(['core.settings.read']);
        $I->sendGET($systemList);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendGET($moduleList);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendPUT($systemPut, ['value' => true]);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->sendPUT($modulePut, ['value' => true]);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->amAuthenticatedWithPermissions(['core.settings.manage']);
        $I->sendPUT($systemPut, ['value' => true]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendPUT($modulePut, ['value' => true]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->amAuthenticatedRole('core.admin');
        $I->sendGET($systemList);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendGET($moduleList);
        $I->seeResponseCodeIs(HttpCode::OK);
    }

    public function featureFlagsEnforceReadAndManageBoundaries(ApiTester $I): void
    {
        $list = '/api/core/feature-flags';
        $put = '/api/core/feature-flags/test.configuration.auth';
        $payload = ['moduleCode' => null, 'enabled' => true, 'rolloutPercent' => 100, 'config' => []];

        $I->sendGET($list);
        $I->seeResponseCodeIs(HttpCode::UNAUTHORIZED);
        $I->amAuthenticatedWithPermissions([]);
        $I->sendGET($list);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->amAuthenticatedWithPermissions(['core.feature_flags.read']);
        $I->sendGET($list);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendPUT($put, $payload);
        $I->seeResponseCodeIs(HttpCode::FORBIDDEN);
        $I->amAuthenticatedWithPermissions(['core.feature_flags.manage']);
        $I->sendPUT($put, $payload);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->amAuthenticatedRole('core.admin');
        $I->sendGET($list);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->amAuthenticatedSuperadmin();
        $I->sendDELETE($put);
        $I->seeResponseCodeIs(HttpCode::OK);
    }
}
