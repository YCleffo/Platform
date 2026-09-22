<?php

declare(strict_types=1);

namespace App\Core\Rbac\Application;

final readonly class PermissionService
{
    private const string SUPERADMIN_ROLE = 'core.superadmin';

    public function __construct(
        private RoleRepository $roles,
        private PermissionRepository $permissions,
    ) {}

    /** @return list<RoleView> */
    public function getRoles(string $accountId): array
    {
        return $this->roles->findByAccountId($accountId);
    }

    /** @return list<PermissionView> */
    public function getPermissions(string $accountId): array
    {
        return $this->isSuperadmin($accountId)
            ? $this->permissions->findAll()
            : $this->permissions->findByAccountId($accountId);
    }

    public function hasPermission(string $accountId, string $permissionCode): bool
    {
        return $this->isSuperadmin($accountId)
            || $this->permissions->accountHasPermission($accountId, $permissionCode);
    }

    private function isSuperadmin(string $accountId): bool
    {
        return $this->roles->accountHasRoleCode($accountId, self::SUPERADMIN_ROLE);
    }
}
