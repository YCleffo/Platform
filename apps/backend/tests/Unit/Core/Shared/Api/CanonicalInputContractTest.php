<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Shared\Api;

use App\Core\Account\Api\AccountInput;
use App\Core\Auth\Api\AuthInput;
use App\Core\Settings\Api\ConfigurationInput;
use App\Core\Settings\Application\SettingSecurityPolicy;
use PHPUnit\Framework\TestCase;
use Yiisoft\Input\Http\InputValidationException;

final class CanonicalInputContractTest extends TestCase
{
    /** @dataProvider invalidMachineCodesProvider */
    public function testRejectsNonCanonicalMachineCodesAtInputBoundary(string $code): void
    {
        foreach ([
            static fn(): string => ConfigurationInput::moduleCode($code),
            static fn(): string => ConfigurationInput::featureCode($code),
            static fn(): string => ConfigurationInput::settingKey($code),
        ] as $validate) {
            try {
                $validate();
                self::fail("Machine code {$code} must be rejected.");
            } catch (InputValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public static function invalidMachineCodesProvider(): array
    {
        return [
            ['Core.Admin'],
            ['core..admin'],
            ['core--'],
            ['core.'],
            ['.core'],
        ];
    }

    public function testAcceptsCanonicalMachineCodesAtEveryConfigurationBoundary(): void
    {
        self::assertSame('core.admin', ConfigurationInput::moduleCode('core.admin'));
        self::assertSame('core.feature_flag', ConfigurationInput::featureCode('core.feature_flag'));
        self::assertSame('core.setting-key', ConfigurationInput::settingKey('core.setting-key'));
    }

    /** @dataProvider invalidLabelsProvider */
    public function testRejectsInvalidAccountTotpAndPasskeyLabels(string $label): void
    {
        foreach ([
            static fn(): mixed => AccountInput::create(['accountType' => 'service', 'label' => $label]),
            static fn(): mixed => AuthInput::totpSetup(['label' => $label]),
            static fn(): mixed => AuthInput::passkeyRename(['label' => $label]),
            static fn(): mixed => AuthInput::passkeyRegister([
                'credential' => ['response' => []],
                'label' => $label,
            ]),
        ] as $validate) {
            try {
                $validate();
                self::fail('Invalid label must be rejected.');
            } catch (InputValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public static function invalidLabelsProvider(): array
    {
        return [
            ['   '],
            ["bad\0label"],
            ["bad\tlabel"],
            [str_repeat('a', 161)],
        ];
    }

    public function testNormalizesLabelsWithoutHtmlEscaping(): void
    {
        $account = AccountInput::create([
            'accountType' => 'service',
            'label' => '  <b>Service</b>   account  ',
        ]);
        self::assertSame('<b>Service</b> account', $account->label);
        self::assertSame('Security key', AuthInput::passkeyRename(['label' => ' Security   key ']));
    }

    /** @dataProvider invalidDescriptionsProvider */
    public function testRejectsInvalidConfigurationDescriptions(string $description): void
    {
        $this->expectException(InputValidationException::class);
        ConfigurationInput::systemSetting(
            ['value' => true, 'description' => $description],
            'core.setting',
            new SettingSecurityPolicy(),
        );
    }

    public static function invalidDescriptionsProvider(): array
    {
        return [
            ['   '],
            ["bad\0description"],
            ["bad\ndescription"],
            [str_repeat('a', 1_001)],
        ];
    }

    public function testNormalizesConfigurationDescriptionWithoutHtmlEscaping(): void
    {
        $input = ConfigurationInput::systemSetting(
            ['value' => true, 'description' => '  <b>Public</b>   description  '],
            'core.setting',
            new SettingSecurityPolicy(),
        );

        self::assertSame('<b>Public</b> description', $input['description']);
    }

    /** @dataProvider invalidUsernamesProvider */
    public function testRejectsInvalidUsernameAtLoginBoundary(string $username): void
    {
        $this->expectException(InputValidationException::class);
        AuthInput::login([
            'identifierType' => 'username',
            'identifier' => $username,
            'password' => 'irrelevant',
        ]);
    }

    public static function invalidUsernamesProvider(): array
    {
        return [
            ['Alice'],
            ['алиса'],
            ['core..admin'],
            ['.core'],
            ['core.'],
        ];
    }
}
