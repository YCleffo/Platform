<?php

declare(strict_types=1);

namespace App\Core\Rbac\Application;

final readonly class ListRoles
{
    public function __construct(private RoleRepository $repository) {}

    public function execute(int $limit, int $offset): RolePage
    {
        return $this->repository->findPage($limit, $offset);
    }
}
