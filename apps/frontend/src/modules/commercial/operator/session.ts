import { ref } from 'vue';
import { createHttpClient } from '@/shared/api/http';
import { ApiError, ApiResponseError, SupersededOperationError } from '@/shared/api/errors';
import type { SessionTokens } from '@/shared/types';
export interface OperatorIdentity {
  user: { id: string; email: string };
  privacy: boolean;
  finance: boolean;
}
// An independent memory-only session. Never import customer/Core stores or their tokens here.
const tokens = ref<SessionTokens | null>(null);
export const operatorIdentity = ref<OperatorIdentity | null>(null);
export const operatorEpoch = ref(0);
export const operatorChallenge = ref('');
function isTokens(value: unknown): value is SessionTokens {
  return (
    !!value &&
    typeof value === 'object' &&
    typeof (value as SessionTokens).access_token === 'string' &&
    typeof (value as SessionTokens).refresh_token === 'string'
  );
}
export function clearOperator(): void {
  operatorEpoch.value++;
  tokens.value = null;
  operatorIdentity.value = null;
  operatorChallenge.value = '';
}
async function auth(path: string, body: unknown, signal?: AbortSignal): Promise<unknown> {
  const response = await fetch('/api/control/auth' + path, {
    method: 'POST',
    credentials: 'omit',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
    signal,
  });
  let value: unknown;
  try {
    value = await response.json();
  } catch {
    throw new ApiResponseError(path);
  }
  if (!response.ok) {
    const record =
      typeof value === 'object' && value !== null ? (value as Record<string, unknown>) : {};
    const error =
      typeof record.error === 'object' && record.error !== null
        ? (record.error as Record<string, unknown>)
        : record;
    throw new ApiError(
      response.status,
      typeof error.code === 'string' ? error.code : 'request_failed',
      typeof error.message === 'string' ? error.message : 'Operator authentication failed.',
    );
  }
  return value;
}
const rawOperatorHttp = createHttpClient({
  baseUrl: '/api/control',
  credentials: 'omit',
  getAccessToken: () => tokens.value?.access_token ?? null,
  getSessionEpoch: () => operatorEpoch.value,
  onUnauthorized: clearOperator,
  refreshTokens: async (expected) => {
    const refresh = tokens.value?.refresh_token;
    if (!refresh) return { status: 'expired' };
    try {
      const value = await auth('/refresh', { refresh_token: refresh });
      if (expected !== operatorEpoch.value || tokens.value?.refresh_token !== refresh)
        return { status: 'stale' };
      if (!isTokens(value)) throw new ApiResponseError('/auth/refresh');
      tokens.value = value;
      return { status: 'refreshed', tokens: value };
    } catch (error) {
      if (expected !== operatorEpoch.value) return { status: 'stale' };
      if (error instanceof ApiError && [401, 403].includes(error.status))
        return { status: 'expired' };
      return {
        status: 'unavailable',
        error: error instanceof Error ? error : new Error('refresh'),
      };
    }
  },
});
export const operatorHttp = new Proxy(rawOperatorHttp, {
  get(target, key: keyof typeof rawOperatorHttp) {
    const method = target[key];
    return async (...args: unknown[]) => {
      const epoch = operatorEpoch.value;
      try {
        const result = await Reflect.apply(method, target, args);
        if (epoch !== operatorEpoch.value) throw new SupersededOperationError();
        return result;
      } catch (error) {
        if (epoch !== operatorEpoch.value) throw new SupersededOperationError();
        if (key !== 'get' || args[0] !== '/operator/me') {
          if (error instanceof ApiError && [403, 404].includes(error.status)
            && !['recent_authentication_required', 'recent_mfa_required', 'mfa_enrollment_required'].includes(error.code)) {
            try {
              const current = await rawOperatorHttp.get<OperatorIdentity>('/operator/me', { retryTransient: false });
              const previous = operatorIdentity.value;
              if (epoch === operatorEpoch.value && previous && current.user?.id === previous.user.id
                && typeof current.privacy === 'boolean' && typeof current.finance === 'boolean') {
                if (!current.privacy && !current.finance) clearOperator();
                else if (current.privacy !== previous.privacy || current.finance !== previous.finance) operatorIdentity.value = current;
              }
            } catch (denial) {
              if (epoch === operatorEpoch.value && denial instanceof ApiError && [401, 403].includes(denial.status)) clearOperator();
            }
          }
        }
        if (epoch !== operatorEpoch.value) throw new SupersededOperationError();
        throw error;
      }
    };
  },
});
async function revokeOperatorToken(token: string): Promise<void> {
  const response = await fetch('/api/control/auth/logout', {
    method: 'POST',
    credentials: 'omit',
    headers: { Authorization: 'Bearer ' + token, 'Content-Type': 'application/json' },
    body: '{}',
  });
  if (!response.ok) throw new ApiError(response.status, 'logout_failed', 'Operator logout failed.');
}
async function establish(value: unknown, epoch: number): Promise<void> {
  if (epoch !== operatorEpoch.value) throw new SupersededOperationError();
  if (!isTokens(value)) throw new ApiResponseError('/auth/login');
  tokens.value = value;
  try {
    const identity = await operatorHttp.get<OperatorIdentity>('/operator/me', {
      retryTransient: false,
    });
    if (epoch !== operatorEpoch.value) throw new SupersededOperationError();
    if (
      !identity?.user?.id ||
      typeof identity.user.email !== 'string' ||
      typeof identity.privacy !== 'boolean' ||
      typeof identity.finance !== 'boolean' ||
      (!identity.privacy && !identity.finance)
    )
      throw new ApiResponseError('/operator/me');
    operatorIdentity.value = identity;
    operatorChallenge.value = '';
  } catch (error) {
    if (epoch === operatorEpoch.value) {
      const token = tokens.value?.access_token;
      clearOperator();
      if (token) {
        try {
          await revokeOperatorToken(token);
        } catch {
          /* Revoke this failed operator attempt only. */
        }
      }
    }
    throw error;
  }
}
export async function loginOperator(email: string, password: string): Promise<void> {
  clearOperator();
  const epoch = operatorEpoch.value;
  const value = await auth('/login', { email, password });
  if (epoch !== operatorEpoch.value) throw new SupersededOperationError();
  if (
    value &&
    typeof value === 'object' &&
    (value as { status?: string }).status === 'mfa_required'
  ) {
    const token = (value as { challenge_token?: string }).challenge_token;
    if (!token) throw new ApiResponseError('/auth/login');
    operatorChallenge.value = token;
    return;
  }
  await establish(value, epoch);
}
export async function verifyOperator(code: string, recovery: boolean): Promise<void> {
  const epoch = operatorEpoch.value;
  const value = await auth('/mfa/login/verify', {
    challenge_token: operatorChallenge.value,
    ...(recovery ? { recovery_code: code } : { code }),
  });
  await establish(value, epoch);
}
export async function logoutOperator(): Promise<void> {
  const token = tokens.value?.access_token;
  clearOperator();
  if (token) await revokeOperatorToken(token);
}
