import { ApiError, ApiResponseError, NetworkError, RequestAbortedError } from '@/shared/api/errors';
import type { CoreAdminTokens } from '@/core/admin/session';
import { coreClientHeaders } from '@/core/admin/clientInstance';
import { i18n } from '@/shared/i18n';
import { isRequestOptions, type RequestOptionsJson } from '@/shared/webauthn';

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

export type CoreMfaRecoveryResult =
  | { status: 'completed' | 'additional_recovery_required' }
  | { status: 'external_reauth_required'; recoveryToken: string; providers: string[]; expiresAt: string };

export type CoreExternalProvider = 'google' | 'telegram';
export interface CoreExternalStart { authorizationUrl: string; expiresAt: string }

function object(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

function expectData<T>(value: unknown, valid: (data: unknown) => boolean, path: string): T {
  if (!valid(value)) throw new ApiResponseError(path);
  return value as T;
}

function authentication(value: unknown): CoreAdminLoginResult {
  return expectData(value, data => {
    if (!object(data)) return false;
    if (data.status === 'mfa_required' || data.status === 'password_change_required') {
      return typeof data.challengeToken === 'string' && data.challengeToken !== '';
    }
    return data.status === 'authenticated' && validTokens(data);
  }, '/auth');
}

function validTokens(data: unknown): boolean {
  return object(data) && ['accessToken', 'refreshToken', 'accessExpiresAt', 'sessionExpiresAt', 'sessionId']
    .every(key => typeof data[key] === 'string' && data[key] !== '');
}

interface CoreEnvelope<T> {
  status: 'success';
  data: T;
}

async function request<T>(path: string, init: RequestInit): Promise<T> {
  const headers = new Headers(init.headers);
  for (const [key, value] of Object.entries(coreClientHeaders())) headers.set(key, value);
  let response: Response;
  try {
    response = await fetch(`/api${path}`, { ...init, headers });
  } catch {
    if (init.signal?.aborted) throw new RequestAbortedError();
    throw new NetworkError();
  }
  if (init.signal?.aborted) throw new RequestAbortedError();
  if (response.status === 204) {
    return undefined as T;
  }
  let payload: unknown = null;
  try {
    payload = await response.json();
  } catch {
    if (init.signal?.aborted) throw new RequestAbortedError();
    throw new ApiResponseError(path);
  }
  if (init.signal?.aborted) throw new RequestAbortedError();
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
  if (!object(payload) || payload.status !== 'success' || !('data' in payload)) {
    throw new ApiResponseError(path);
  }
  return (payload as unknown as CoreEnvelope<T>).data;
}

function jsonInit(method: string, body: unknown, token?: string): RequestInit {
  const headers = new Headers({ Accept: 'application/json', 'Content-Type': 'application/json',
    'X-Client-Locale': i18n.global.locale.value === 'ru' ? 'ru-RU' : 'en-US' });
  if (token !== undefined) {
    headers.set('Authorization', `Bearer ${token}`);
  }
  return { method, headers, body: JSON.stringify(body) };
}

export const coreAdminAuth = {
  async startPasskeyAuthentication(signal?: AbortSignal): Promise<RequestOptionsJson> {
    const path = '/auth/passkeys/authentication/start';
    return expectData(await request(path, { ...jsonInit('POST', {}), signal }), isRequestOptions, path);
  },
  completePasskeyAuthentication(credential: Record<string, unknown>, signal?: AbortSignal): Promise<CoreAdminLoginResult> {
    return request('/auth/passkeys/authentication/complete', { ...jsonInit('POST', { credential }), signal }).then(authentication);
  },
  async providers(signal?: AbortSignal): Promise<CoreExternalProvider[]> {
    const path = '/auth/providers';
    const data = expectData<{ items: Array<{ code: string; loginEnabled: boolean }> }>(await request(path, { method: 'GET', signal }),
      value => object(value) && Array.isArray(value.items) && value.items.every(item => object(item) && typeof item.code === 'string' && typeof item.loginEnabled === 'boolean'), path);
    return data.items.filter(item => item.loginEnabled).map(item => item.code).filter((code): code is CoreExternalProvider => code === 'google' || code === 'telegram');
  },
  async startExternalLogin(provider: CoreExternalProvider, signal?: AbortSignal): Promise<CoreExternalStart> {
    const path = `/auth/external/${provider}/start`;
    return expectData(await request(path, { ...jsonInit('POST', {}), signal }), value => object(value)
      && typeof value.authorizationUrl === 'string' && typeof value.expiresAt === 'string', path);
  },
  completeExternalLogin(provider: CoreExternalProvider, state: string, code: string, signal?: AbortSignal): Promise<CoreAdminLoginResult> {
    return request(`/auth/external/${provider}/callback?${new URLSearchParams({ state, code })}`, { method: 'GET', signal }).then(authentication);
  },
  login(identifier: string, password: string, signal?: AbortSignal): Promise<CoreAdminLoginResult> {
    return request<CoreAdminLoginResult>(
      '/auth/login',
      { ...jsonInit('POST', { identifierType: 'email', identifier, password }), signal },
    ).then(authentication);
  },
  verifyMfa(challengeToken: string, code: string | null, recoveryCode: string | null, signal?: AbortSignal): Promise<CoreAdminLoginResult> {
    return request<CoreAdminLoginResult>(
      '/auth/mfa/verify',
      { ...jsonInit('POST', { challengeToken, totpCode: code, recoveryCode }), signal },
    ).then(authentication);
  },
  completeRequiredPasswordChange(challengeToken: string, currentPassword: string, newPassword: string, signal?: AbortSignal): Promise<CoreAdminLoginResult> {
    return request('/auth/password/change-required', {
      ...jsonInit('POST', { challengeToken, currentPassword, newPassword }), signal,
    }).then(authentication);
  },
  async activate(token: string, password: string, signal?: AbortSignal): Promise<void> {
    const path = '/auth/activate';
    expectData(await request(path, { ...jsonInit('POST', { token, password }), signal }),
      data => object(data) && data.activated === true, path);
  },
  async requestPasswordReset(email: string, signal?: AbortSignal): Promise<void> {
    const path = '/auth/password-reset/request';
    expectData(await request(path, { ...jsonInit('POST', { email }), signal }),
      data => object(data) && data.accepted === true, path);
  },
  async confirmPasswordReset(token: string, password: string, signal?: AbortSignal): Promise<void> {
    const path = '/auth/password-reset/confirm';
    expectData(await request(path, { ...jsonInit('POST', { token, password }), signal }),
      data => object(data) && data.completed === true, path);
  },
  async requestMfaRecovery(email: string, signal?: AbortSignal): Promise<void> {
    const path = '/auth/mfa/recovery/request';
    expectData(await request(path, { ...jsonInit('POST', { email }), signal }),
      data => object(data) && data.status === 'accepted', path);
  },
  async confirmMfaRecovery(token: string, password: string | null, signal?: AbortSignal): Promise<CoreMfaRecoveryResult> {
    const path = '/auth/mfa/recovery/confirm';
    return expectData(await request(path, { ...jsonInit('POST', { token, password }), signal }), data =>
      object(data) && (data.status === 'completed' || data.status === 'additional_recovery_required'
        || (data.status === 'external_reauth_required' && typeof data.recoveryToken === 'string'
          && data.recoveryToken !== '' && Array.isArray(data.providers) && data.providers.length > 0
          && data.providers.every(provider => typeof provider === 'string') && typeof data.expiresAt === 'string')), path);
  },
  async startExternalMfaRecovery(provider: CoreExternalProvider, recoveryToken: string, signal?: AbortSignal): Promise<CoreExternalStart> {
    const path = `/auth/mfa/recovery/external/${provider}/start`;
    return expectData(await request(path, { ...jsonInit('POST', { recoveryToken }), signal }), data =>
      object(data) && typeof data.authorizationUrl === 'string' && typeof data.expiresAt === 'string', path);
  },
  async completeExternalMfaRecovery(provider: CoreExternalProvider, state: string, code: string, signal?: AbortSignal): Promise<void> {
    const query = new URLSearchParams({ state, code });
    const path = `/auth/mfa/recovery/external/${provider}/callback`;
    expectData(await request(`${path}?${query}`, { method: 'GET', headers: { Accept: 'application/json' }, signal }),
      data => object(data) && data.status === 'completed', path);
  },
  me(accessToken: string, signal?: AbortSignal): Promise<CoreAdminIdentity> {
    return request<CoreAdminIdentity>('/auth/me', {
      method: 'GET',
      headers: { Accept: 'application/json', Authorization: `Bearer ${accessToken}` },
      signal,
    }).then(data => expectData(data, value => object(value) && object(value.account)
      && typeof value.account.id === 'string' && (value.account.personId === null || typeof value.account.personId === 'string')
      && Array.isArray(value.roles) && value.roles.every(role => typeof role === 'string')
      && Array.isArray(value.permissions) && value.permissions.every(permission => typeof permission === 'string')
      && typeof value.sessionId === 'string', '/auth/me'));
  },
  refresh(refreshToken: string): Promise<CoreAdminTokens> {
    return request<CoreAdminTokens>('/auth/refresh', jsonInit('POST', { refreshToken }))
      .then(data => expectData(data, validTokens, '/auth/refresh'));
  },
  logout(accessToken: string): Promise<void> {
    return request<void>('/auth/logout', {
      method: 'POST',
      headers: { Accept: 'application/json', Authorization: `Bearer ${accessToken}` },
    });
  },
};
