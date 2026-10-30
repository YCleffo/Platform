import { afterEach, describe, expect, it, vi } from 'vitest';
import type { HttpClientConfig, RefreshOutcome } from './http';
import { createHttpClient } from './http';
import { ApiError, ApiResponseError, NetworkError, RequestAbortedError } from './errors';

function jsonResponse(status: number, body: unknown, headers: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json', ...headers },
  });
}

const unauthorized = () =>
  jsonResponse(401, { error: { code: 'authentication_required', message: 'Authentication required.' } });

const refreshed = (access: string, refresh: string): RefreshOutcome => ({
  status: 'refreshed',
  tokens: { access_token: access, refresh_token: refresh },
});

function makeClient(overrides: Partial<HttpClientConfig> = {}) {
  const onUnauthorized = vi.fn();
  const config: HttpClientConfig = {
    baseUrl: '/api/control',
    getAccessToken: () => 'access-1',
    getSessionEpoch: () => 1,
    refreshTokens: vi.fn(async () => ({ status: 'expired' }) as RefreshOutcome),
    onUnauthorized,
    ...overrides,
  };
  return { client: createHttpClient(config), onUnauthorized };
}

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('http client', () => {
  it('refreshes once and retries the original request with the new token', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(unauthorized())
      .mockResolvedValueOnce(jsonResponse(200, { ok: true }));
    vi.stubGlobal('fetch', fetchMock);

    const refreshTokens = vi.fn(async () => refreshed('access-2', 'r2'));
    const { client } = makeClient({ refreshTokens });

    await expect(client.get<{ ok: boolean }>('/me')).resolves.toEqual({ ok: true });
    expect(refreshTokens).toHaveBeenCalledTimes(1);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    const retryInit = fetchMock.mock.calls[1]?.[1] as RequestInit;
    expect(new Headers(retryInit.headers).get('Authorization')).toBe('Bearer access-2');
  });

  it('shares a single refresh across concurrent 401 responses', async () => {
    let release: (value: RefreshOutcome) => void = () => {};
    const pendingRefresh = new Promise<RefreshOutcome>((resolve) => {
      release = resolve;
    });
    const refreshTokens = vi.fn(() => pendingRefresh);
    const fetchMock = vi.fn(async (_url: string, init?: RequestInit) => {
      const auth = new Headers(init?.headers).get('Authorization');
      return auth === 'Bearer access-1' ? unauthorized() : jsonResponse(200, { ok: true });
    });
    vi.stubGlobal('fetch', fetchMock);

    const { client } = makeClient({ refreshTokens });
    const first = client.get('/a');
    const second = client.get('/b');
    await tick();
    release(refreshed('access-2', 'r2'));
    await Promise.all([first, second]);

    expect(refreshTokens).toHaveBeenCalledTimes(1);
  });

  it('does not refresh a second time when the retry also returns 401', async () => {
    const fetchMock = vi.fn(async () => unauthorized());
    vi.stubGlobal('fetch', fetchMock);
    const refreshTokens = vi.fn(async () => refreshed('access-2', 'r2'));
    const { client } = makeClient({ refreshTokens });

    await expect(client.get('/me')).rejects.toBeInstanceOf(ApiError);
    expect(refreshTokens).toHaveBeenCalledTimes(1);
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('calls onUnauthorized when the refresh is rejected', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => unauthorized()));
    const refreshTokens = vi.fn(async () => ({ status: 'expired' }) as RefreshOutcome);
    const { client, onUnauthorized } = makeClient({ refreshTokens });

    await expect(client.get('/me')).rejects.toMatchObject({ status: 401 });
    expect(onUnauthorized).toHaveBeenCalledTimes(1);
  });

  it('does not log out when the session changed during refresh (stale)', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => unauthorized()));
    const refreshTokens = vi.fn(async () => ({ status: 'stale' }) as RefreshOutcome);
    const { client, onUnauthorized } = makeClient({ refreshTokens });

    await expect(client.get('/me')).rejects.toMatchObject({ status: 401 });
    expect(onUnauthorized).not.toHaveBeenCalled();
  });

  it('surfaces a transient refresh failure without logging out', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => unauthorized()));
    const refreshTokens = vi.fn(
      async () => ({ status: 'unavailable', error: new NetworkError() }) as RefreshOutcome,
    );
    const { client, onUnauthorized } = makeClient({ refreshTokens });

    await expect(client.get('/me')).rejects.toBeInstanceOf(NetworkError);
    expect(onUnauthorized).not.toHaveBeenCalled();
  });

  it('skips refresh when the session epoch changes after the request starts', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => unauthorized()));
    const refreshTokens = vi.fn(async () => refreshed('access-2', 'r2'));
    let reads = 0;
    const { client } = makeClient({
      refreshTokens,
      getSessionEpoch: () => {
        reads += 1;
        return reads === 1 ? 1 : 2;
      },
    });

    await expect(client.get('/me')).rejects.toMatchObject({ status: 401 });
    expect(refreshTokens).not.toHaveBeenCalled();
  });

  it('rejects payloads that fail runtime validation', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(200, { wrong: true })));
    const { client } = makeClient();

    await expect(
      client.get('/me', { validate: (value) => typeof value === 'object' && value !== null && 'id' in value }),
    ).rejects.toBeInstanceOf(ApiResponseError);
  });

  it('returns undefined for 204 responses', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(null, { status: 204 })));
    const { client } = makeClient();
    await expect(client.post('/logout')).resolves.toBeUndefined();
  });

  it('maps aborted requests to RequestAbortedError', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new DOMException('aborted', 'AbortError')));
    const { client } = makeClient();
    await expect(client.get('/me')).rejects.toBeInstanceOf(RequestAbortedError);
  });

  it('exposes Retry-After on rate-limited responses', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        jsonResponse(429, { error: { code: 'rate_limited', message: 'Too many requests.' } }, { 'Retry-After': '42' }),
      ),
    );
    const { client } = makeClient();
    await expect(client.get('/x')).rejects.toMatchObject({ status: 429, retryAfterSeconds: 42 });
  });
});
