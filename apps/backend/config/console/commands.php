<?php

declare(strict_types=1);

use App\Console;

return [
    ...(class_exists(\App\Education\Console\MigrateCommand::class) ? [
        'education:migrate' => \App\Education\Console\MigrateCommand::class,
        'education:teacher:assign' => \App\Education\Console\AssignTeacherCommand::class,
    ] : []),
    ...(class_exists(\App\DeviceManagement\Console\MigrateCommand::class) ? [
        'device:migrate' => \App\DeviceManagement\Console\MigrateCommand::class,
    ] : []),
    ...(class_exists(\App\Storage\Console\MigrateCommand::class) ? [
        'storage:migrate' => \App\Storage\Console\MigrateCommand::class,
    ] : []),
    'scheduler:run' => Console\SchedulerRunCommand::class,
    'ops:status' => Console\OpsStatusCommand::class,
    'ops:health' => Console\OpsHealthCommand::class,
    'auth/cleanup' => Console\AuthCleanupCommand::class,
    'db:check' => Console\DbCheckCommand::class,
    'runtime:installation:init' => Console\RuntimeInstallationInitCommand::class,
    'runtime:deployment:activate' => Console\RuntimeDeploymentActivateCommand::class,
    'runtime:release:apply' => Console\RuntimeReleaseApplyCommand::class,
    'runtime:release:verify' => Console\RuntimeReleaseVerifyCommand::class,
    'runtime:migrations:validate-history' => Console\RuntimeMigrationsValidateHistoryCommand::class,
    'runtime:migrations:apply' => Console\RuntimeMigrationsApplyCommand::class,
    'runtime:entitlements:sync' => Console\RuntimeEntitlementsSyncCommand::class,
    'communication:process-email' => Console\ProcessEmailOutboxCommand::class,
    'communication:preview-emails' => Console\EmailPreviewCommand::class,
    'core:bootstrap-superadmin' => Console\BootstrapSuperadminCommand::class,
    'core:rotate-password' => Console\RotateAccountPasswordCommand::class,
    'privacy/retention/evaluate' => Console\RetentionEvaluateCommand::class,
    'privacy/erasure/execute' => Console\ErasureExecuteCommand::class,
    'privacy/compliance/check' => Console\PrivacyComplianceCheckCommand::class,
    'privacy/cleanup' => Console\PrivacyCleanupCommand::class,
];
