<?php

declare(strict_types=1);

namespace App\Core\Organization\Application;

interface OrganizationRepository
{
    /** @return list<Organization> */
    public function listFor(?string $personId): array;
    public function create(string $code, string $name): ?Organization;
    public function activeOrganizationExists(string $id): bool;
    public function activePerson(string $personId): bool;
    public function activeMember(string $personId, string $organizationId): bool;
    /** @return list<OrganizationMembership> */
    public function members(string $organizationId): array;
    public function addMember(string $organizationId, string $personId): ?OrganizationMembership;
    public function leave(string $organizationId, string $personId): ?OrganizationMembership;
}
