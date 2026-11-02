import type { HttpClient } from '../http';
import {
  isAcceptedStatus,
  isControlSessionList,
  isLoginResult,
  isMfaStatus,
  isPasswordChangedStatus,
  isRecoveryCodesResult,
  isRegisterResult,
  isSessionTokens,
  isTotpSetup,
  isVerifiedStatus,
} from '../guards';
import type {
  ControlSessionList,
  LoginResult,
  MfaStatus,
  ReauthenticateInput,
  RecoveryCodesResult,
  RegisterResult,
  SessionTokens,
  StatusResult,
  TotpSetup,
} from '@/shared/types';

export interface MfaVerificationInput {
  code?: string;
  recoveryCode?: string;
}

export interface CommercialAuthApi {
  register(email: string, password: string): Promise<RegisterResult>;
  resendVerification(email: string): Promise<StatusResult>;
  verifyEmail(token: string): Promise<StatusResult>;
  requestPasswordReset(email: string): Promise<StatusResult>;
  confirmPasswordReset(token: string, password: string): Promise<StatusResult>;
  changePassword(currentPassword: string, password: string): Promise<StatusResult>;
  regenerateRecoveryCodes(): Promise<RecoveryCodesResult>;
  disableMfa(): Promise<void>;
  revokeSession(sessionId: string): Promise<void>;
  revokeOtherSessions(): Promise<void>;
  login(email: string, password: string, signal?: AbortSignal): Promise<LoginResult>;
  verifyMfaLogin(
    challengeToken: string,
    input: MfaVerificationInput,
    signal?: AbortSignal,
  ): Promise<SessionTokens>;
  /** Sends the logout request with an explicit snapshot of the token being revoked. */
  logout(token: string): Promise<void>;
  mfaStatus(): Promise<MfaStatus>;
  beginTotpSetup(): Promise<TotpSetup>;
  confirmTotpSetup(factorId: string, code: string): Promise<RecoveryCodesResult>;
  reauthenticate(input: ReauthenticateInput): Promise<void>;
  sessions(): Promise<ControlSessionList>;
}

export function createCommercialAuthApi(http: HttpClient): CommercialAuthApi {
  return {
    register: (email, password) =>
      http.post<RegisterResult>('/auth/register', { email, password }, { validate: isRegisterResult }),
    resendVerification: (email) =>
      http.post<StatusResult>('/auth/verification/resend', { email }, { validate: isAcceptedStatus }),
    verifyEmail: (token) =>
      http.post<StatusResult>('/auth/verify', { token }, { validate: isVerifiedStatus }),
    requestPasswordReset: (email) =>
      http.post<StatusResult>(
        '/auth/password/reset/request',
        { email },
        { validate: isAcceptedStatus },
      ),
    confirmPasswordReset: (token, password) =>
      http.post<StatusResult>(
        '/auth/password/reset/confirm',
        { token, password },
        { validate: isPasswordChangedStatus },
      ),
    changePassword: (currentPassword, password) =>
      http.post<StatusResult>(
        '/auth/password/change',
        { current_password: currentPassword, password },
        { validate: isPasswordChangedStatus },
      ),
    regenerateRecoveryCodes: () =>
      http.post<RecoveryCodesResult>('/auth/mfa/recovery-codes/regenerate', {}, { validate: isRecoveryCodesResult }),
    disableMfa: () => http.post<void>('/auth/mfa/disable', {}),
    revokeSession: (sessionId) =>
      http.delete<void>(`/auth/sessions/${encodeURIComponent(sessionId)}`),
    revokeOtherSessions: () => http.post<void>('/auth/sessions/revoke-others', {}),
    login: (email, password, signal) =>
      http.post<LoginResult>('/auth/login', { email, password }, { validate: isLoginResult, signal }),
    verifyMfaLogin: (challengeToken, input, signal) =>
      http.post<SessionTokens>(
        '/auth/mfa/login/verify',
        {
          challenge_token: challengeToken,
          code: input.code ?? null,
          recovery_code: input.recoveryCode ?? null,
        },
        { validate: isSessionTokens, signal },
      ),
    logout: (token) =>
      http.post<void>('/auth/logout', undefined, {
        headers: { Authorization: `Bearer ${token}` },
      }),
    mfaStatus: () => http.get<MfaStatus>('/auth/mfa/status', { validate: isMfaStatus }),
    beginTotpSetup: () =>
      http.post<TotpSetup>('/auth/mfa/totp/setup', {}, { validate: isTotpSetup }),
    confirmTotpSetup: (factorId, code) =>
      http.post<RecoveryCodesResult>(
        '/auth/mfa/totp/confirm',
        { factor_id: factorId, code },
        { validate: isRecoveryCodesResult },
      ),
    reauthenticate: (input) =>
      http.post<void>('/auth/reauthenticate', {
        password: input.password,
        code: input.code ?? null,
        recovery_code: input.recoveryCode ?? null,
      }),
    sessions: () => http.get<ControlSessionList>('/auth/sessions', { validate: isControlSessionList }),
  };
}
