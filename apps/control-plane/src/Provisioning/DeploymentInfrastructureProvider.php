<?php

declare(strict_types=1);

namespace ControlPlane\Provisioning;

interface DeploymentInfrastructureProvider
{
    public function provision(ProvisioningRequest $request): ProvisioningResult;
    /** @return array{runtime:bool,database:bool,redis:bool,scheduler:bool,emailWorker:bool,network:bool} */
    public function status(string $deploymentId): array;
    public function activate(ProvisioningRequest $request): void;
    public function applyTargetMigrations(ProvisioningRequest $request): void;
    public function replaceApplicationRuntime(ProvisioningRequest $request): void;
    /** Verifies the new runtime after entitlement synchronization. */
    public function verifyUpdate(ProvisioningRequest $request, \DateTimeImmutable $startedAt): void;
    public function quiesce(string $deploymentId): void;
    public function decommission(string $deploymentId): void;
    public function cleanup(string $deploymentId): void;
}
