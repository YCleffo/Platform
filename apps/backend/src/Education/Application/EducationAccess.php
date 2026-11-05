<?php

declare(strict_types=1);

namespace App\Education\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Module\Application\ModuleRepository;
use App\Core\Organization\Application\OrganizationRepository;
use App\Core\Person\Application\GetPerson;
use App\Core\Privacy\Application\PrivacyResourceUsePolicy;
use App\Core\Rbac\Application\PermissionService;
use DateTimeZone;

final readonly class EducationAccess
{
    public function __construct(private EducationStore $store, private ModuleRepository $modules,
        private OrganizationRepository $organizations, private GetPerson $persons,
        private PermissionService $permissions, private PrivacyResourceUsePolicy $privacy) {}

    public function assertAvailable(AuthenticatedIdentity $identity): string
    {
        if ($this->modules->findByCode('education')?->enabled !== true) throw new EducationError(404, 'module_unavailable');
        $personId = $identity->personId;
        if ($personId === null || !$this->organizations->activePerson($personId)
            || !$this->permissions->hasPermission($identity->accountId, 'education.access')) {
            throw new EducationError(403, 'access_denied');
        }
        if (!$this->privacy->allowed($personId, 'education.person', $personId, 'education')) {
            throw new EducationError(403, 'processing_restricted');
        }
        return $personId;
    }

    /** @return array<string,mixed> */
    public function context(AuthenticatedIdentity $identity): array
    {
        $personId = $this->assertAvailable($identity);
        $assigned = $this->store->teacherOrganizations($identity->accountId, $personId);
        $organizations = [];
        foreach ($this->organizations->listFor($personId) as $organization) {
            if (in_array($organization->id, $assigned, true) && $this->organizations->activeMember($personId, $organization->id)) {
                $organizations[] = ['id' => $organization->id, 'name' => $organization->name];
            }
        }
        return ['organizations' => $organizations, 'personId' => $personId,
            'displayName' => $this->personName($personId), 'locale' => $identity->locale ?? 'ru-RU',
            'timezone' => $identity->timezone ?? 'UTC'];
    }

    public function scope(AuthenticatedIdentity $identity, string $organizationId, string $permission): Scope
    {
        $personId = $this->assertAvailable($identity);
        Input::uuid($organizationId);
        if (!$this->permissions->hasPermission($identity->accountId, $permission)
            || !$this->organizations->activeMember($personId, $organizationId)) throw new EducationError(403, 'access_denied');
        $timezone = $identity->timezone ?? 'UTC';
        if ($timezone === '') throw new EducationError(422, 'invalid_timezone');
        try { new DateTimeZone($timezone); }
        catch (\Exception) { throw new EducationError(422, 'invalid_timezone'); }
        $scope = new Scope($organizationId, $identity->accountId, $personId, $identity->locale ?? 'ru-RU', $timezone);
        if (!$this->store->teacherExists($scope)) throw new EducationError(403, 'access_denied');
        return $scope;
    }

    public function personName(string $personId): ?string
    {
        if (!$this->privacy->allowed($personId, 'education.person', $personId, 'education')) return null;
        $person = $this->persons->execute($personId);
        if ($person === null || $person->status !== 'active') return null;
        return $person->displayName ?: trim($person->givenName . ' ' . $person->familyName);
    }

    public function member(string $personId, string $organizationId): bool
    {
        return $this->organizations->activeMember($personId, $organizationId)
            && $this->privacy->allowed($personId, 'education.person', $personId, 'education');
    }

    public function resourceAllowed(string $personId, string $type, string $id): bool
    {
        return $this->privacy->allowed($personId, 'education.' . $type, $id, 'education');
    }

    public function assertResource(Scope $scope, string $type, string $id): void
    {
        if (!$this->resourceAllowed($scope->personId, $type, $id)) throw new EducationError(403, 'processing_restricted');
    }

    /** @return list<array{id:string,name:string}> */
    public function candidates(Scope $scope): array
    {
        $items = [];
        foreach ($this->organizations->members($scope->organizationId) as $membership) {
            if ($membership->status !== 'active' || !$this->member($membership->personId, $scope->organizationId)) continue;
            $name = $this->personName($membership->personId);
            if ($name !== null) $items[] = ['id' => $membership->personId, 'name' => $name];
        }
        return $items;
    }
}
