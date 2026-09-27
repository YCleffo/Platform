<?php

declare(strict_types=1);

use App\Core\Account\Application\AccountRepository;
use App\Core\Account\Application\AccountLifecycleRepository;
use App\Core\Account\Application\AccountSecurityRepository;
use App\Core\Account\Application\AccountPreferencesRepository;
use App\Core\Account\Infrastructure\PostgresAccountPreferencesRepository;
use App\Core\Account\Infrastructure\PostgresAccountRepository;
use App\Core\Account\Infrastructure\PostgresAccountLifecycleRepository;
use App\Core\Account\Infrastructure\PostgresAccountSecurityRepository;
use App\Core\AccountSettings\Application\AccountSettingsRepository;
use App\Core\AccountSettings\Infrastructure\PostgresAccountSettingsRepository;
use App\Core\Audit\Application\AuditWriter;
use App\Core\Audit\Infrastructure\PostgresAuditWriter;
use App\Core\Auth\Application\AuthChallengeRepository;
use App\Core\Auth\Application\AuthCleanupRepository;
use App\Core\Auth\Application\CredentialLifecycleRepository;
use App\Core\Auth\Application\AuthProviderRepository;
use App\Core\Auth\Application\ExternalIdentityRepository;
use App\Core\Auth\Application\IdentifierRepository;
use App\Core\Auth\Application\PasskeyRepository;
use App\Core\Auth\Application\PasswordCredentialRepository;
use App\Core\Auth\Application\RecoveryCodeRepository;
use App\Core\Auth\Application\SessionRepository;
use App\Core\Auth\Application\TotpFactorRepository;
use App\Core\Auth\Infrastructure\PostgresAuthRepository;
use App\Core\Auth\Infrastructure\PostgresAuthCleanupRepository;
use App\Core\Auth\Infrastructure\PostgresCredentialLifecycleRepository;
use App\Core\Module\Application\ModuleRepository;
use App\Core\Module\Infrastructure\PostgresModuleRepository;
use App\Core\Messaging\Application\InboxRepository;
use App\Core\Messaging\Application\OutboxRepository;
use App\Core\Messaging\Infrastructure\PostgresInboxRepository;
use App\Core\Messaging\Infrastructure\PostgresOutboxRepository;
use App\Core\Person\Application\ContactNormalizer;
use App\Core\Person\Application\PersonContactRepository;
use App\Core\Person\Application\PersonRepository;
use App\Core\Person\Infrastructure\PostgresPersonContactRepository;
use App\Core\Person\Infrastructure\PostgresPersonRepository;
use App\Core\Person\Infrastructure\CanonicalContactNormalizer;
use App\Core\Rbac\Application\PermissionRepository;
use App\Core\Rbac\Application\RoleRepository;
use App\Core\Rbac\Infrastructure\PostgresPermissionRepository;
use App\Core\Rbac\Infrastructure\PostgresRoleRepository;
use App\Core\Settings\Application\FeatureFlagRepository;
use App\Core\Settings\Application\ModuleSettingsRepository;
use App\Core\Settings\Application\SystemSettingsRepository;
use App\Core\Settings\Infrastructure\PostgresFeatureFlagRepository;
use App\Core\Settings\Infrastructure\PostgresModuleSettingsRepository;
use App\Core\Settings\Infrastructure\PostgresSystemSettingsRepository;

return [
    AccountRepository::class => [
        'class' => PostgresAccountRepository::class,
    ],
    AccountLifecycleRepository::class => [
        'class' => PostgresAccountLifecycleRepository::class,
    ],
    AccountSecurityRepository::class => [
        'class' => PostgresAccountSecurityRepository::class,
    ],
    AccountPreferencesRepository::class => [
        'class' => PostgresAccountPreferencesRepository::class,
    ],
    AccountSettingsRepository::class => [
        'class' => PostgresAccountSettingsRepository::class,
    ],
    AuditWriter::class => ['class' => PostgresAuditWriter::class],
    IdentifierRepository::class => ['class' => PostgresAuthRepository::class],
    PasswordCredentialRepository::class => ['class' => PostgresAuthRepository::class],
    AuthProviderRepository::class => ['class' => PostgresAuthRepository::class],
    ExternalIdentityRepository::class => ['class' => PostgresAuthRepository::class],
    PasskeyRepository::class => ['class' => PostgresAuthRepository::class],
    TotpFactorRepository::class => ['class' => PostgresAuthRepository::class],
    RecoveryCodeRepository::class => ['class' => PostgresAuthRepository::class],
    SessionRepository::class => ['class' => PostgresAuthRepository::class],
    AuthChallengeRepository::class => ['class' => PostgresAuthRepository::class],
    AuthCleanupRepository::class => ['class' => PostgresAuthCleanupRepository::class],
    CredentialLifecycleRepository::class => ['class' => PostgresCredentialLifecycleRepository::class],
    OutboxRepository::class => ['class' => PostgresOutboxRepository::class],
    InboxRepository::class => ['class' => PostgresInboxRepository::class],
    ModuleRepository::class => ['class' => PostgresModuleRepository::class],
    ModuleSettingsRepository::class => ['class' => PostgresModuleSettingsRepository::class],
    FeatureFlagRepository::class => ['class' => PostgresFeatureFlagRepository::class],
    SystemSettingsRepository::class => ['class' => PostgresSystemSettingsRepository::class],
    PersonRepository::class => [
        'class' => PostgresPersonRepository::class,
    ],
    PersonContactRepository::class => [
        'class' => PostgresPersonContactRepository::class,
    ],
    ContactNormalizer::class => [
        'class' => CanonicalContactNormalizer::class,
    ],
    RoleRepository::class => [
        'class' => PostgresRoleRepository::class,
    ],
    PermissionRepository::class => [
        'class' => PostgresPermissionRepository::class,
    ],
];
