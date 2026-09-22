<?php

declare(strict_types=1);

namespace App\Core\Account\Application;

final readonly class AccountLifecycleChange
{
    public function __construct(
        public string $accountId,
        public string $beforeStatus,
        public string $afterStatus,
        public int $revokedSessions,
    ) {}
}
