<?php

declare(strict_types=1);

use App\Console;

return [
    'scheduler:run' => Console\SchedulerRunCommand::class,
    'auth/cleanup' => Console\AuthCleanupCommand::class,
    'db:check' => Console\DbCheckCommand::class,
    'runtime:installation:init' => Console\RuntimeInstallationInitCommand::class,
    'runtime:deployment:activate' => Console\RuntimeDeploymentActivateCommand::class,
    'runtime:entitlements:sync' => Console\RuntimeEntitlementsSyncCommand::class,
    'communication:process-email' => Console\ProcessEmailOutboxCommand::class,
    'core:bootstrap-superadmin' => Console\BootstrapSuperadminCommand::class,
    'privacy/retention/evaluate' => Console\RetentionEvaluateCommand::class,
    'privacy/erasure/execute' => Console\ErasureExecuteCommand::class,
    'privacy/compliance/check' => Console\PrivacyComplianceCheckCommand::class,
    'privacy/cleanup' => Console\PrivacyCleanupCommand::class,
];
