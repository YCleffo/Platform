<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core\Auth;

use App\Core\Auth\Application\AuthIdentifierNormalizer;
use App\Core\Auth\Infrastructure\NativePasswordHasher;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PasswordAndIdentifierTest extends TestCase
{
    public function testUsesNativeArgon2idAndVerifiesPassword(): void
    {
        $hasher = new NativePasswordHasher();
        $hash = $hasher->hash('Strong Password 123');

        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue($hasher->verify('Strong Password 123', $hash));
        self::assertFalse($hasher->verify('wrong', $hash));
        self::assertFalse($hasher->needsRehash($hash));
    }

    public function testNormalizesIdentifiers(): void
    {
        $normalizer = new AuthIdentifierNormalizer();
        self::assertSame(
            'user@xn--e1afmkfd.xn--p1ai',
            $normalizer->normalize('email', ' User@ПРИМЕР.РФ '),
        );
        self::assertSame('alice', $normalizer->normalize('username', ' Ａlice '));
        self::assertSame('+79991234567', $normalizer->normalize('phone', '+79991234567'));
    }

    public function testRejectsAmbiguousPhone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AuthIdentifierNormalizer())->normalize('phone', '8 (999) 123-45-67');
    }
}
