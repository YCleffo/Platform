<?php

declare(strict_types=1);

namespace App\PlatformRuntime;

interface DeploymentStatusReporter
{
    /** Returns ready or already_ready. */
    public function ready(int $entitlementSequence): string;

    public function failed(string $machineErrorCode): void;
}
