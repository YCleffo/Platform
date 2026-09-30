<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

interface AuthCleanupRepository
{
    public function cleanup(AuthCleanupPolicy $policy): AuthCleanupReport;
    /** Erases operational credentials for one verified human account inside the caller transaction. */
    public function eraseHumanIdentity(string $accountId): void;
}
