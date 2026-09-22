<?php

declare(strict_types=1);

namespace App\Core\Auth\Application;

interface AuthCleanupRepository
{
    public function cleanup(AuthCleanupPolicy $policy): AuthCleanupReport;
}
