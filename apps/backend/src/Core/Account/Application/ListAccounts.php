<?php

declare(strict_types=1);

namespace App\Core\Account\Application;

final readonly class ListAccounts
{
    public function __construct(
        private AccountRepository $repository,
    ) {}

    public function execute(int $limit, int $offset): AccountPage
    {
        return $this->repository->findPage($limit, $offset);
    }
}
