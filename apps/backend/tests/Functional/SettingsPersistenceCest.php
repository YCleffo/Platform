<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Module\Application\ModuleService;
use App\Core\Settings\Application\FeatureFlagService;
use App\Core\Settings\Application\SettingsService;
use App\Environment;
use App\Tests\Support\FunctionalTester;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Yii\Runner\Http\HttpApplicationRunner;

use function dirname;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final readonly class SettingsPersistenceCest
{
    public function servicesReadNonSecretConfiguration(FunctionalTester $tester): void
    {
        $runner = new HttpApplicationRunner(
            rootPath: dirname(__DIR__, 2),
            environment: Environment::appEnv(),
        );
        $container = $runner->getContainer();
        $db = $container->get(ConnectionInterface::class);
        $transaction = $db->beginTransaction();

        try {
            $suffix = bin2hex(random_bytes(6));
            $moduleCode = 'integration-' . $suffix;
            $moduleId = (string) $db->createCommand(
                <<<'SQL'
                INSERT INTO core.modules (code, name, version, enabled, enabled_at)
                VALUES (:code, 'Integration module', '1.0.0', true, now())
                RETURNING id::text
                SQL,
                [':code' => $moduleCode],
            )->queryScalar();
            $db->createCommand(
                <<<'SQL'
                INSERT INTO core.module_settings (module_id, setting_key, value)
                VALUES (CAST(:module_id AS uuid), 'limits', '{"max":25}'::jsonb)
                SQL,
                [':module_id' => $moduleId],
            )->execute();
            $db->createCommand(
                <<<'SQL'
                INSERT INTO core.feature_flags (module_id, code, enabled, rollout_percent)
                VALUES
                    (CAST(:module_id AS uuid), :full_code, true, 100),
                    (CAST(:module_id AS uuid), :partial_code, true, 50)
                SQL,
                [
                    ':module_id' => $moduleId,
                    ':full_code' => 'full-' . $suffix,
                    ':partial_code' => 'partial-' . $suffix,
                ],
            )->execute();
            $db->createCommand(
                <<<'SQL'
                INSERT INTO core.system_settings (setting_key, value, is_public)
                VALUES
                    (:public_key, '"Platform"'::jsonb, true),
                    (:private_key, '"internal"'::jsonb, false)
                SQL,
                [
                    ':public_key' => 'public-' . $suffix,
                    ':private_key' => 'private-' . $suffix,
                ],
            )->execute();

            assertTrue($container->get(ModuleService::class)->isEnabled($moduleCode));
            assertFalse($container->get(ModuleService::class)->isEnabled('missing-' . $suffix));

            $settings = $container->get(SettingsService::class);
            assertSame(['max' => 25], $settings->getModuleSetting($moduleCode, 'limits'));
            assertSame('internal', $settings->getSystemSetting('private-' . $suffix));
            assertSame(1, count(array_filter(
                $settings->getPublicSystemSettings(),
                static fn($setting): bool => $setting->key === 'public-' . $suffix,
            )));

            $flags = $container->get(FeatureFlagService::class);
            assertTrue($flags->isEnabled('full-' . $suffix));
            assertSame(
                $flags->isEnabledFor('partial-' . $suffix, 'account-fixture'),
                $flags->isEnabledFor('partial-' . $suffix, 'account-fixture'),
            );
        } finally {
            if ($transaction->isActive()) {
                $transaction->rollBack();
            }
        }
    }
}
