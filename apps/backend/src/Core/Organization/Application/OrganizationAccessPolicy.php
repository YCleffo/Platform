<?php

declare(strict_types=1);

namespace App\Core\Organization\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Organization\Infrastructure\PostgresOrganizationRepository;
use App\Core\Rbac\Application\PermissionService;
use DomainException;

final readonly class OrganizationAccessPolicy
{
    public function __construct(
        private OrganizationContext $context,
        private PostgresOrganizationRepository $organizations,
        private PermissionService $permissions,
    ) {}

    public function assertMember(AuthenticatedIdentity $identity, string $organizationId): void
    {
        $this->context->resolve($identity, $organizationId);
    }

    public function assertPersonBelongsToOrganization(string $personId, string $organizationId): void
    {
        if (!$this->organizations->activeMember($personId, $organizationId)) {
            throw new DomainException('Target person is outside organization scope.');
        }
    }

    public function canAccess(AuthenticatedIdentity $identity, string $organizationId, string $permission): bool
    {
        if (!$this->permissions->hasPermission($identity->accountId, $permission)) {
            return false;
        }
        try {
            $this->assertMember($identity, $organizationId);
            return true;
        } catch (DomainException) {
            return false;
        }
    }
}
