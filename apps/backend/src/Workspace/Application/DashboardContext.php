<?php

declare(strict_types=1);

namespace App\Workspace\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;

final readonly class DashboardContext
{
    /**
     * @param list<string> $permissions
     * @param array<string, bool> $enabledModules
     * @param array{teacher: bool, student: bool, parent: bool, admin: bool} $educationRoles
     */
    public function __construct(
        public AuthenticatedIdentity $identity,
        public string $organizationId,
        public string $accountId,
        public string $personId,
        public array $permissions,
        public bool $isSuperadmin,
        public bool $isOrgAdmin,
        public array $enabledModules,
        public array $educationRoles,
        public string $locale,
        public ?string $studentId = null,
    ) {}

    public function hasPermission(string $code): bool
    {
        return $this->isSuperadmin || in_array($code, $this->permissions, true);
    }

    public function isModuleEnabled(string $code): bool
    {
        return ($this->enabledModules[$code] ?? false) === true;
    }
}
