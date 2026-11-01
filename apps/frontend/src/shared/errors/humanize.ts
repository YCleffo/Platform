import {
  ApiError,
  ApiResponseError,
  NetworkError,
  RequestAbortedError,
  SupersededOperationError,
} from '@/shared/api/errors';
import { i18n } from '@/shared/i18n';

const CODE_KEYS: Record<string, string> = {
  invalid_credentials: 'auth.invalidCredentials',
  authentication_required: 'errors.sessionExpired',
  mfa_code_invalid: 'auth.mfaInvalid',
  mfa_challenge_invalid: 'auth.mfaInvalid',
  mfa_challenge_expired: 'auth.mfaExpired',
  mfa_method_required: 'auth.mfaInvalid',
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
