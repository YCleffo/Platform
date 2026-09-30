<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Unit;

use ControlPlane\M2m\DeploymentCredentialLifetime;
use PHPUnit\Framework\TestCase;

final class DeploymentCredentialLifetimeTest extends TestCase
{
    public function testConfiguredExpiryIsFinite(): void
    {
        $expiry = new \DateTimeImmutable((new DeploymentCredentialLifetime('30'))->expiresAt());
        self::assertGreaterThan(new \DateTimeImmutable('+29 days'), $expiry);
        self::assertLessThan(new \DateTimeImmutable('+31 days'), $expiry);
    }

    public function testMissingOrInvalidConfigurationFailsClosed(): void
    {
        foreach (['', '0', '-1', '366', '30days'] as $value) {
            try { new DeploymentCredentialLifetime($value); self::fail('Invalid TTL accepted.'); }
            catch (\DomainException) { self::assertTrue(true); }
        }
    }
}
