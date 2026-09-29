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
use App\Core\AccountSettings\Application\SettingsSchemaRegistry;
use App\Core\AccountSettings\Infrastructure\PostgresAccountSettingsRepository;
use App\Core\Consent\Application\ConsentDefinitionRegistry;
use App\Core\Privacy\Application\DataCategoryRegistry;
use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use App\Core\Privacy\Application\RetentionPolicyRegistry;
use App\Core\Privacy\Application\LawfulProcessingBasisContributorRegistry;
use App\Core\Privacy\Application\RetentionCandidateContributorRegistry;
use App\Core\Privacy\Application\RetentionDependencyContributorRegistry;
use App\Core\Privacy\Application\PrivacyRequestExecutionCapabilityRegistry;
use App\Core\Privacy\Application\RepresentativeAuthorityPolicy;
use App\Core\Privacy\Application\RepresentationScopeRegistry;
use App\Core\Privacy\Application\BusinessCalendarInterface;
use App\Core\Privacy\Application\ProcessingBlockPolicy;
use App\Core\Privacy\Application\ProcessingBlockService;
use App\Core\Privacy\Application\PersonalDataContributorRegistry;
use App\Core\Privacy\Application\PersonalDataCorrectionContributorRegistry;
use App\Core\Privacy\Application\ProcessingRestrictionContributorRegistry;
use App\Core\Privacy\Infrastructure\CorePersonalDataContributorProvider;
use App\Core\Privacy\Infrastructure\CorePersonalDataCorrectionContributorProvider;
use App\Core\Privacy\Infrastructure\ConfiguredBusinessCalendar;
use App\Core\Privacy\Infrastructure\PostgresRepresentativeAuthorityPolicy;
use App\Core\Audit\Application\AuditWriter;
use App\Core\Audit\Application\AuditEventDefinitionRegistry;
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
use Yiisoft\Definitions\Reference;

/** @var array<string, mixed> $params */
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
    SettingsSchemaRegistry::class => [
        '__construct()' => ['definitions' => $params['accountSettingsDefinitions'] ?? []],
    ],
    ConsentDefinitionRegistry::class => [
        '__construct()' => ['definitions' => $params['consentDefinitions'] ?? []],
    ],
    DataCategoryRegistry::class => [
        '__construct()' => ['definitions' => $params['dataCategoryDefinitions'] ?? []],
    ],
    RetentionPolicyRegistry::class => [
        '__construct()' => ['definitions' => $params['retentionPolicyDefinitions'] ?? []],
    ],
    LawfulProcessingBasisContributorRegistry::class => [
        '__construct()' => ['contributors' => $params['lawfulProcessingBasisContributors'] ?? []],
    ],
    RetentionCandidateContributorRegistry::class => [
        '__construct()' => ['contributors' => $params['retentionCandidateContributors'] ?? []],
    ],
    RetentionDependencyContributorRegistry::class => [
        '__construct()' => ['contributors' => $params['retentionDependencyContributors'] ?? []],
    ],
    PrivacyRequestExecutionCapabilityRegistry::class => [
        '__construct()' => [
            'retentionCandidates' => Reference::to(RetentionCandidateContributorRegistry::class),
            'purposes' => Reference::to(ProcessingPurposeRegistry::class),
        ],
    ],
    ProcessingPurposeRegistry::class => [
        '__construct()' => ['definitions' => $params['processingPurposeDefinitions'] ?? []],
    ],
    RepresentativeAuthorityPolicy::class => ['class' => PostgresRepresentativeAuthorityPolicy::class],
    BusinessCalendarInterface::class => [
        'class' => ConfiguredBusinessCalendar::class,
        '__construct()' => [
            'coverageFrom' => $params['privacyBusinessCalendar']['coverageFrom'],
            'coverageUntil' => $params['privacyBusinessCalendar']['coverageUntil'],
            'weekends' => $params['privacyBusinessCalendar']['weekends'],
            'nonWorkingDates' => $params['privacyBusinessCalendar']['nonWorkingDates'],
            'workingOverrideDates' => $params['privacyBusinessCalendar']['workingOverrideDates'],
        ],
    ],
    ProcessingBlockPolicy::class => ['class' => ProcessingBlockService::class],
    CorePersonalDataContributorProvider::class => [
        '__construct()' => ['moduleContributors' => $params['personalDataContributors'] ?? []],
    ],
    PersonalDataContributorRegistry::class => [
        '__construct()' => ['contributors' => Reference::to(CorePersonalDataContributorProvider::class)],
    ],
    CorePersonalDataCorrectionContributorProvider::class => [
        '__construct()' => ['moduleContributors' => $params['personalDataCorrectionContributors'] ?? []],
    ],
    PersonalDataCorrectionContributorRegistry::class => [
        '__construct()' => ['contributors' => Reference::to(CorePersonalDataCorrectionContributorProvider::class)],
    ],
    ProcessingRestrictionContributorRegistry::class => [
        '__construct()' => ['contributors' => $params['processingRestrictionContributors'] ?? []],
    ],
    RepresentationScopeRegistry::class => [
        '__construct()' => ['additionalCodes' => $params['representationScopeCodes'] ?? []],
    ],
    AuditWriter::class => ['class' => PostgresAuditWriter::class],
    AuditEventDefinitionRegistry::class => [
        '__construct()' => ['additionalDefinitions' => $params['auditEventDefinitions'] ?? []],
    ],
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
