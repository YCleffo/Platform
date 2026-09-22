<?php

declare(strict_types=1);

namespace App\Core\Rbac\Application;

final readonly class ListPermissions
{
    public function __construct(private PermissionRepository $repository) {}

    public function execute(int $limit, int $offset): PermissionPage
    {
        return $this->repository->findPage($limit, $offset);
    }
}
