<?php

declare(strict_types=1);

namespace App\DeviceManagement\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Module\Application\ModuleRepository;
use App\Core\Organization\Application\OrganizationRepository;
use App\Core\Rbac\Application\PermissionService;
use App\Core\Privacy\Application\PrivacyResourceUsePolicy;
use DateTimeZone;

final readonly class DeviceAccess
{
    public function __construct(
        private ModuleRepository $modules,
        private OrganizationRepository $organizations,
        private PermissionService $permissions,
        private PrivacyResourceUsePolicy $privacy,
    ) {}

    public function assertAvailable(AuthenticatedIdentity $identity): string
    {
        if ($this->modules->findByCode('device_management')?->enabled !== true) {
            throw new DeviceError(404, 'module_unavailable');
        }
        $personId = $identity->personId;
        if ($personId === null || !$this->organizations->activePerson($personId)
            || !$this->permissions->hasPermission($identity->accountId, 'education.devices.manage')) {
            throw new DeviceError(403, 'access_denied');
        }
        return $personId;
    }

    public function scope(AuthenticatedIdentity $identity, string $organizationId): Scope
    {
        $personId = $this->assertAvailable($identity);
        if (!preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $organizationId) === 1
            || !$this->organizations->activeMember($personId, $organizationId)) {
            throw new DeviceError(403, 'access_denied');
        }
        $timezone = $identity->timezone ?? 'UTC';
        try {
            new DateTimeZone($timezone);
        } catch (\Exception) {
            throw new DeviceError(422, 'invalid_timezone');
        }
        return new Scope($organizationId, $identity->accountId, $personId, $identity->locale ?? 'ru-RU', $timezone);
    }

    public function studentAllowed(string $personId, string $type, string $id, string $category): bool
    {
        return $this->privacy->allowed($personId, $type, $id, $category);
    }
}
