<?php

declare(strict_types=1);

namespace App\PlatformRuntime;

interface DeploymentEntitlementPuller
{
    /** @return array<string,mixed> */
    public function pull(string $controlPlaneUrl, string $authorization): array;
}
