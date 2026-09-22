<?php

declare(strict_types=1);

use App\Console;

return [
    'auth/cleanup' => Console\AuthCleanupCommand::class,
    'db:check' => Console\DbCheckCommand::class,
    'communication:process-email' => Console\ProcessEmailOutboxCommand::class,
    'core:bootstrap-superadmin' => Console\BootstrapSuperadminCommand::class,
];
