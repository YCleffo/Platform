<?php

declare(strict_types=1);

namespace App\Core\Rbac\Application;

interface RoleRepository
{
    public function findPage(int $limit, int $offset): RolePage;

    public function findById(string $id): ?RoleView;

    /** @return list<RoleView> */
    public function findByAccountId(string $accountId): array;

    public function accountHasRoleCode(string $accountId, string $roleCode): bool;

    public function assign(string $accountId, string $roleId, string $assignedByAccountId): bool;

    public function revoke(string $accountId, string $roleId): bool;
}
