<?php

declare(strict_types=1);

namespace ControlPlane\Provisioning;

interface ProvisioningSecretStore
{
    /** @return array{reference:string,databasePath:string,credentialPath:string} */
    public function prepare(string $deploymentId, string $credential): array;
    public function cleanup(string $deploymentId): void;
}
