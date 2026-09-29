<?php

declare(strict_types=1);

namespace ControlPlane\M2m;

use DomainException;

final readonly class DeploymentScopePolicy
{
    public function requireScope(DeploymentIdentity $identity, string $scope): void
    {
        if (!in_array($scope, $identity->scopes, true)) {
            throw new DomainException('Deployment scope denied.');
        }
    }
}
