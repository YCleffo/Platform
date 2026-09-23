<?php

declare(strict_types=1);

namespace App\Api\Shared;

final class ApiMessage
{
    public const string HTTP_BAD_REQUEST = 'http.bad_request';
    public const string HTTP_UNAUTHORIZED = 'http.unauthorized';
    public const string HTTP_FORBIDDEN = 'http.forbidden';
    public const string HTTP_NOT_FOUND = 'http.not_found';
    public const string HTTP_METHOD_NOT_ALLOWED = 'http.method_not_allowed';
    public const string HTTP_CONFLICT = 'http.conflict';
    public const string HTTP_PAYLOAD_TOO_LARGE = 'http.payload_too_large';
    public const string HTTP_UNSUPPORTED_MEDIA_TYPE = 'http.unsupported_media_type';
    public const string HTTP_UNPROCESSABLE_ENTITY = 'http.unprocessable_entity';
    public const string HTTP_TOO_MANY_REQUESTS = 'http.too_many_requests';
    public const string HTTP_INTERNAL_ERROR = 'http.internal_error';
    public const string HTTP_SERVICE_UNAVAILABLE = 'http.service_unavailable';

    public const string AUTH_REQUIRED = 'auth.required';
    public const string AUTH_PERMISSION_DENIED = 'auth.permission_denied';
    public const string AUTH_INVALID_CREDENTIALS = 'auth.invalid_credentials';
    public const string AUTH_ACCOUNT_UNAVAILABLE = 'auth.account_unavailable';
    public const string AUTH_ACCOUNT_LOCKED = 'auth.account_locked';
    public const string AUTH_SESSION_INVALID = 'auth.session.invalid';
    public const string AUTH_SESSION_EXPIRED = 'auth.session.expired';
    public const string AUTH_SESSION_REVOKED = 'auth.session.revoked';
    public const string AUTH_REFRESH_INVALID = 'auth.refresh.invalid';
    public const string AUTH_REFRESH_EXPIRED = 'auth.refresh.expired';
    public const string AUTH_ACTIVATION_INVALID = 'auth.activation.invalid';
    public const string AUTH_ACTIVATION_EXPIRED = 'auth.activation.expired';
    public const string AUTH_ACTIVATION_COMPLETED = 'auth.activation.completed';
    public const string AUTH_PASSWORD_RESET_REQUESTED = 'auth.password.reset_requested';
    public const string AUTH_PASSWORD_RESET_INVALID = 'auth.password.reset_invalid';
    public const string AUTH_PASSWORD_RESET_COMPLETED = 'auth.password.reset_completed';
    public const string AUTH_RATE_LIMITED = 'auth.rate_limited';
    public const string AUTH_LOGIN_RATE_LIMITED = 'auth.login_rate_limited';
    public const string AUTH_LOGIN_UNAVAILABLE = 'auth.login_unavailable';
    public const string AUTH_PASSWORD_RESET_RATE_LIMITED = 'auth.password_reset_rate_limited';
    public const string AUTH_PASSWORD_RESET_UNAVAILABLE = 'auth.password_reset_unavailable';
    public const string AUTH_ACTIVATION_RATE_LIMITED = 'auth.activation_rate_limited';
    public const string AUTH_ACTIVATION_UNAVAILABLE = 'auth.activation_unavailable';
    public const string AUTH_MFA_INVALID = 'auth.mfa.invalid';
    public const string AUTH_MFA_UPDATED = 'auth.mfa.updated';
    public const string AUTH_MFA_RECOVERY_REQUESTED = 'auth.mfa.recovery_requested';
    public const string AUTH_MFA_RECOVERY_COMPLETED = 'auth.mfa.recovery_completed';
    public const string AUTH_RECENT_REQUIRED = 'auth.recent_required';
    public const string AUTH_PROVIDER_UNAVAILABLE = 'auth.provider.unavailable';
    public const string AUTH_PROVIDER_NOT_LINKED = 'auth.provider.not_linked';
    public const string AUTH_EXTERNAL_INVALID = 'auth.external.invalid';
    public const string AUTH_IDENTITY_CONFLICT = 'auth.identity.conflict';
    public const string AUTH_LAST_METHOD = 'auth.identity.last_method';
    public const string AUTH_PASSKEY_INVALID = 'auth.passkey.invalid';
    public const string AUTH_PASSKEY_CONFLICT = 'auth.passkey.conflict';
    public const string AUTH_PASSKEY_NOT_FOUND = 'auth.passkey.not_found';

    public const string ACCOUNT_INVALID_ID = 'account.invalid_id';
    public const string ACCOUNT_NOT_FOUND = 'account.not_found';
    public const string ACCOUNT_PERSON_NOT_FOUND = 'account.person_not_found';
    public const string ACCOUNT_INVITE_INVALID = 'account.invite_invalid';
    public const string ACCOUNT_CONFLICT = 'account.conflict';
    public const string ACCOUNT_PREFERENCES_INVALID = 'account.preferences_invalid';
    public const string PERSON_INVALID_ID = 'person.invalid_id';
    public const string PERSON_NOT_FOUND = 'person.not_found';
    public const string PERSON_ACTIVE_NOT_FOUND = 'person.active_not_found';
    public const string PERSON_CONTACT_CONFLICT = 'person.contact_conflict';
    public const string ROLE_INVALID_ID = 'role.invalid_id';
    public const string RBAC_TARGET_NOT_FOUND = 'rbac.target_not_found';
    public const string RBAC_CONFLICT = 'rbac.conflict';
    public const string SESSION_INVALID_ID = 'session.invalid_id';
    public const string SESSION_NOT_FOUND = 'session.not_found';
    public const string HEALTH_NOT_READY = 'health.not_ready';
    public const string HEALTH_DATABASE_UNAVAILABLE = 'health.database_unavailable';
    public const string MODULE_NOT_FOUND = 'module.not_found';
    public const string SETTING_NOT_FOUND = 'setting.not_found';
    public const string FEATURE_FLAG_NOT_FOUND = 'feature_flag.not_found';

    public const string VALIDATION_BODY_OBJECT_REQUIRED = 'validation.body_object_required';
    public const string VALIDATION_FAILED = 'validation.failed';
    public const string VALIDATION_INVALID = 'validation.invalid';
    public const string VALIDATION_UNKNOWN_FIELD = 'validation.unknown_field';
    public const string VALIDATION_REQUIRED = 'validation.required';
    public const string VALIDATION_STRING = 'validation.string';
    public const string VALIDATION_STRING_OR_NULL = 'validation.string_or_null';
    public const string VALIDATION_NOT_BLANK = 'validation.not_blank';
    public const string VALIDATION_MAX_LENGTH = 'validation.length.max';
    public const string VALIDATION_BOOLEAN = 'validation.boolean';
    public const string VALIDATION_INTEGER = 'validation.integer';
    public const string VALIDATION_LIMIT_RANGE = 'validation.range';
    public const string VALIDATION_OFFSET_MIN = 'validation.offset_min';
    public const string VALIDATION_DATE_STRING = 'validation.date_string';
    public const string VALIDATION_DATE_INVALID = 'validation.datetime.invalid';
    public const string VALIDATION_UPDATE_REQUIRED = 'validation.update_required';
    public const string VALIDATION_CONTACT_TYPE = 'validation.contact_type';
    public const string VALIDATION_IDENTIFIER_TYPE = 'validation.identifier_type';
    public const string VALIDATION_PASSWORD_MIN_LENGTH = 'validation.length.min';
    public const string VALIDATION_PREFERENCE_REQUIRED = 'validation.preference_required';
    public const string VALIDATION_ACCOUNT_TYPE = 'validation.account_type';
    public const string VALIDATION_EMAIL_INVALID = 'validation.email.invalid';
    public const string VALIDATION_UUID = 'validation.uuid.invalid';
    public const string VALIDATION_LOCALE_INVALID = 'validation.locale.invalid';
    public const string VALIDATION_TIMEZONE_INVALID = 'validation.timezone.invalid';
    public const string VALIDATION_ENUM_INVALID = 'validation.enum.invalid';
    public const string VALIDATION_CONFLICT = 'validation.conflict';
    public const string VALIDATION_HUMAN_PERSON_REQUIRED = 'validation.human_person_required';
    public const string VALIDATION_LABEL = 'validation.label';
    public const string VALIDATION_CODE = 'validation.code';
    public const string VALIDATION_KEY = 'validation.key';
    public const string VALIDATION_JSON_OBJECT = 'validation.json_object';
    public const string VALIDATION_JSON_TOO_LARGE = 'validation.json_too_large';
    public const string VALIDATION_SECRET_KEY_FORBIDDEN = 'validation.secret_key_forbidden';
    public const string VALIDATION_EXACTLY_ONE_MFA_CODE = 'validation.exactly_one_mfa_code';
    public const string VALIDATION_PERSON_NAME_INVALID = 'validation.person_name_invalid';
    public const string VALIDATION_PHONE_INVALID = 'validation.phone_invalid';
    public const string VALIDATION_USERNAME_INVALID = 'validation.username_invalid';
    public const string VALIDATION_TEXT_INVALID = 'validation.text_invalid';
    public const string VALIDATION_BIRTH_DATE_FUTURE = 'validation.birth_date_future';

    public static function forStatus(int $status): string
    {
        return match ($status) {
            400 => self::HTTP_BAD_REQUEST,
            401 => self::HTTP_UNAUTHORIZED,
            403 => self::HTTP_FORBIDDEN,
            404 => self::HTTP_NOT_FOUND,
            405 => self::HTTP_METHOD_NOT_ALLOWED,
            409 => self::HTTP_CONFLICT,
            413 => self::HTTP_PAYLOAD_TOO_LARGE,
            415 => self::HTTP_UNSUPPORTED_MEDIA_TYPE,
            422 => self::HTTP_UNPROCESSABLE_ENTITY,
            429 => self::HTTP_TOO_MANY_REQUESTS,
            503 => self::HTTP_SERVICE_UNAVAILABLE,
            default => self::HTTP_INTERNAL_ERROR,
        };
    }
}
