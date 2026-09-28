<?php

declare(strict_types=1);

namespace App\Core\Security\Application;

final class SecurityAction
{
    public const LOGIN_SUCCESS = 'auth.login.succeeded';
    public const LOGIN_FAILED = 'auth.login.failed';
    public const PASSWORD_CHANGED = 'auth.password.changed';
    public const SESSION_CREATED = 'auth.session.created';
    public const SESSION_REVOKED = 'auth.session.revoked';
    public const SESSION_REVOKED_ALL = 'auth.session.revoked_all';
    public const SESSION_IP_CHANGED = 'auth.session.ip_changed';
    public const SESSION_DEVICE_CHANGED = 'auth.session.device_changed';
    public const SESSION_REFRESHED = 'auth.session.refreshed';
    public const PASSWORD_RESET = 'auth.password.reset';
    public const ACCOUNT_ACTIVATED = 'auth.account.activated';
    public const ACCOUNT_APPROVED = 'core.account.approved';
    public const ACCOUNT_DISABLED = 'core.account.disabled';
    public const ACCOUNT_ENABLED = 'core.account.enabled';
    public const ACCOUNT_ARCHIVED = 'core.account.archived';
    public const ACCOUNT_SETTINGS_UPDATED = 'core.account_settings.updated';
    public const ROLE_ASSIGNED = 'core.rbac.role_assigned';
    public const ROLE_REVOKED = 'core.rbac.role_revoked';
    public const ROLE_CREATED = 'core.rbac.role_created';
    public const ROLE_UPDATED = 'core.rbac.role_updated';
    public const ROLE_DELETED = 'core.rbac.role_deleted';
    public const ROLE_PERMISSIONS_UPDATED = 'core.rbac.permissions_updated';
    public const MODULE_ENABLED = 'core.module.enabled';
    public const MODULE_DISABLED = 'core.module.disabled';
    public const SYSTEM_SETTING_UPDATED = 'core.system_setting.updated';
    public const SYSTEM_SETTING_DELETED = 'core.system_setting.deleted';
    public const MODULE_SETTING_UPDATED = 'core.module_setting.updated';
    public const MODULE_SETTING_DELETED = 'core.module_setting.deleted';
    public const FEATURE_FLAG_UPDATED = 'core.feature_flag.updated';
    public const FEATURE_FLAG_DELETED = 'core.feature_flag.deleted';
    public const EXTERNAL_LOGIN_SUCCEEDED = 'auth.external_login.succeeded';
    public const EXTERNAL_LOGIN_FAILED = 'auth.external_login.failed';
    public const EXTERNAL_IDENTITY_LINKED = 'auth.external_identity.linked';
    public const EXTERNAL_IDENTITY_UNLINKED = 'auth.external_identity.unlinked';
    public const MFA_REQUIRED = 'auth.mfa.required';
    public const MFA_VERIFIED = 'auth.mfa.verified';
    public const MFA_SUCCESS = 'auth.mfa.success';
    public const MFA_FAILED = 'auth.mfa.failed';
    public const TOTP_SETUP_STARTED = 'auth.totp.setup_started';
    public const TOTP_ENABLED = 'auth.totp.enabled';
    public const TOTP_DISABLED = 'auth.totp.disabled';
    public const RECOVERY_CODE_USED = 'auth.recovery_code.used';
    public const RECOVERY_CODES_REGENERATED = 'auth.recovery_codes.regenerated';
    public const MFA_RECOVERY_REQUESTED = 'auth.mfa_recovery.requested';
    public const MFA_RECOVERY_COMPLETED = 'auth.mfa_recovery.completed';
    public const MFA_RECOVERY_FAILED = 'auth.mfa_recovery.failed';
    public const MFA_RECOVERY_EXTERNAL_REAUTH_REQUIRED = 'auth.mfa_recovery.external_reauth_required';
    public const MFA_RECOVERY_EXTERNAL_REAUTH_SUCCEEDED = 'auth.mfa_recovery.external_reauth_succeeded';
    public const MFA_RECOVERY_EXTERNAL_REAUTH_FAILED = 'auth.mfa_recovery.external_reauth_failed';
    public const SUSPICIOUS_ACTIVITY = 'auth.suspicious_activity';
    public const PASSKEY_REGISTRATION_STARTED = 'auth.passkey_registration.started';
    public const PASSKEY_REGISTRATION_COMPLETED = 'auth.passkey_registration.completed';
    public const PASSKEY_REGISTRATION_FAILED = 'auth.passkey_registration.failed';
    public const PASSKEY_AUTHENTICATION_SUCCEEDED = 'auth.passkey_authentication.succeeded';
    public const PASSKEY_AUTHENTICATION_FAILED = 'auth.passkey_authentication.failed';
    public const PASSKEY_RENAMED = 'auth.passkey.renamed';
    public const PASSKEY_REVOKED = 'auth.passkey.revoked';
    public const PASSKEY_COUNTER_ANOMALY = 'auth.passkey.counter_anomaly';

    /** @return non-empty-list<string> */
    public static function all(): array
    {
        return [
            self::LOGIN_SUCCESS,
            self::LOGIN_FAILED,
            self::PASSWORD_CHANGED,
            self::SESSION_CREATED,
            self::SESSION_REVOKED,
            self::SESSION_REVOKED_ALL,
            self::SESSION_IP_CHANGED,
            self::SESSION_DEVICE_CHANGED,
            self::SESSION_REFRESHED,
            self::PASSWORD_RESET,
            self::ACCOUNT_ACTIVATED,
            self::ACCOUNT_APPROVED,
            self::ACCOUNT_DISABLED,
            self::ACCOUNT_ENABLED,
            self::ACCOUNT_ARCHIVED,
            self::ACCOUNT_SETTINGS_UPDATED,
            self::ROLE_ASSIGNED,
            self::ROLE_REVOKED,
            self::ROLE_CREATED,
            self::ROLE_UPDATED,
            self::ROLE_DELETED,
            self::ROLE_PERMISSIONS_UPDATED,
            self::MODULE_ENABLED,
            self::MODULE_DISABLED,
            self::SYSTEM_SETTING_UPDATED,
            self::SYSTEM_SETTING_DELETED,
            self::MODULE_SETTING_UPDATED,
            self::MODULE_SETTING_DELETED,
            self::FEATURE_FLAG_UPDATED,
            self::FEATURE_FLAG_DELETED,
            self::EXTERNAL_LOGIN_SUCCEEDED,
            self::EXTERNAL_LOGIN_FAILED,
            self::EXTERNAL_IDENTITY_LINKED,
            self::EXTERNAL_IDENTITY_UNLINKED,
            self::MFA_REQUIRED,
            self::MFA_VERIFIED,
            self::MFA_SUCCESS,
            self::MFA_FAILED,
            self::TOTP_SETUP_STARTED,
            self::TOTP_ENABLED,
            self::TOTP_DISABLED,
            self::RECOVERY_CODE_USED,
            self::RECOVERY_CODES_REGENERATED,
            self::MFA_RECOVERY_REQUESTED,
            self::MFA_RECOVERY_COMPLETED,
            self::MFA_RECOVERY_FAILED,
            self::MFA_RECOVERY_EXTERNAL_REAUTH_REQUIRED,
            self::MFA_RECOVERY_EXTERNAL_REAUTH_SUCCEEDED,
            self::MFA_RECOVERY_EXTERNAL_REAUTH_FAILED,
            self::SUSPICIOUS_ACTIVITY,
            self::PASSKEY_REGISTRATION_STARTED,
            self::PASSKEY_REGISTRATION_COMPLETED,
            self::PASSKEY_REGISTRATION_FAILED,
            self::PASSKEY_AUTHENTICATION_SUCCEEDED,
            self::PASSKEY_AUTHENTICATION_FAILED,
            self::PASSKEY_RENAMED,
            self::PASSKEY_REVOKED,
            self::PASSKEY_COUNTER_ANOMALY,
        ];
    }
}
