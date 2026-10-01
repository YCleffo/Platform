<?php

declare(strict_types=1);

namespace ControlPlane\Provisioning;

interface DeploymentInfrastructureProvider
{
    public function provision(ProvisioningRequest $request): ProvisioningResult;
    /** @return array{runtime:bool,database:bool,network:bool} */
    public function status(string $deploymentId): array;
    public function activate(ProvisioningRequest $request): void;
    public function quiesce(string $deploymentId): void;
    public function decommission(string $deploymentId): void;
    public function cleanup(string $deploymentId): void;
}
