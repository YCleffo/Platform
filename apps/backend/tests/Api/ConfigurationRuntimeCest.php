<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Codeception\Util\HttpCode;

use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertNotEmpty;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class ConfigurationRuntimeCest
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

    public function moduleRegistryLifecycleIsExplicitAndIdempotent(ApiTester $I): void
    {
        $module = $I->haveConfigurationModule();

        $I->sendGET('/api/core/modules');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContains($module['code']);

        $I->sendGET('/api/core/modules/' . $module['code']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $before = $this->response($I)['data'];
        assertSame('1.2.3', $before['version']);
        assertSame(2, $before['settingsSchemaVersion']);
        $installedAt = $before['installedAt'];

        $I->sendPOST('/api/core/modules/' . $module['code'] . '/enable');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => ['enabled' => true, 'changed' => true]]);
        assertSame($installedAt, $this->response($I)['data']['installedAt']);

        $I->sendPOST('/api/core/modules/' . $module['code'] . '/enable');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => ['enabled' => true, 'changed' => false]]);

        $I->sendPOST('/api/core/modules/' . $module['code'] . '/disable');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => ['enabled' => false, 'changed' => true]]);

        $I->sendPOST('/api/core/modules/' . $module['code'] . '/disable');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => ['enabled' => false, 'changed' => false]]);

        $actions = $I->grabConfigurationAuditActions();
        assertSame(1, count(array_filter($actions, static fn(string $action): bool => $action === 'core.module.enabled')));
        assertSame(1, count(array_filter($actions, static fn(string $action): bool => $action === 'core.module.disabled')));
    }

    public function systemSettingsSupportAtomicUpsertFilteringAndSecretDenial(ApiTester $I): void
    {
        $publicKey = 'test.configuration.public';
        $privateKey = 'test.configuration.private';

        $I->sendPUT('/api/core/settings/system/' . $publicKey, [
            'value' => ['name' => 'Platform'],
            'isPublic' => true,
            'description' => 'Public application name',
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $created = $this->response($I)['data'];
        assertNotEmpty($created['updatedByAccountId']);

        $I->sendPUT('/api/core/settings/system/' . $publicKey, [
            'value' => true,
            'isPublic' => true,
            'description' => null,
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $updated = $this->response($I)['data'];
        assertSame($created['id'], $updated['id']);
        assertSame($created['createdAt'], $updated['createdAt']);
        assertTrue($updated['value']);

        $I->sendPUT('/api/core/settings/system/' . $privateKey, [
            'value' => 'internal',
            'isPublic' => false,
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);

        $module = $I->haveConfigurationModule(true);
        $I->sendPUT('/api/core/modules/' . $module['code'] . '/settings/test.configuration.private-marker', [
            'value' => 'MODULE_PRIVATE_MARKER',
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->sendPUT('/api/core/feature-flags/test.configuration.private-marker', [
            'moduleCode' => $module['code'],
            'enabled' => true,
            'rolloutPercent' => 100,
            'config' => ['label' => 'FLAG_PRIVATE_MARKER'],
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);

        $I->sendGET('/api/core/settings/system');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContains($publicKey);
        $I->seeResponseContains($privateKey);

        $I->sendPUT('/api/core/settings/system/test.configuration.smtp_password', [
            'value' => 'not-accepted',
        ]);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->seeResponseContainsJson(['error' => ['code' => 'validation.failed']]);

        $I->deleteHeader('Authorization');
        $I->sendGET('/api/public/config');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeHttpHeader('X-Content-Type-Options', 'nosniff');
        $public = $this->response($I);
        assertTrue($public['data']['settings'][$publicKey]);
        assertArrayNotHasKey($privateKey, $public['data']['settings']);
        $I->dontSeeResponseContains('updatedByAccountId');
        $I->dontSeeResponseContains('description');
        $I->dontSeeResponseContains('MODULE_PRIVATE_MARKER');
        $I->dontSeeResponseContains('FLAG_PRIVATE_MARKER');

        $I->amAuthenticatedSuperadmin();
        $I->sendDELETE('/api/core/settings/system/' . $publicKey);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContainsJson(['data' => ['deleted' => true, 'key' => $publicKey]]);
        assertContains('core.system_setting.deleted', $I->grabConfigurationAuditActions());
    }

    public function moduleSettingsSupportAtomicUpsertAndValidateOwnership(ApiTester $I): void
    {
        $module = $I->haveConfigurationModule(true);
        $path = '/api/core/modules/' . $module['code'] . '/settings/test.configuration.limits';

        $I->sendPUT($path, ['value' => ['maximum' => 10], 'description' => 'Limits']);
        $I->seeResponseCodeIs(HttpCode::OK);
        $created = $this->response($I)['data'];
        assertSame($module['code'], $created['moduleCode']);

        $I->sendPUT($path, ['value' => ['maximum' => 20], 'description' => null]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $updated = $this->response($I)['data'];
        assertSame($created['id'], $updated['id']);
        assertSame(20, $updated['value']['maximum']);

        $I->sendGET('/api/core/modules/' . $module['code'] . '/settings');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContains('test.configuration.limits');

        $I->sendPUT('/api/core/modules/test.configuration.missing/settings/test.configuration.value', [
            'value' => true,
        ]);
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->seeResponseContainsJson(['error' => ['code' => 'module.not_found']]);

        $I->sendPUT('/api/core/modules/' . $module['code'] . '/settings/test.configuration.mail', [
            'value' => ['smtpPassword' => 'not-accepted'],
        ]);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);

        $I->sendDELETE($path);
        $I->seeResponseCodeIs(HttpCode::OK);
        assertContains('core.module_setting.deleted', $I->grabConfigurationAuditActions());
    }

    public function featureFlagsSupportAtomicUpsertValidationAndAudit(ApiTester $I): void
    {
        $module = $I->haveConfigurationModule(true);
        $code = 'test.configuration.rollout';
        $path = '/api/core/feature-flags/' . $code;

        $I->sendPUT($path, [
            'moduleCode' => $module['code'],
            'enabled' => true,
            'rolloutPercent' => 25,
            'config' => ['variant' => 'blue'],
            'description' => 'Quarter rollout',
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $created = $this->response($I)['data'];

        $I->sendPUT($path, [
            'moduleCode' => null,
            'enabled' => true,
            'rolloutPercent' => 50,
            'config' => [],
            'description' => null,
        ]);
        $I->seeResponseCodeIs(HttpCode::OK);
        $updated = $this->response($I)['data'];
        assertSame($created['id'], $updated['id']);
        assertSame(50, $updated['rolloutPercent']);

        $I->sendGET('/api/core/feature-flags');
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->seeResponseContains($code);

        $I->sendPUT('/api/core/feature-flags/test.configuration.invalid', [
            'moduleCode' => 'test.configuration.missing',
            'enabled' => true,
            'rolloutPercent' => 101,
            'config' => [],
        ]);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);

        $I->sendPUT('/api/core/feature-flags/test.configuration.secret', [
            'moduleCode' => null,
            'enabled' => true,
            'rolloutPercent' => 100,
            'config' => ['apiKey' => 'not-accepted'],
        ]);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);

        $I->sendPUT('/api/core/feature-flags/test.configuration.unknown-module', [
            'moduleCode' => 'test.configuration.missing',
            'enabled' => true,
            'rolloutPercent' => 10,
            'config' => [],
        ]);
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->seeResponseContainsJson(['error' => ['code' => 'module.not_found']]);

        $I->sendDELETE($path);
        $I->seeResponseCodeIs(HttpCode::OK);
        assertContains('core.feature_flag.updated', $I->grabConfigurationAuditActions());
        assertContains('core.feature_flag.deleted', $I->grabConfigurationAuditActions());
    }

    public function validationAndMissingResourcesUseTheExistingErrorContract(ApiTester $I): void
    {
        $I->sendGET('/api/core/modules/test.configuration.missing');
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->seeResponseContainsJson(['error' => ['code' => 'module.not_found']]);

        $I->sendGET('/api/core/settings/system/test.configuration.missing');
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->seeResponseContainsJson(['error' => ['code' => 'setting.not_found']]);

        $I->sendGET('/api/core/feature-flags/test.configuration.missing');
        $I->seeResponseCodeIs(HttpCode::NOT_FOUND);
        $I->seeResponseContainsJson(['error' => ['code' => 'feature_flag.not_found']]);

        $I->sendPUT('/api/core/settings/system/' . str_repeat('a', 161), ['value' => true]);
        $I->seeResponseCodeIs(HttpCode::UNPROCESSABLE_ENTITY);
        $I->seeResponseContainsJson(['error' => ['code' => 'validation.failed']]);

        $I->sendPUT('/api/core/settings/system/test.configuration.malformed', '{"value":');
        $I->seeResponseCodeIs(HttpCode::BAD_REQUEST);
        $I->seeResponseContainsJson(['error' => ['code' => 'http.bad_request']]);
    }

    /** @return array<string, mixed> */
    private function response(ApiTester $I): array
    {
        /** @var array<string, mixed> */
        return json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
    }
}
