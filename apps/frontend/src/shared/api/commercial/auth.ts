import { clearSession, getRefreshToken, setSessionTokens } from '@/core/auth/session';
import type { HttpClient } from '../http';
import type { LoginResult, RegisterResult, SessionTokens, StatusResult } from '@/shared/types';

const REFRESH_PATH = '/api/control/auth/refresh';

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
    register: (email, password) => http.post<RegisterResult>('/auth/register', { email, password }),
    resendVerification: (email) => http.post<StatusResult>('/auth/verification/resend', { email }),
    verifyEmail: (token) => http.post<StatusResult>('/auth/verify', { token }),
    login: (email, password) => http.post<LoginResult>('/auth/login', { email, password }),
    verifyMfaLogin: (challengeToken, input) =>
      http.post<SessionTokens>('/auth/mfa/login/verify', {
        challenge_token: challengeToken,
        code: input.code ?? null,
        recovery_code: input.recoveryCode ?? null,
      }),
    logout: () => http.post<void>('/auth/logout'),
  };
}

export async function refreshAccessToken(): Promise<SessionTokens | null> {
  const refreshToken = getRefreshToken();
  if (refreshToken === null) {
    return null;
  }
  try {
    const response = await fetch(REFRESH_PATH, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ refresh_token: refreshToken }),
    });
    if (response.status === 204) {
      clearSession();
      return null;
    }
    const raw = await response.text();
    const payload = parseJson(raw);
    if (!response.ok || !isSessionTokens(payload)) {
      clearSession();
      return null;
    }
    setSessionTokens(payload);
    return payload;
  } catch {
    clearSession();
    return null;
  }
}

function parseJson(raw: string): unknown {
  if (raw === '') {
    return null;
  }
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

function isSessionTokens(payload: unknown): payload is SessionTokens {
  if (typeof payload !== 'object' || payload === null) {
    return false;
  }
  const tokens = payload as { access_token?: unknown; refresh_token?: unknown };
  return typeof tokens.access_token === 'string' && typeof tokens.refresh_token === 'string';
}
