<?php

declare(strict_types=1);

namespace App\Core\Account\Application;

final readonly class GetAccount
{
    public function __construct(
        private AccountRepository $repository,
    ) {}

    public function execute(string $id): ?AccountView
    {
        return $this->repository->findById($id);
    }
}
