<?php

declare(strict_types=1);

namespace App\Core\Rbac\Application;

interface PermissionRepository
{
    public function findPage(int $limit, int $offset): PermissionPage;

    /** @return list<PermissionView> */
    public function findByAccountId(string $accountId): array;

    /** @return list<PermissionView> */
    public function findAll(): array;

    public function accountHasPermission(string $accountId, string $permissionCode): bool;
}
