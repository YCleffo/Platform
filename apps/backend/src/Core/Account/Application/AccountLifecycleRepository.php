<?php

declare(strict_types=1);

namespace App\Core\Account\Application;

interface AccountLifecycleRepository
{
    public function approve(string $accountId, string $actorAccountId): ?AccountLifecycleChange;

    public function disable(string $accountId): ?AccountLifecycleChange;

    public function enable(string $accountId): ?AccountLifecycleChange;

    public function archive(string $accountId): ?AccountLifecycleChange;
}
