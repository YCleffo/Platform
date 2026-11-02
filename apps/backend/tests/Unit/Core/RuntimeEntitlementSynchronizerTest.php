<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use App\PlatformRuntime\DeploymentEntitlementPuller;
use App\PlatformRuntime\EntitlementApplier;
use App\PlatformRuntime\InstallationRepository;
use App\PlatformRuntime\RuntimeEntitlementSynchronizer;
use App\PlatformRuntime\RuntimeSecretResolver;
use PHPUnit\Framework\TestCase;

final class RuntimeEntitlementSynchronizerTest extends TestCase
{
    public function testConfigurationRequiresControlPlaneUrlAndCredentialReference(): void
    {
        $installations = $this->createMock(InstallationRepository::class);
        $applier = $this->createMock(EntitlementApplier::class);
        $puller = $this->createMock(DeploymentEntitlementPuller::class);
        $make = static fn(string $url, string $reference): RuntimeEntitlementSynchronizer => new RuntimeEntitlementSynchronizer(
            $installations,
            $applier,
            $puller,
            $url,
            $reference,
            new RuntimeSecretResolver(),
        );

        self::assertFalse($make('', 'env:CONTROL_PLANE_DEPLOYMENT_CREDENTIAL')->configured());
        self::assertFalse($make('https://control.example.test', '')->configured());
        self::assertTrue($make('https://control.example.test', 'env:CONTROL_PLANE_DEPLOYMENT_CREDENTIAL')->configured());
    }
}
