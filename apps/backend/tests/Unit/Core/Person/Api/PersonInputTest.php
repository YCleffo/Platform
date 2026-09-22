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
    public function testBuildsStrictCreateCommand(): void
    {
        $command = PersonInput::createPerson([
            'givenName' => 'Юрий',
            'familyName' => 'Никогосян',
            'middleName' => null,
            'displayName' => 'Юрий Никогосян',
            'birthDate' => '2004-11-18',
        ]);

        assertSame('Юрий', $command->givenName);
        assertSame('2004-11-18', $command->birthDate);
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

    public function testRejectsDatabaseInvalidPerson(): void
    {
        $this->expectException(InputValidationException::class);
        PersonInput::createPerson([
            'givenName' => ' ',
            'familyName' => 'Person',
            'birthDate' => '2024-02-31',
        ]);
    }
}
