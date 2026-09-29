<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

use DomainException;

final readonly class ServiceScopePolicy
{
    public function requireScope(ServiceIdentity $identity, string $scope): void
    {
        if (!in_array($scope, $identity->scopes, true)) {
            throw new DomainException('Machine scope denied.');
        }
    }
}
