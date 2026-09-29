<?php

declare(strict_types=1);

namespace App\Core\Organization\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;
use DomainException;

final readonly class OrganizationContext
{
    public function __construct(private OrganizationRepository $organizations) {}

    public function resolve(AuthenticatedIdentity $identity, string $organizationId): string
    {
        if (!$this->organizations->activeOrganizationExists($organizationId)) {
            throw new DomainException('Organization is unavailable.');
        }
        if ($identity->personId === null || !$this->organizations->activePerson($identity->personId)) {
            throw new DomainException('Organization scope denied.');
        }
        if ($identity->hasRole('core.superadmin')) {
            return $organizationId;
        }
        if (!$this->organizations->activeMember($identity->personId, $organizationId)) {
            throw new DomainException('Organization scope denied.');
        }
        return $organizationId;
    }
}
