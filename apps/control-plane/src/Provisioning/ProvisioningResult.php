<?php

declare(strict_types=1);

namespace ControlPlane\Provisioning;

final readonly class ProvisioningResult
{
    public function __construct(public string $runtimeContainer, public string $databaseContainer,
        public string $network, public bool $reused) {}
}
