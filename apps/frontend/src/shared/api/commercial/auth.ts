import type { HttpClient } from '../http';
import {
  isControlSessionList,
  isLoginResult,
  isMfaStatus,
  isRecoveryCodesResult,
  isRegisterResult,
  isSessionTokens,
  isStatusResult,
  isTotpSetup,
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
  login(email: string, password: string): Promise<LoginResult>;
  verifyMfaLogin(challengeToken: string, input: MfaVerificationInput): Promise<SessionTokens>;
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
      http.post<StatusResult>('/auth/verification/resend', { email }, { validate: isStatusResult }),
    verifyEmail: (token) =>
      http.post<StatusResult>('/auth/verify', { token }, { validate: isStatusResult }),
    login: (email, password) =>
      http.post<LoginResult>('/auth/login', { email, password }, { validate: isLoginResult }),
    verifyMfaLogin: (challengeToken, input) =>
      http.post<SessionTokens>(
        '/auth/mfa/login/verify',
        {
          challenge_token: challengeToken,
          code: input.code ?? null,
          recovery_code: input.recoveryCode ?? null,
        },
        { validate: isSessionTokens },
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
