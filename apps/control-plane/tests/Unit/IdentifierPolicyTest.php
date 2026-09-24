<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use ControlPlane\Deployment\Domain\DomainName;
use ControlPlane\Shared\Domain\IdentifierPolicy;
use ControlPlane\Shared\Domain\TextPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdentifierPolicyTest extends TestCase
{
    public function testCanonicalMachineCode(): void
    {
        self::assertSame('students.max', IdentifierPolicy::machineCode('students.max'));
        self::assertSame('custom_domain', IdentifierPolicy::machineCode('custom_domain'));
    }

    #[DataProvider('invalidCodes')]
    public function testInvalidMachineCode(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IdentifierPolicy::machineCode($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCodes(): iterable
    {
        foreach (['Core.Admin', 'core..admin', 'core--', 'core.', '.core', 'café', ''] as $value) {
            yield $value => [$value];
        }
    }

    public function testSlugAndDomain(): void
    {
        self::assertSame('school-1', IdentifierPolicy::slug('school-1'));
        self::assertSame('xn--e1afmkfd.xn--p1ai', DomainName::normalize('ПРИМЕР.РФ.'));
    }

    public function testOpaqueKey(): void
    {
        self::assertSame('request_123-ABC:retry', IdentifierPolicy::opaqueKey('request_123-ABC:retry'));
    }

    #[DataProvider('invalidOpaqueKeys')]
    public function testInvalidOpaqueKey(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IdentifierPolicy::opaqueKey($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOpaqueKeys(): iterable
    {
        foreach (['empty' => '', 'whitespace' => '   ', 'control' => "key\nvalue", 'space' => 'key value', 'long' => str_repeat('x', 201)] as $name => $value) {
            yield $name => [$value];
        }
    }

    #[DataProvider('invalidDomains')]
    public function testInvalidDomain(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DomainName::normalize($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDomains(): iterable
    {
        foreach (['https://example.com', 'example.com/path', 'example.com:443', '127.0.0.1', 'localhost', 'foo.internal', 'foo..example.com'] as $value) {
            yield $value => [$value];
        }
    }

    public function testTextNormalization(): void
    {
        self::assertSame('Café', TextPolicy::label(" Cafe\u{0301} ", 20));
    }

    public function testControlCharacterRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TextPolicy::label("Name\x00", 20);
    }

    public function testUnsafeOrExcessiveTextIsRejectedWithoutHtmlEscaping(): void
    {
        self::assertSame('A & B <C>', TextPolicy::label(' A & B <C> ', 20));
    }

    #[DataProvider('invalidLabels')]
    public function testUnsafeOrExcessiveTextIsRejected(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TextPolicy::label($value, 20);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLabels(): iterable
    {
        foreach (['whitespace' => '   ', 'long' => str_repeat('x', 21), 'control' => "Name\x7F"] as $name => $value) {
            yield $name => [$value];
        }
    }
}
