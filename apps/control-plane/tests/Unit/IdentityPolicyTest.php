<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use ControlPlane\Identity\Domain\EmailPolicy;
use ControlPlane\Identity\Domain\PasswordPolicy;
use ControlPlane\Identity\Security\TokenCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class IdentityPolicyTest extends TestCase
{
    public function testEmailPreservesTrimmedPresentationAndNormalizesLookup(): void
    {
        self::assertSame(['email' => 'User@Example.com', 'normalized' => 'user@example.com'], EmailPolicy::normalize(' User@Example.com '));
    }

    #[DataProvider('invalidEmails')]
    public function testInvalidEmailIsRejected(string $email): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EmailPolicy::normalize($email);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidEmails(): iterable
    {
        yield 'empty' => [''];
        yield 'control' => ["a\0b@example.com"];
        yield 'syntax' => ['not-an-email'];
        yield 'oversized' => [str_repeat('a', 310) . '@example.com'];
    }

    public function testPasswordUsesLengthInsteadOfCompositionRules(): void
    {
        PasswordPolicy::validate('correct horse battery staple');
        self::addToAssertionCount(1);
    }

    #[DataProvider('invalidPasswords')]
    public function testUnsafePasswordIsRejected(string $password): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PasswordPolicy::validate($password);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPasswords(): iterable
    {
        yield 'short' => ['too-short'];
        yield 'control' => ["long-enough\npassword"];
        yield 'oversized' => [str_repeat('x', PasswordPolicy::MAX_BYTES + 1)];
    }

    public function testVerificationTokenIsDeterministicAuthenticatedAndHashable(): void
    {
        $codec = new TokenCodec(str_repeat('s', 32));
        $id = Uuid::v7()->toRfc4122();
        $token = $codec->verificationToken($id);
        $codec->validateVerificationToken($token);
        self::assertSame(64, strlen($codec->hash($token)));
        self::assertNotSame($token, $codec->hash($token));
    }

    public function testTamperedVerificationTokenIsRejected(): void
    {
        $codec = new TokenCodec(str_repeat('s', 32));
        $token = $codec->verificationToken(Uuid::v7()->toRfc4122());
        $this->expectException(\DomainException::class);
        $codec->validateVerificationToken(substr($token, 0, -1) . 'x');
    }
}
