<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\AccountSettings;

use App\Core\Consent\Application\ConsentDefinition;
use Codeception\Test\Unit;
use InvalidArgumentException;

final class ConsentDocumentTest extends Unit
{
    public function testDocumentTextMustMatchTheRegisteredVersionHash(): void
    {
        $text = "Editable synthetic document\nSecond line";
        $definition = new ConsentDefinition('example', 'v1', hash('sha256', $text), true, 'example', 'Example document', $text);
        self::assertSame(['title' => 'Example document', 'text' => $text], $definition->toArray(false)['document']);
        self::assertNull((new ConsentDefinition('example', 'v1', str_repeat('a', 64), true, 'example'))->toArray(false)['document']);
        $this->expectException(InvalidArgumentException::class);
        new ConsentDefinition('example', 'v1', str_repeat('a', 64), true, 'example', 'Changed document', $text);
    }
}
