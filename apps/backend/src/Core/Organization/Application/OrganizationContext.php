<?php

declare(strict_types=1);

namespace App\Core\Organization\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Organization\Infrastructure\PostgresOrganizationRepository;
use DomainException;

final readonly class OrganizationContext
{
    public function __construct(private PostgresOrganizationRepository $organizations) {}

    public function resolve(AuthenticatedIdentity $identity, string $organizationId): string
    {
        if (!$this->organizations->activeOrganizationExists($organizationId)) {
            throw new DomainException('Organization is unavailable.');
        }
        if ($identity->hasRole('core.superadmin')) {
            return $organizationId;
        }
        if ($identity->personId === null || !$this->organizations->activeMember($identity->personId, $organizationId)) {
            throw new DomainException('Organization scope denied.');
        }
        return $organizationId;
    }
}
