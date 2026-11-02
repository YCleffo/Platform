import { ApiError, ApiResponseError } from '@/shared/api/errors';
import type { CoreAdminTokens } from '@/core/admin/session';

export interface CoreAdminIdentity {
  account: { id: string; personId: string | null };
  roles: string[];
  permissions: string[];
  locale: string | null;
  timezone: string | null;
  sessionId: string;
}

export interface CoreAdminLoginResult extends Partial<CoreAdminTokens> {
  status: 'authenticated' | 'mfa_required' | 'password_change_required';
  challengeToken?: string;
  allowedFactors?: string[];
}

interface CoreEnvelope<T> {
  status: 'success';
  data: T;
}

async function request<T>(path: string, init: RequestInit): Promise<T> {
  let response: Response;
  try {
    response = await fetch(`/api${path}`, init);
  } catch {
    throw new ApiError(0, 'network_error', 'The network request could not be completed.');
  }
  if (response.status === 204) {
    return undefined as T;
  }
  let payload: unknown = null;
  try {
    payload = await response.json();
  } catch {
    throw new ApiResponseError(path);
  }
  if (!response.ok) {
    const error = typeof payload === 'object' && payload !== null && 'error' in payload
      ? (payload as { error?: { code?: unknown; message?: unknown } }).error
      : undefined;
    throw new ApiError(
      response.status,
      typeof error?.code === 'string' ? error.code : 'http_error',
      typeof error?.message === 'string' ? error.message : 'The request failed.',
    );
  }
  if (typeof payload !== 'object' || payload === null || (payload as { status?: unknown }).status !== 'success') {
    throw new ApiResponseError(path);
  }
  return (payload as CoreEnvelope<T>).data;
}

function jsonInit(method: string, body: unknown, token?: string): RequestInit {
  const headers = new Headers({ Accept: 'application/json', 'Content-Type': 'application/json' });
  if (token !== undefined) {
    headers.set('Authorization', `Bearer ${token}`);
  }
  return { method, headers, body: JSON.stringify(body) };
}

export const coreAdminAuth = {
  login(identifier: string, password: string): Promise<CoreAdminLoginResult> {
    return request<CoreAdminLoginResult>(
      '/auth/login',
      jsonInit('POST', { identifierType: 'email', identifier, password }),
    );
  },
  verifyMfa(challengeToken: string, code: string | null, recoveryCode: string | null): Promise<CoreAdminLoginResult> {
    return request<CoreAdminLoginResult>(
      '/auth/mfa/verify',
      jsonInit('POST', { challengeToken, totpCode: code, recoveryCode }),
    );
  },
  me(accessToken: string): Promise<CoreAdminIdentity> {
    return request<CoreAdminIdentity>('/auth/me', {
      method: 'GET',
      headers: { Accept: 'application/json', Authorization: `Bearer ${accessToken}` },
    });
  },
  refresh(refreshToken: string): Promise<CoreAdminTokens> {
    return request<CoreAdminTokens>('/auth/refresh', jsonInit('POST', { refreshToken }));
  },
  logout(accessToken: string): Promise<void> {
    return request<void>('/auth/logout', {
      method: 'POST',
      headers: { Accept: 'application/json', Authorization: `Bearer ${accessToken}` },
    });
  },
};
