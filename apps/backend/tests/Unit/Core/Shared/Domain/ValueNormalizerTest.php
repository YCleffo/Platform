<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Shared\Domain;

use App\Core\Shared\Domain\ValueNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ValueNormalizerTest extends TestCase
{
    /**
     * @dataProvider validPersonNamesProvider
     */
    public function testValidPersonNames(string $input, string $expected): void
    {
        self::assertSame($expected, ValueNormalizer::normalizePersonName($input));
        self::assertTrue(ValueNormalizer::isValidPersonName($input));
    }

    public static function validPersonNamesProvider(): array
    {
        return [
            ['Юрий', 'Юрий'],
            ['Никогосян', 'Никогосян'],
            ['Анна-Мария', 'Анна-Мария'],
            ["О'Коннор", "О'Коннор"],
            ['O’Connor', 'O’Connor'],
            ['Жан-Пьер', 'Жан-Пьер'],
            ['María', 'María'],
            ['José', 'José'],
            ['François', 'François'],
            ['Łukasz', 'Łukasz'],
            ['Nguyễn', 'Nguyễn'],
            ['  Юрий   Никогосян  ', 'Юрий Никогосян'],
            ['Jean-Luc', 'Jean-Luc'],
        ];
    }

    /**
     * @dataProvider invalidPersonNamesProvider
     */
    public function testInvalidPersonNames(string $input): void
    {
        self::assertFalse(ValueNormalizer::isValidPersonName($input));
        $this->expectException(InvalidArgumentException::class);
        ValueNormalizer::normalizePersonName($input);
    }

    public static function invalidPersonNamesProvider(): array
    {
        return [
            ['Юрий123'],
            ['123Юрий'],
            ['@Юрий'],
            ['Юрий!!!'],
            ['<script>'],
            ['%%%'],
            ['----'],
            ["'''"],
            ['   '],
            ["Юрий\x00"],
            ["Юрий\r\n"],
            ['Анна--Мария'],
            ['-Анна'],
            ['Анна-'],
            ["O''Connor"],
            [''],
        ];
    }

    /**
     * @dataProvider validPhoneNumbersProvider
     */
    public function testValidPhoneNumbers(string $input, string $expected): void
    {
        self::assertSame($expected, ValueNormalizer::normalizePhone($input));
        self::assertTrue(ValueNormalizer::isValidPhone($input));
    }

    public static function validPhoneNumbersProvider(): array
    {
        return [
            ['+79991234567', '+79991234567'],
            ['+7 (999) 123-45-67', '+79991234567'],
            ['+1 (800) 555-0199', '+18005550199'],
            ['+44 20 7946 0991', '+442079460991'],
            ['+33-1-42-68-55-55', '+33142685555'],
        ];
    }

    /**
     * @dataProvider invalidPhoneNumbersProvider
     */
    public function testInvalidPhoneNumbers(string $input): void
    {
        self::assertFalse(ValueNormalizer::isValidPhone($input));
        $this->expectException(InvalidArgumentException::class);
        ValueNormalizer::normalizePhone($input);
    }

    public static function invalidPhoneNumbersProvider(): array
    {
        return [
            ['89991234567'],          // Local format without '+'
            ['+7 (999) 123-45-6a'],   // Alphabetic character
            ['+123456'],              // Too short (< 8 digits)
            ['+1234567890123456'],    // Too long (> 15 digits)
            ['phone'],                // String
            [''],                     // Empty
            ['+012345678'],           // Invalid country code starting with 0
        ];
    }

    /**
     * @dataProvider validEmailsProvider
     */
    public function testValidEmails(string $input, string $expected): void
    {
        self::assertSame($expected, ValueNormalizer::normalizeEmail($input));
        self::assertTrue(ValueNormalizer::isValidEmail($input));
    }

    public static function validEmailsProvider(): array
    {
        return [
            ['user@example.com', 'user@example.com'],
            ['  User@EXAMPLE.COM  ', 'user@example.com'],
            ['john.doe+tag@sub.domain.org', 'john.doe+tag@sub.domain.org'],
            ['info@россия.рф', 'info@xn--h1alffa9f.xn--p1ai'],
        ];
    }

    /**
     * @dataProvider invalidEmailsProvider
     */
    public function testInvalidEmails(string $input): void
    {
        self::assertFalse(ValueNormalizer::isValidEmail($input));
        $this->expectException(InvalidArgumentException::class);
        ValueNormalizer::normalizeEmail($input);
    }

    public static function invalidEmailsProvider(): array
    {
        return [
            ['not-an-email'],
            ['user@@example.com'],
            ['user@'],
            ['@example.com'],
            ["user \x00@example.com"],
            [''],
        ];
    }

    /**
     * @dataProvider validUsernamesProvider
     */
    public function testValidUsernames(string $input, string $expected): void
    {
        self::assertSame($expected, ValueNormalizer::normalizeUsername($input));
        self::assertTrue(ValueNormalizer::isValidUsername($input));
    }

    public static function validUsernamesProvider(): array
    {
        return [
            ['john_doe', 'john_doe'],
            ['jane.doe', 'jane.doe'],
            ['user-123', 'user-123'],
            ['user_123', 'user_123'],
            ['admin', 'admin'],
        ];
    }

    /**
     * @dataProvider invalidUsernamesProvider
     */
    public function testInvalidUsernames(string $input): void
    {
        self::assertFalse(ValueNormalizer::isValidUsername($input));
        $this->expectException(InvalidArgumentException::class);
        ValueNormalizer::normalizeUsername($input);
    }

    public static function invalidUsernamesProvider(): array
    {
        return [
            ['..'],
            ['---'],
            ['___'],
            ['_user'],
            ['user_'],
            ['.user'],
            ['user.'],
            ['-user'],
            ['user-'],
            ['user..name'],
            ['user__name'],
            ['user--name'],
            ['user  name'],
            [' user'],
            ['user '],
            ['Jane.Doe'],
            ['Юрий_123'],
            ['Ａlice'],
            ['<script>'],
            ['ab'], // < 3 chars
            [''],
        ];
    }

    /**
     * @dataProvider validMachineCodesProvider
     */
    public function testValidMachineCodes(string $input, string $expected): void
    {
        self::assertSame($expected, ValueNormalizer::normalizeMachineCode($input));
        self::assertTrue(ValueNormalizer::isValidMachineCode($input));
    }

    public static function validMachineCodesProvider(): array
    {
        return [
            ['core.superadmin', 'core.superadmin'],
            ['core.settings.read', 'core.settings.read'],
            ['test.configuration.foo', 'test.configuration.foo'],
            ['auth_passkey', 'auth_passkey'],
        ];
    }

    /**
     * @dataProvider invalidMachineCodesProvider
     */
    public function testInvalidMachineCodes(string $input): void
    {
        self::assertFalse(ValueNormalizer::isValidMachineCode($input));
        $this->expectException(InvalidArgumentException::class);
        ValueNormalizer::normalizeMachineCode($input);
    }

    public static function invalidMachineCodesProvider(): array
    {
        return [
            ['.core'],
            ['core.'],
            ['-core'],
            ['core-'],
            ['core..admin'],
            ['core--admin'],
            ['Core.Admin'],
            [' core.admin'],
            ['core.admin '],
            ['core/admin'],
            [''],
        ];
    }

    public function testNormalizesLabelsAndTextWithoutHtmlEscaping(): void
    {
        self::assertSame(
            '<b>Пример</b> label',
            ValueNormalizer::normalizeLabel("  <b>Пример</b>\u{00A0}  label  ", 160),
        );
        self::assertSame(
            'Unicode description',
            ValueNormalizer::normalizeText('  Unicode   description  '),
        );
        self::assertNull(ValueNormalizer::normalizeLabel(null));
        self::assertNull(ValueNormalizer::normalizeText(null));
    }

    /** @dataProvider invalidTextProvider */
    public function testRejectsInvalidLabelsAndText(string $input): void
    {
        self::assertFalse(ValueNormalizer::isValidLabel($input, 8));
        self::assertFalse(ValueNormalizer::isValidText($input, 8));
    }

    public static function invalidTextProvider(): array
    {
        return [
            [''],
            ['   '],
            ["bad\0text"],
            ["bad\ntext"],
            [str_repeat('a', 9)],
        ];
    }
}
