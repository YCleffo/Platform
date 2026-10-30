import { ApiError, NetworkError, RequestAbortedError } from './errors';
import type { ApiErrorEnvelope, SessionTokens } from '@/shared/types';

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export interface RequestOptions {
  method?: HttpMethod;
  body?: unknown;
  query?: Record<string, string | number | undefined>;
  headers?: Record<string, string>;
  signal?: AbortSignal;
  idempotencyKey?: string;
}

type ReadOptions = Omit<RequestOptions, 'method' | 'body'>;

export interface HttpClient {
  request<T>(path: string, options?: RequestOptions): Promise<T>;
  get<T>(path: string, options?: ReadOptions): Promise<T>;
  post<T>(path: string, body?: unknown, options?: ReadOptions): Promise<T>;
  put<T>(path: string, body?: unknown, options?: ReadOptions): Promise<T>;
  patch<T>(path: string, body?: unknown, options?: ReadOptions): Promise<T>;
  delete<T>(path: string, options?: ReadOptions): Promise<T>;
}

export interface HttpClientConfig {
  baseUrl: string;
  getAccessToken: () => string | null;
  refreshTokens: () => Promise<SessionTokens | null>;
  onUnauthorized: () => void;
}

export function createHttpClient(config: HttpClientConfig): HttpClient {
  let refreshInFlight: Promise<SessionTokens | null> | null = null;

  function refreshOnce(): Promise<SessionTokens | null> {
    if (refreshInFlight === null) {
      refreshInFlight = config.refreshTokens().finally(() => {
        refreshInFlight = null;
      });
    }
    return refreshInFlight;
  }

  async function send<T>(path: string, options: RequestOptions, token: string | null): Promise<T> {
    const method = options.method ?? 'GET';
    const url = buildUrl(config.baseUrl, path, options.query);
    const headers = new Headers({ Accept: 'application/json' });
    if (options.body !== undefined) {
      headers.set('Content-Type', 'application/json');
    }
    if (options.idempotencyKey !== undefined) {
      headers.set('Idempotency-Key', options.idempotencyKey);
    }
    if (token !== null) {
      headers.set('Authorization', `Bearer ${token}`);
    }
    for (const [key, value] of Object.entries(options.headers ?? {})) {
      headers.set(key, value);
    }

    const body = options.body === undefined ? undefined : JSON.stringify(options.body);

    let response: Response;
    try {
      response = await fetch(url, { method, headers, body, signal: options.signal });
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') {
        throw new RequestAbortedError();
      }
      throw new NetworkError();
    }

    if (response.status === 204) {
      return undefined as T;
    }

    const raw = await response.text();
    const payload = parseJson(raw);

    if (!response.ok) {
      throw toApiError(response.status, payload, response.headers.get('Retry-After'));
    }

    return payload as T;
  }

  async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
    const token = config.getAccessToken();
    try {
      return await send<T>(path, options, token);
    } catch (error) {
      if (error instanceof ApiError && error.status === 401 && token !== null) {
        const tokens = await refreshOnce();
        if (tokens !== null) {
          return await send<T>(path, options, tokens.access_token);
        }
        config.onUnauthorized();
        throw error;
      }
      throw error;
    }
  }

  return {
    request,
    get<T>(path: string, options: ReadOptions = {}) {
      return request<T>(path, { ...options, method: 'GET' });
    },
    post<T>(path: string, body?: unknown, options: ReadOptions = {}) {
      return request<T>(path, { ...options, method: 'POST', body });
    },
    put<T>(path: string, body?: unknown, options: ReadOptions = {}) {
      return request<T>(path, { ...options, method: 'PUT', body });
    },
    patch<T>(path: string, body?: unknown, options: ReadOptions = {}) {
      return request<T>(path, { ...options, method: 'PATCH', body });
    },
    delete<T>(path: string, options: ReadOptions = {}) {
      return request<T>(path, { ...options, method: 'DELETE' });
    },
  };
}

function buildUrl(baseUrl: string, path: string, query?: RequestOptions['query']): string {
  const base = baseUrl.endsWith('/') ? baseUrl.slice(0, -1) : baseUrl;
  const suffix = path.startsWith('/') ? path : `/${path}`;
  const url = new URL(base + suffix, window.location.origin);
  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== '') {
      url.searchParams.set(key, String(value));
    }
  }
  return url.toString();
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

function toApiError(status: number, payload: unknown, retryAfterHeader: string | null): ApiError {
  const envelope = isErrorEnvelope(payload) ? payload : null;
  const code = envelope?.error.code ?? 'http_error';
  const message = envelope?.error.message ?? `The request failed with status ${status}.`;
  return new ApiError(status, code, message, parseRetryAfter(retryAfterHeader));
}

function isErrorEnvelope(payload: unknown): payload is ApiErrorEnvelope {
  if (typeof payload !== 'object' || payload === null) {
    return false;
  }
  const error = (payload as { error?: unknown }).error;
  if (typeof error !== 'object' || error === null) {
    return false;
  }
  const code = (error as { code?: unknown }).code;
  const message = (error as { message?: unknown }).message;
  return typeof code === 'string' && typeof message === 'string';
}

function parseRetryAfter(header: string | null): number | null {
  if (header === null || header === '') {
    return null;
  }
  const seconds = Number(header);
  return Number.isFinite(seconds) && seconds >= 0 ? Math.floor(seconds) : null;
}
