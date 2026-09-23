<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Person\Api;

use App\Core\Person\Api\PersonInput;
use Codeception\Test\Unit;
use Yiisoft\Input\Http\InputValidationException;

use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

final class PersonInputTest extends Unit
{
    /**
     * @dataProvider validInternationalNamesProvider
     */
    public function testBuildsStrictCreateCommandWithInternationalNames(
        string $givenName,
        string $familyName,
        ?string $middleName,
    ): void {
        $command = PersonInput::createPerson([
            'givenName' => $givenName,
            'familyName' => $familyName,
            'middleName' => $middleName,
            'displayName' => $givenName . ' ' . $familyName,
            'birthDate' => '2004-11-18',
        ]);

        assertSame($givenName, $command->givenName);
        assertSame($familyName, $command->familyName);
        assertSame($middleName, $command->middleName);
        assertSame('2004-11-18', $command->birthDate);
    }

    public static function validInternationalNamesProvider(): array
    {
        return [
            ['Юрий', 'Никогосян', null],
            ['Анна-Мария', 'Дюпон', null],
            ['John', "O'Connor", null],
            ['Sean', 'O’Connor', null],
            ['Jean-Pierre', 'François', null],
            ['María', 'José', null],
            ['Łukasz', 'Kowalski', null],
            ['Nguyễn', 'Văn', 'Nam'],
        ];
    }

    /**
     * @dataProvider invalidNamesProvider
     */
    public function testRejectsInvalidPersonNames(string $invalidName): void
    {
        $this->expectException(InputValidationException::class);
        PersonInput::createPerson([
            'givenName' => $invalidName,
            'familyName' => 'Valid',
            'birthDate' => '2000-01-01',
        ]);
    }

    public static function invalidNamesProvider(): array
    {
        return [
            ['Юрий123'],
            ['123'],
            ['@@@'],
            ['Иван!!!'],
            ['<script>'],
            ["\0"],
            ["Control\r\nName"],
            ['   '],
            ['---'],
            ["'''"],
            [str_repeat('A', 121)], // excessive length
        ];
    }

    public function testRejectsFutureBirthDate(): void
    {
        $this->expectException(InputValidationException::class);
        PersonInput::createPerson([
            'givenName' => 'Future',
            'familyName' => 'Person',
            'birthDate' => '2099-01-01',
        ]);
    }

    public function testRejectsInvalidCalendarDate(): void
    {
        $this->expectException(InputValidationException::class);
        PersonInput::createPerson([
            'givenName' => 'Invalid',
            'familyName' => 'Date',
            'birthDate' => '2024-02-31',
        ]);
    }

    public function testPatchPreservesProvidedNull(): void
    {
        $command = PersonInput::updatePerson(
            '018f2892-aaaa-7aaa-8aaa-aaaaaaaaaaaa',
            ['middleName' => null],
        );

        assertTrue($command->middleNameProvided);
        assertNull($command->middleName);
    }

    public function testValidEmailContact(): void
    {
        $command = PersonInput::createContact('018f2892-aaaa-7aaa-8aaa-aaaaaaaaaaaa', [
            'contactType' => 'email',
            'value' => 'user@example.com',
            'label' => 'work',
            'isPrimary' => true,
        ]);

        assertSame('email', $command->contactType);
        assertSame('user@example.com', $command->value);
        assertSame('work', $command->label);
        assertTrue($command->isPrimary);
    }

    public function testValidPhoneContact(): void
    {
        $command = PersonInput::createContact('018f2892-aaaa-7aaa-8aaa-aaaaaaaaaaaa', [
            'contactType' => 'phone',
            'value' => '+7 (999) 123-45-67',
            'label' => 'mobile',
            'isPrimary' => false,
        ]);

        assertSame('phone', $command->contactType);
        assertSame('+7 (999) 123-45-67', $command->value);
    }

    /**
     * @dataProvider invalidContactsProvider
     */
    public function testRejectsInvalidContacts(string $type, string $value): void
    {
        $this->expectException(InputValidationException::class);
        PersonInput::createContact('018f2892-aaaa-7aaa-8aaa-aaaaaaaaaaaa', [
            'contactType' => $type,
            'value' => $value,
        ]);
    }

    public static function invalidContactsProvider(): array
    {
        return [
            ['phone', '89991234567'],         // missing +
            ['phone', '+7 (999) 123-45-6a'],  // letters
            ['phone', '+12345'],              // too short
            ['phone', '+123456789012345678'], // too long
            ['email', 'not-an-email'],
            ['email', 'user@@example.com'],
            ['email', 'user@'],
        ];
    }

    /** @dataProvider invalidContactLabelsProvider */
    public function testRejectsInvalidContactLabels(string $label): void
    {
        $this->expectException(InputValidationException::class);
        PersonInput::createContact('018f2892-aaaa-7aaa-8aaa-aaaaaaaaaaaa', [
            'contactType' => 'email',
            'value' => 'user@example.com',
            'label' => $label,
        ]);
    }

    public static function invalidContactLabelsProvider(): array
    {
        return [
            ['   '],
            ["work\0label"],
            ["work\nlabel"],
            [str_repeat('a', 81)],
        ];
    }
}
