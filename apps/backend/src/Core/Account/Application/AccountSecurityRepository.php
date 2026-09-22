<?php

declare(strict_types=1);

namespace App\Core\Account\Application;

interface AccountSecurityRepository
{
    public function findSecurityState(string $accountId): ?AccountSecurityState;

    public function recordSuccessfulLogin(string $accountId): bool;

    public function rotateSecurityStamp(string $accountId): ?string;
}
