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
    public function __construct(
        private EducationStore $store,
        private ModuleRepository $modules,
        private OrganizationRepository $organizations,
        private GetPerson $persons,
        private PermissionService $permissions,
        private PrivacyResourceUsePolicy $privacy,
        private ?\Yiisoft\Db\Connection\ConnectionInterface $familyDb = null,
    ) {}

    public function assertAvailable(AuthenticatedIdentity $identity): string
    {
        if ($this->modules->findByCode('education')?->enabled !== true) {
            throw new EducationError(404, 'module_unavailable');
        }
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
        $studentOrgs = $this->store->studentOrganizations($identity->accountId);
        $parentOrgs = [];
        if ($this->familyDb !== null && $this->permissions->hasPermission($identity->accountId, 'education.parent.use')) {
            $parentOrgs = $this->familyDb->createCommand('SELECT DISTINCT pa.organization_id::text FROM education.parent_accounts pa
                JOIN education.student_guardians sg ON sg.organization_id=pa.organization_id AND sg.guardian_person_id=pa.guardian_person_id
                WHERE pa.account_id=CAST(:account AS uuid) AND pa.guardian_person_id=CAST(:person AS uuid)
                AND pa.revoked_at IS NULL AND sg.status=\'confirmed\'', [':account' => $identity->accountId,':person' => $personId])->queryColumn();
        }
        $organizations = [];
        foreach ($this->organizations->listFor($personId) as $organization) {
            if ($this->organizations->activeMember($personId, $organization->id)) {
                $organizations[] = ['id' => $organization->id, 'name' => $organization->name,
                    'teacher' => in_array($organization->id, $assigned, true),
                    'student' => in_array($organization->id, $studentOrgs, true),
                    'parent' => in_array($organization->id, $parentOrgs, true)];
            }
        }
        $organizations = array_values(array_filter(
            $organizations,
            static fn(array $row): bool => $row['teacher'] || $row['student'] || $row['parent'],
        ));
        return ['organizations' => $organizations, 'personId' => $personId,
            'displayName' => $this->personName($personId), 'locale' => $identity->locale ?? 'ru-RU',
            'timezone' => $identity->timezone ?? 'UTC'];
    }

    public function scope(AuthenticatedIdentity $identity, string $organizationId, string $permission): Scope
    {
        $personId = $this->assertAvailable($identity);
        Input::uuid($organizationId);
        if (!$this->permissions->hasPermission($identity->accountId, $permission)
            || !$this->organizations->activeMember($personId, $organizationId)) {
            throw new EducationError(403, 'access_denied');
        }
        $timezone = $this->assertTimezone($identity);
        $scope = new Scope($organizationId, $identity->accountId, $personId, $identity->locale ?? 'ru-RU', $timezone);
        if (!$this->store->teacherExists($scope)) {
            throw new EducationError(403, 'access_denied');
        }
        return $scope;
    }

    public function studentScope(AuthenticatedIdentity $identity, string $organizationId, string $permission): Scope
    {
        $personId = $this->assertAvailable($identity);
        Input::uuid($organizationId);
        if (!$this->permissions->hasPermission($identity->accountId, $permission)
            || !$this->organizations->activeMember($personId, $organizationId)) {
            throw new EducationError(403, 'access_denied');
        }
        $student = $this->store->studentByAccount($identity->accountId, $organizationId, $personId);
        if ($student === null) {
            throw new EducationError(403, 'access_denied');
        }
        return new Scope(
            $organizationId,
            $identity->accountId,
            $personId,
            $identity->locale ?? 'ru-RU',
            $this->assertTimezone($identity),
            $student['id'],
        );
    }

    public function parentScope(AuthenticatedIdentity $identity, string $organizationId, string $studentId): ParentScope
    {
        $person = $this->assertAvailable($identity);
        Input::uuid($organizationId);
        Input::uuid($studentId);
        if ($this->familyDb === null || !$this->permissions->hasPermission($identity->accountId, 'education.parent.use')
            || !$this->member($person, $organizationId)) {
            throw new EducationError(403, 'access_denied');
        }
        $row = $this->familyDb->createCommand(
            "SELECT s.person_id::text FROM education.student_guardians sg
            JOIN education.students s ON s.id=sg.student_id AND s.organization_id=sg.organization_id
            JOIN education.parent_accounts pa ON pa.organization_id=sg.organization_id AND pa.guardian_person_id=sg.guardian_person_id
            JOIN core.accounts a ON a.id=pa.account_id AND a.person_id=sg.guardian_person_id
            WHERE sg.student_id=CAST(:student AS uuid) AND sg.organization_id=CAST(:org AS uuid)
            AND sg.guardian_person_id=CAST(:person AS uuid) AND sg.status='confirmed' AND pa.revoked_at IS NULL
            AND pa.account_id=CAST(:account AS uuid) AND a.status='active' AND a.archived_at IS NULL AND a.disabled_at IS NULL",
            [':student' => $studentId,':org' => $organizationId,':person' => $person,':account' => $identity->accountId],
        )->queryOne();
        if (!is_array($row) || !$this->member($row['person_id'], $organizationId)
            || !$this->resourceAllowed($person, 'family', $studentId)
            || !$this->resourceAllowed($row['person_id'], 'student', $studentId)) {
            throw new EducationError(404, 'not_found');
        }
        return new ParentScope(
            $organizationId,
            $identity->accountId,
            $person,
            $studentId,
            $row['person_id'],
            $identity->locale ?? 'ru-RU',
            $this->assertTimezone($identity),
        );
    }

    private function assertTimezone(AuthenticatedIdentity $identity): string
    {
        $timezone = $identity->timezone ?? 'UTC';
        if ($timezone === '') {
            throw new EducationError(422, 'invalid_timezone');
        }
        try {
            new DateTimeZone($timezone);
        } catch (\Exception) {
            throw new EducationError(422, 'invalid_timezone');
        }
        return $timezone;
    }

    public function personName(string $personId): ?string
    {
        if (!$this->privacy->allowed($personId, 'education.person', $personId, 'education')) {
            return null;
        }
        $person = $this->persons->execute($personId);
        if ($person === null || $person->status !== 'active') {
            return null;
        }
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
        if (!$this->resourceAllowed($scope->personId, $type, $id)) {
            throw new EducationError(403, 'processing_restricted');
        }
    }

    /** @return list<array{id:string,name:string}> */
    public function candidates(Scope $scope): array
    {
        $items = [];
        foreach ($this->organizations->members($scope->organizationId) as $membership) {
            if ($membership->status !== 'active' || !$this->member($membership->personId, $scope->organizationId)) {
                continue;
            }
            $name = $this->personName($membership->personId);
            if ($name !== null) {
                $items[] = ['id' => $membership->personId, 'name' => $name];
            }
        }
        return $items;
    }
}
