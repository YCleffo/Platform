import { ApiResponseError } from '@/shared/api/errors';
import { createHttpClient } from '@/shared/api/http';
import { coreAdminAuth } from '@/shared/api/core/adminAuth';
import { coreAdminSession } from '@/core/admin/session';

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
  getAccessToken: () => coreAdminSession.accessToken(),
  getSessionEpoch: () => coreAdminSession.epoch(),
  refreshTokens: async () => {
    const refreshToken = coreAdminSession.refreshToken();
    if (refreshToken === null) {
      return { status: 'expired' };
    }
    try {
      const tokens = await coreAdminAuth.refresh(refreshToken);
      coreAdminSession.set(tokens);
      return { status: 'refreshed', tokens: { access_token: tokens.accessToken, refresh_token: tokens.refreshToken } };
    } catch {
      coreAdminSession.notifyUnauthorized();
      return { status: 'expired' };
    }
  },
  onUnauthorized: () => {
    coreAdminSession.notifyUnauthorized();
  },
});

export const coreAdminHttp = {
  get<T>(path: string): Promise<T> {
    return client.get<unknown>(path).then(payload => unwrap<T>(payload, path));
  },
  post<T>(path: string, body: unknown = {}): Promise<T> {
    return client.post<unknown>(path, body).then(payload => unwrap<T>(payload, path));
  },
  put<T>(path: string, body: unknown): Promise<T> {
    return client.put<unknown>(path, body).then(payload => unwrap<T>(payload, path));
  },
  patch<T>(path: string, body: unknown): Promise<T> {
    return client.patch<unknown>(path, body).then(payload => unwrap<T>(payload, path));
  },
  delete<T>(path: string): Promise<T> {
    return client.delete<unknown>(path).then(payload => unwrap<T>(payload, path));
  },
};
