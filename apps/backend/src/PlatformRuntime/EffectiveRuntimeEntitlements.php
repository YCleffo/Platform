<?php

declare(strict_types=1);

namespace App\PlatformRuntime;

interface EffectiveRuntimeEntitlements
{
    /** @return array<string, mixed> */
    public function forInstallation(string $installationId): array;
}
