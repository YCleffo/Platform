<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\AccountSettings;

use App\Core\AccountSettings\Application\AccountSettingDefinition;
use App\Core\AccountSettings\Application\SettingsSchemaRegistry;
use App\Core\AccountSettings\Application\SettingsValidationException;
use Codeception\Test\Unit;

final class SettingsSchemaRegistryTest extends Unit
{
    public function testDefaultsAndDeepPatchPreserveSiblings(): void
    {
        $registry = new SettingsSchemaRegistry();
        $patch = $registry->validatePatch(['notifications' => ['browser' => false]]);
        $overrides = $registry->applyPatch([], $patch);
        $effective = $registry->effective($overrides);

        self::assertFalse($effective['notifications']['browser']);
        self::assertTrue($effective['notifications']['sound']);
        self::assertFalse($effective['privacy']['show_online_status']);
        self::assertArrayNotHasKey('profile_visibility', $effective['privacy']);
    }

    public function testNullResetsOverrideToVersionedDefault(): void
    {
        $registry = new SettingsSchemaRegistry();
        $overrides = $registry->applyPatch([], ['privacy' => ['show_online_status' => false]]);
        $overrides = $registry->applyPatch($overrides, ['privacy' => ['show_online_status' => null]]);

        self::assertSame([], $overrides);
        self::assertFalse($registry->effective($overrides)['privacy']['show_online_status']);
    }

    /** @dataProvider invalidPatches */
    public function testRejectsUnknownDangerousAndInvalidValues(array $patch): void
    {
        $this->expectException(SettingsValidationException::class);
        (new SettingsSchemaRegistry())->validatePatch($patch);
    }

    public static function invalidPatches(): iterable
    {
        yield 'unknown namespace' => [['security' => ['mfa' => false]]];
        yield 'unknown leaf' => [['privacy' => ['tracking' => false]]];
        yield 'prototype key' => [['privacy' => ['__proto__' => true]]];
        yield 'wrong boolean type' => [['privacy' => ['show_online_status' => 1]]];
        yield 'malformed nested value' => [['privacy' => true]];
        yield 'empty patch' => [[]];
    }

    public function testExtensibleDefinitionsEnforceEnumRangeAndListLimits(): void
    {
        $registry = new SettingsSchemaRegistry([
            new AccountSettingDefinition('example.mode', 'enum', 'compact', ['compact', 'wide']),
            new AccountSettingDefinition('example.density', 'integer', 1, minimum: 1, maximum: 3),
            new AccountSettingDefinition('example.channels', 'list', [], maximumItems: 2, itemType: 'string'),
        ]);
        $valid = $registry->validatePatch(['example' => [
            'mode' => 'wide',
            'density' => 3,
            'channels' => ['browser', 'email'],
        ]]);
        self::assertSame('wide', $valid['example']['mode']);

        foreach ([
            ['example' => ['mode' => 'other']],
            ['example' => ['density' => 4]],
            ['example' => ['channels' => ['a', 'b', 'c']]],
            ['example' => ['channels' => ['a', 2]]],
        ] as $invalid) {
            try {
                $registry->validatePatch($invalid);
                self::fail('Invalid extensible definition value was accepted.');
            } catch (SettingsValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testFutureSchemaVersionIsRejectedExplicitly(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SettingsSchemaRegistry())->upgradeStored([], 3);
    }

    public function testVersionOneDropsEducationOverridesAndPreservesGenericSettings(): void
    {
        $registry = new SettingsSchemaRegistry();
        $upgraded = $registry->upgradeStored([
            'privacy' => ['profile_visibility' => ['students' => false, 'parents' => false], 'show_online_status' => false],
            'notifications' => ['email' => true],
        ], 1);
        self::assertSame(['privacy' => ['show_online_status' => false], 'notifications' => ['email' => true]], $upgraded);
        self::assertFalse($registry->effective($upgraded)['privacy']['show_online_status']);
    }
}
