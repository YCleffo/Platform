<?php

declare(strict_types=1);

namespace App\Core\Account\Application;

interface AccountRepository
{
    public function findPage(int $limit, int $offset): AccountPage;

    public function findById(string $id): ?AccountView;

    public function create(CreateAccountCommand $command): AccountView;
}
