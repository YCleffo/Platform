<?php

declare(strict_types=1);

use App\Environment;
use Yiisoft\Db\Pgsql\Dsn;

/**
 * Read a required environment variable.
 *
 * Works both with:
 * - local .env loaded by phpdotenv;
 * - real process/container environment variables.
 */
$env = static function (string ...$names): string {
    foreach ($names as $name) {
        $value = getenv($name);

        if ($value !== false && $value !== '') {
            return $value;
        }

        if (isset($_ENV[$name]) && $_ENV[$name] !== '') {
            return (string) $_ENV[$name];
        }
    }

    throw new \RuntimeException(
        sprintf(
            'Required environment variable is missing: %s',
            implode(' or ', $names),
        ),
    );
};

/** Read an optional environment variable without confusing "0" with empty. */
$envOr = static function (string $name, string $default): string {
    $value = getenv($name);
    if ($value !== false && $value !== '') {
        return $value;
    }

    if (isset($_ENV[$name]) && $_ENV[$name] !== '') {
        return (string) $_ENV[$name];
    }

    return $default;
};

$nullableEnv = static function (string $name) use ($envOr): ?string {
    $value = $envOr($name, '');
    return $value === '' ? null : $value;
};

$csvEnv = static function (string $name) use ($envOr): array {
    return array_values(array_filter(
        array_map('trim', explode(',', $envOr($name, ''))),
        static fn(string $value): bool => $value !== '',
    ));
};

$totpEncryptionKey = $nullableEnv('AUTH_TOTP_ENCRYPTION_KEY');
if (Environment::isProd() && $totpEncryptionKey === null) {
    throw new \RuntimeException('AUTH_TOTP_ENCRYPTION_KEY is required in production.');
}

return [
    'runtimeTrustedEntitlementKeys' => json_decode($envOr('RUNTIME_ENTITLEMENT_TRUSTED_KEYS', '{}'), true, 16, JSON_THROW_ON_ERROR),
    'runtimeControlPlaneUrl' => $envOr('CONTROL_PLANE_URL', ''),
    'runtimeDeploymentCredentialReference' => $envOr('CONTROL_PLANE_DEPLOYMENT_CREDENTIAL_REF', ''),
    'application' => require __DIR__ . '/application.php',

    'yiisoft/aliases' => [
        'aliases' => require __DIR__ . '/aliases.php',
    ],

    /*
     * PostgreSQL connection parameters.
     *
     * No database credentials are hardcoded here.
     * Values come from environment variables.
     */
    'yiisoft/db-pgsql' => [
        'dsn' => new Dsn(
            'pgsql',
            getenv('DB_HOST') ?: '127.0.0.1',
            $env('DB_NAME', 'POSTGRES_DB'),
            $env('DB_PORT', 'POSTGRES_PORT'),
        ),

        'username' => $env('DB_USER', 'POSTGRES_USER'),
        'password' => $env('DB_PASSWORD', 'POSTGRES_PASSWORD'),
    ],

    'internationalization' => [
        'supportedLocales' => ['ru-RU', 'en-US'],
        'defaultLocale' => $envOr('APP_DEFAULT_LOCALE', 'ru-RU'),
        'messagesPath' => dirname(__DIR__, 2) . '/resources/messages',
    ],

    'time' => [
        'defaultTimezone' => $envOr('APP_DEFAULT_TIMEZONE', 'UTC'),
    ],

    'privacyBusinessCalendar' => [
        // No legal calendar is assumed until a covered period is configured.
        'coverageFrom' => $envOr('PRIVACY_CALENDAR_COVERAGE_FROM', '1970-01-01'),
        'coverageUntil' => $envOr('PRIVACY_CALENDAR_COVERAGE_UNTIL', '1970-01-01'),
        'weekends' => [6, 7],
        'nonWorkingDates' => $csvEnv('PRIVACY_CALENDAR_NON_WORKING_DATES'),
        'workingOverrideDates' => $csvEnv('PRIVACY_CALENDAR_WORKING_OVERRIDE_DATES'),
        'sourceReference' => $nullableEnv('PRIVACY_CALENDAR_SOURCE_REFERENCE'),
        'verifiedAt' => $nullableEnv('PRIVACY_CALENDAR_VERIFIED_AT'),
    ],

    'client' => [
        'trustedProxies' => $csvEnv('TRUSTED_PROXIES'),
        'fingerprintHmacKey' => $nullableEnv('DEVICE_FINGERPRINT_KEY'),
    ],

    'auth' => [
        'sessionTouchIntervalSeconds' => (int) $envOr('SESSION_TOUCH_INTERVAL_SECONDS', '300'),
        'accessTokenTtlSeconds' => (int) $envOr('ACCESS_TOKEN_TTL_SECONDS', '900'),
        'sessionTtlSeconds' => (int) $envOr('SESSION_TTL_SECONDS', '2592000'),
        'activationTtlSeconds' => (int) $envOr('AUTH_ACTIVATION_TTL_SECONDS', '86400'),
        'passwordResetTtlSeconds' => (int) $envOr('AUTH_PASSWORD_RESET_TTL_SECONDS', '1800'),
        'mfaChallengeTtlSeconds' => (int) $envOr('AUTH_MFA_CHALLENGE_TTL_SECONDS', '300'),
        'mfaRecoveryTtlSeconds' => (int) $envOr('AUTH_MFA_RECOVERY_TTL_SECONDS', '1800'),
        'totpSetupTtlSeconds' => (int) $envOr('AUTH_TOTP_SETUP_TTL_SECONDS', '600'),
        'totpIssuer' => $envOr('AUTH_TOTP_ISSUER', 'Platform'),
        'cleanup' => [
            'challengeRetentionDays' => (int) $envOr('AUTH_CHALLENGE_RETENTION_DAYS', '30'),
            'recoveryCodeRetentionDays' => (int) $envOr('AUTH_RECOVERY_CODE_RETENTION_DAYS', '30'),
            'serviceCredentialRetentionDays' => (int) $envOr('AUTH_SERVICE_CREDENTIAL_RETENTION_DAYS', '90'),
            'batchSize' => (int) $envOr('AUTH_CLEANUP_BATCH_SIZE', '500'),
        ],
        'recentAuthenticationSeconds' => (int) $envOr('AUTH_RECENT_AUTH_SECONDS', '600'),
        'identifierHmacKey' => $nullableEnv('AUTH_IDENTIFIER_HMAC_KEY'),
        'totpEncryptionKey' => $totpEncryptionKey,
        'publicBaseUrl' => $envOr('AUTH_PUBLIC_BASE_URL', 'http://localhost:5173'),
        'providers' => [
            'google' => [
                'clientId' => $nullableEnv('AUTH_GOOGLE_CLIENT_ID'),
                'clientSecret' => $nullableEnv('AUTH_GOOGLE_CLIENT_SECRET'),
                'redirectUri' => $nullableEnv('AUTH_GOOGLE_REDIRECT_URI'),
            ],
            'telegram' => [
                'clientId' => $nullableEnv('AUTH_TELEGRAM_CLIENT_ID'),
                'clientSecret' => $nullableEnv('AUTH_TELEGRAM_CLIENT_SECRET'),
                'redirectUri' => $nullableEnv('AUTH_TELEGRAM_REDIRECT_URI'),
            ],
        ],
        'webauthn' => [
            'rpId' => $envOr('AUTH_WEBAUTHN_RP_ID', 'localhost'),
            'rpName' => $envOr('AUTH_WEBAUTHN_RP_NAME', 'Platform'),
            'origins' => array_values(array_filter(array_map('trim', explode(',', $envOr('AUTH_WEBAUTHN_ORIGINS', 'http://localhost:5173,http://localhost:8080'))))),
            'challengeTtlSeconds' => (int) $envOr('AUTH_WEBAUTHN_CHALLENGE_TTL_SECONDS', '300'),
        ],
        'rateLimit' => [
            'loginIpLimit' => (int) $envOr('AUTH_LOGIN_IP_LIMIT', '30'),
            'loginIdentifierLimit' => (int) $envOr('AUTH_LOGIN_IDENTIFIER_LIMIT', '10'),
            'loginCombinedLimit' => (int) $envOr('AUTH_LOGIN_COMBINED_LIMIT', '5'),
            'loginWindowSeconds' => (int) $envOr('AUTH_LOGIN_WINDOW_SECONDS', '300'),
            'passwordResetLimit' => (int) $envOr('AUTH_PASSWORD_RESET_LIMIT', '5'),
            'passwordResetWindowSeconds' => (int) $envOr('AUTH_PASSWORD_RESET_WINDOW_SECONDS', '3600'),
            'activationResendLimit' => (int) $envOr('AUTH_ACTIVATION_RESEND_LIMIT', '3'),
            'activationResendWindowSeconds' => (int) $envOr('AUTH_ACTIVATION_RESEND_WINDOW_SECONDS', '3600'),
            'externalStartIpLimit' => (int) $envOr('AUTH_EXTERNAL_START_IP_LIMIT', '20'),
            'externalCallbackLimit' => (int) $envOr('AUTH_EXTERNAL_CALLBACK_LIMIT', '10'),
            'mfaVerifyChallengeLimit' => (int) $envOr('AUTH_MFA_VERIFY_CHALLENGE_LIMIT', '5'),
            'mfaVerifyIpLimit' => (int) $envOr('AUTH_MFA_VERIFY_IP_LIMIT', '20'),
            'totpSetupConfirmLimit' => (int) $envOr('AUTH_TOTP_SETUP_CONFIRM_LIMIT', '5'),
            'mfaRecoveryIdentifierLimit' => (int) $envOr('AUTH_MFA_RECOVERY_IDENTIFIER_LIMIT', '5'),
            'mfaRecoveryIpLimit' => (int) $envOr('AUTH_MFA_RECOVERY_IP_LIMIT', '10'),
            'mfaRecoveryConfirmLimit' => (int) $envOr('AUTH_MFA_RECOVERY_CONFIRM_LIMIT', '5'),
            'mfaRecoveryExternalStartIpLimit' => (int) $envOr('AUTH_MFA_RECOVERY_EXTERNAL_START_IP_LIMIT', '10'),
            'mfaRecoveryExternalTransactionLimit' => (int) $envOr('AUTH_MFA_RECOVERY_EXTERNAL_TRANSACTION_LIMIT', '3'),
            'mfaRecoveryExternalCallbackLimit' => (int) $envOr('AUTH_MFA_RECOVERY_EXTERNAL_CALLBACK_LIMIT', '5'),
            'providerLinkLimit' => (int) $envOr('AUTH_PROVIDER_LINK_LIMIT', '10'),
            'recoveryCodeLimit' => (int) $envOr('AUTH_RECOVERY_CODE_LIMIT', '5'),
            'securityWindowSeconds' => (int) $envOr('AUTH_SECURITY_WINDOW_SECONDS', '900'),
            'passkeyAuthStartLimit' => (int) $envOr('AUTH_PASSKEY_AUTH_START_LIMIT', '20'),
            'passkeyAuthCompleteLimit' => (int) $envOr('AUTH_PASSKEY_AUTH_COMPLETE_LIMIT', '10'),
            'passkeyRegisterCompleteLimit' => (int) $envOr('AUTH_PASSKEY_REGISTER_COMPLETE_LIMIT', '10'),
        ],
    ],

    'redis' => [
        'host' => $envOr('REDIS_HOST', '127.0.0.1'),
        'port' => (int) $envOr('REDIS_PORT', '6379'),
        'database' => (int) $envOr('REDIS_DATABASE', '0'),
        'rateLimitPrefix' => $envOr('REDIS_RATE_LIMIT_PREFIX', 'platform:auth'),
    ],

    'geoIp' => [
        'databasePath' => $nullableEnv('GEOIP_DATABASE_PATH'),
    ],

    'security' => [
        'auditRetentionDays' => (int) $envOr('SECURITY_AUDIT_RETENTION_DAYS', '365'),
    ],

    'privacyDataResidency' => [
        'primaryDbCountry' => $nullableEnv('PRIVACY_PRIMARY_DB_COUNTRY'),
        'backupCountry' => $nullableEnv('PRIVACY_BACKUP_COUNTRY'),
        'objectStorageCountry' => $nullableEnv('PRIVACY_OBJECT_STORAGE_COUNTRY'),
        'logStorageCountry' => $nullableEnv('PRIVACY_LOG_STORAGE_COUNTRY'),
        'cacheCountry' => $nullableEnv('PRIVACY_CACHE_COUNTRY'),
        'verifiedAt' => $nullableEnv('PRIVACY_RESIDENCY_VERIFIED_AT'),
        'verificationReference' => $nullableEnv('PRIVACY_RESIDENCY_VERIFICATION_REFERENCE'),
    ],
    'privacySecurityReferences' => [
        'threatModelReference' => $nullableEnv('PRIVACY_THREAT_MODEL_REFERENCE'),
        'harmAssessmentReference' => $nullableEnv('PRIVACY_HARM_ASSESSMENT_REFERENCE'),
        'ispdnProtectionLevel' => $nullableEnv('PRIVACY_ISPDN_PROTECTION_LEVEL'),
        'responsiblePersonReference' => $nullableEnv('PRIVACY_RESPONSIBLE_PERSON_REFERENCE'),
        'securityApprovalReference' => $nullableEnv('PRIVACY_SECURITY_APPROVAL_REFERENCE'),
    ],
    'privacyIncidentDeadlines' => ['initialHours' => 24, 'investigationHours' => 72],
    'privacyCleanup' => [
        'auditDays' => (int) $envOr('SECURITY_AUDIT_RETENTION_DAYS', '365'),
        'outboxDays' => $nullableEnv('PRIVACY_OUTBOX_RETENTION_DAYS'),
        'inboxDays' => $nullableEnv('PRIVACY_INBOX_RETENTION_DAYS'),
        'dsarDays' => $nullableEnv('PRIVACY_DSAR_RETENTION_DAYS'),
        'blockDays' => $nullableEnv('PRIVACY_BLOCK_RETENTION_DAYS'),
        'holdDays' => $nullableEnv('PRIVACY_HOLD_RETENTION_DAYS'),
        'decisionDays' => $nullableEnv('PRIVACY_DECISION_RETENTION_DAYS'),
        'sessionDays' => $nullableEnv('PRIVACY_SESSION_RETENTION_DAYS'),
    ],

    'communication' => [
        'templates' => [
            'path' => dirname(__DIR__, 2) . '/resources/email',
        ],
        'smtp' => [
            'host' => $envOr('SMTP_HOST', '127.0.0.1'),
            'port' => (int) $envOr('SMTP_PORT', '1025'),
            'username' => $nullableEnv('SMTP_USERNAME'),
            'password' => $nullableEnv('SMTP_PASSWORD'),
            'encryption' => $envOr('SMTP_ENCRYPTION', 'none'),
            'fromAddress' => $envOr('MAIL_FROM_ADDRESS', 'no-reply@platform.local'),
            'fromName' => $envOr('MAIL_FROM_NAME', 'Platform'),
        ],
        'outbox' => [
            'leaseSeconds' => (int) $envOr('MAIL_OUTBOX_LEASE_SECONDS', '60'),
            'maxAttempts' => (int) $envOr('MAIL_OUTBOX_MAX_ATTEMPTS', '5'),
            'retryBaseSeconds' => (int) $envOr('MAIL_OUTBOX_RETRY_BASE_SECONDS', '30'),
            'retryMaxSeconds' => (int) $envOr('MAIL_OUTBOX_RETRY_MAX_SECONDS', '3600'),
            'messageIdDomain' => $envOr('MAIL_MESSAGE_ID_DOMAIN', 'platform.local'),
        ],
    ],
];
