import { ApiError, ApiResponseError, SupersededOperationError } from '@/shared/api/errors';
import { createHttpClient, type RequestOptions } from '@/shared/api/http';
import { coreAdminAuth } from '@/shared/api/core/adminAuth';
import { coreAdminSession } from '@/core/admin/session';
import { coreClientHeaders } from '@/core/admin/clientInstance';

interface CoreEnvelope {
  status: 'success';
  data: unknown;
}

function unwrap<T>(payload: unknown, path: string): T {
  if (payload === undefined) {
    return undefined as T;
  }
  if (typeof payload !== 'object' || payload === null || (payload as { status?: unknown }).status !== 'success') {
    throw new ApiResponseError(path);
  }
  return (payload as CoreEnvelope).data as T;
}

const client = createHttpClient({
  baseUrl: '/api',
  getClientHeaders: coreClientHeaders,
  getAccessToken: () => coreAdminSession.accessToken(),
  getSessionEpoch: () => coreAdminSession.epoch(),
  refreshTokens: async (expectedEpoch) => {
    const refreshToken = coreAdminSession.refreshToken();
    if (refreshToken === null) {
      return { status: 'expired' };
    }
    try {
      const tokens = await coreAdminAuth.refresh(refreshToken);
      if (!coreAdminSession.rotate(tokens, expectedEpoch, refreshToken)) return { status: 'stale' };
      return { status: 'refreshed', tokens: { access_token: tokens.accessToken, refresh_token: tokens.refreshToken } };
    } catch (error) {
      if (coreAdminSession.epoch() !== expectedEpoch) return { status: 'stale' };
      if (error instanceof ApiError && [401, 403].includes(error.status)) return { status: 'expired' };
      return { status: 'unavailable', error: error instanceof Error ? error : new ApiResponseError('/auth/refresh') };
    }
  },
  onUnauthorized: () => {
    coreAdminSession.notifyUnauthorized();
  },
});

async function scoped<T>(action: () => Promise<T>): Promise<T> {
  const epoch = coreAdminSession.epoch();
  try {
    const result = await action();
    if (epoch !== coreAdminSession.epoch()) throw new SupersededOperationError();
    return result;
  } catch (error) {
    if (epoch !== coreAdminSession.epoch()) throw new SupersededOperationError();
    throw error;
  }
}

export const coreAdminHttp = {
  request<T>(path: string, options: RequestOptions): Promise<T> {
    return scoped(() => client.request<unknown>(path, options).then(payload => unwrap<T>(payload, path)));
  },
  binary(path: string, headers: Record<string, string>, signal?: AbortSignal): Promise<Blob> {
    return scoped(() => client.get<Blob>(path, { responseType: 'blob', headers, signal, retryTransient: false }));
  },
  document(path: string, signal?: AbortSignal): Promise<string> {
    return scoped(() => client.get<string>(path, { responseType: 'text', retryTransient: false, signal }));
  },
  get<T>(path: string, signal?: AbortSignal): Promise<T> {
    return scoped(() => client.get<unknown>(path, { signal }).then(payload => unwrap<T>(payload, path)));
  },
  post<T>(path: string, body: unknown = {}, signal?: AbortSignal): Promise<T> {
    return scoped(() => client.post<unknown>(path, body, { signal }).then(payload => unwrap<T>(payload, path)));
  },
  put<T>(path: string, body: unknown, signal?: AbortSignal): Promise<T> {
    return scoped(() => client.put<unknown>(path, body, { signal }).then(payload => unwrap<T>(payload, path)));
  },
  patch<T>(path: string, body: unknown, signal?: AbortSignal): Promise<T> {
    return scoped(() => client.patch<unknown>(path, body, { signal }).then(payload => unwrap<T>(payload, path)));
  },
  delete<T>(path: string, body?: unknown, signal?: AbortSignal): Promise<T> {
    return scoped(() => client.request<unknown>(path, { method: 'DELETE', body, signal }).then(payload => unwrap<T>(payload, path)));
  },
};
