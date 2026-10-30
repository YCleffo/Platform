<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Identity\Application\CurrentUser;

final class CurrentUserTest extends DatabaseTestCase
{
    public function testReturnsNonSecretProfileFields(): void
    {
        $user = $this->user('profile@example.test');

        $profile = (new CurrentUser($this->connection))->profile($user);

        self::assertSame($user, $profile['id']);
        self::assertSame('profile@example.test', $profile['email']);
        self::assertSame('active', $profile['status']);
        self::assertNotNull($profile['email_verified_at']);
        self::assertArrayNotHasKey('password_hash', $profile);
        self::assertArrayNotHasKey('email_normalized', $profile);
        self::assertArrayNotHasKey('password_changed_at', $profile);
    }

    public function testUnknownUserIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        (new CurrentUser($this->connection))->profile($this->uuid());
    }
}
