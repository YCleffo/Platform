import type { HttpClient } from '../http';
import { isLoginResult, isRegisterResult, isSessionTokens, isStatusResult } from '../guards';
import type { LoginResult, RegisterResult, SessionTokens, StatusResult } from '@/shared/types';

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
  logout(): Promise<void>;
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
    logout: () => http.post<void>('/auth/logout'),
  };
}
