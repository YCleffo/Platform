import {
  ApiError,
  ApiResponseError,
  NetworkError,
  RequestAbortedError,
  SupersededOperationError,
} from '@/shared/api/errors';
import { i18n } from '@/shared/i18n';

const CODE_KEYS: Record<string, string> = {
  operator_authorization_required: 'operator.authorizationRequired',
  passkey_insecure: 'coreCredentials.insecure', passkey_unsupported: 'coreCredentials.unsupported',
  passkey_cancelled: 'coreCredentials.cancelled', passkey_duplicate: 'coreCredentials.duplicate',
  passkey_origin: 'coreCredentials.origin', passkey_unavailable: 'coreCredentials.unavailable',
  identity_popup_blocked: 'coreCredentials.popupBlocked', identity_popup_unsupported: 'coreCredentials.popupUnsupported',
  identity_link_timeout: 'coreCredentials.linkTimeout', identity_link_cancelled: 'coreCredentials.linkCancelled',
  'auth.recent_required': 'coreSecurity.recentRequired', 'auth.identity.last_method': 'coreCredentials.lastMethod',
  'auth.identity.conflict': 'coreCredentials.identityConflict', 'auth.passkey.invalid': 'coreCredentials.invalid',
  'auth.passkey.conflict': 'coreCredentials.duplicate', 'auth.passkey.not_found': 'errors.notFound',
  'auth.provider.unavailable': 'coreCredentials.providerUnavailable', 'auth.provider.not_linked': 'errors.notFound',
  'account.preferences_invalid': 'coreProfile.invalidPreferences',
  core_recovery_callback_invalid: 'coreAuth.externalInvalid',
  core_recovery_cancelled: 'coreAuth.externalCancelled',
  core_recovery_storage: 'coreAuth.externalStorage',
  'auth.external.invalid': 'coreAuth.externalInvalid',
  invalid_credentials: 'auth.invalidCredentials',
  'auth.invalid_credentials': 'auth.invalidCredentials',
  'auth.mfa.invalid': 'auth.mfaInvalid',
  'auth.activation.invalid': 'coreAuth.linkInvalid',
  'auth.activation.expired': 'coreAuth.linkInvalid',
  'auth.password.reset_invalid': 'coreAuth.linkInvalid',
  'auth.password_reset_rate_limited': 'errors.rate_limited',
  'auth.activation_rate_limited': 'errors.rate_limited',
  'auth.password_reset_unavailable': 'coreAuth.temporarilyUnavailable',
  'auth.activation_unavailable': 'coreAuth.temporarilyUnavailable',
  'auth.login_unavailable': 'coreAuth.temporarilyUnavailable',
  core_challenge_missing: 'coreAuth.restartLogin',
  unexpected_response: 'errors.invalidResponse',
  'auth.rate_limited': 'errors.rate_limited',
  'auth.login_rate_limited': 'errors.rate_limited',
  'auth.permission_denied': 'admin.forbidden',
  'auth.required': 'errors.sessionExpired',
  'auth.session.invalid': 'errors.sessionExpired',
  'auth.session.expired': 'errors.sessionExpired',
  'auth.session.revoked': 'errors.sessionExpired',
  'http.unauthorized': 'errors.sessionExpired',
  'http.forbidden': 'admin.forbidden',
  'http.not_found': 'errors.notFound',
  authentication_required: 'errors.sessionExpired',
  mfa_code_invalid: 'auth.mfaInvalid',
  mfa_challenge_invalid: 'auth.mfaInvalid',
  mfa_challenge_expired: 'auth.mfaExpired',
  mfa_method_required: 'auth.mfaInvalid',
  mfa_enrollment_required: 'security.mfaRequired',
  recent_mfa_required: 'security.mfaRequired',
  password_reset_invalid: 'auth.resetInvalid',
  verification_invalid: 'auth.verifyLinkInvalid',
  verification_used: 'auth.verifyLinkInvalid',
  verification_expired: 'auth.verifyLinkInvalid',
  rate_limited: 'errors.rate_limited',
  email_unavailable: 'errors.email_unavailable',
  validation_failed: 'errors.validation_failed',
  request_too_large: 'errors.request_too_large',
  resource_not_found: 'errors.notFound',
  operation_rejected: 'errors.operationRejected',
};

/**
 * Maps an unknown failure to a safe, localized, user-facing message.
 * Unknown API codes fall back to a generic localized message (never a raw English/server string).
 */
export function resolveErrorMessage(error: unknown): string {
  if (error instanceof RequestAbortedError || error instanceof SupersededOperationError) {
    return '';
  }
  if (error instanceof NetworkError) {
    return i18n.global.t('errors.network');
  }
  if (error instanceof ApiResponseError) {
    return i18n.global.t('errors.invalidResponse');
  }
  if (error instanceof ApiError) {
    const key = CODE_KEYS[error.code];
    return i18n.global.t(key ?? 'errors.generic');
  }
  return i18n.global.t('common.unexpectedError');
}
