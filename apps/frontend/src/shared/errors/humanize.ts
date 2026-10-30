import { ApiError, NetworkError, RequestAbortedError } from '@/shared/api/errors';

const CODE_KEYS: Record<string, string> = {
  invalid_credentials: 'auth.invalidCredentials',
  mfa_code_invalid: 'auth.mfaInvalid',
  mfa_challenge_invalid: 'auth.mfaInvalid',
  mfa_method_required: 'auth.mfaInvalid',
  rate_limited: 'errors.rate_limited',
  email_unavailable: 'errors.email_unavailable',
  validation_failed: 'errors.validation_failed',
  request_too_large: 'errors.request_too_large',
};

export function toErrorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message;
  }
  if (error instanceof NetworkError) {
    return error.message;
  }
  if (error instanceof RequestAbortedError) {
    return '';
  }
  return 'An unexpected error occurred.';
}

export function errorCodeKey(error: unknown): string | null {
  if (error instanceof ApiError) {
    return CODE_KEYS[error.code] ?? null;
  }
  return null;
}
