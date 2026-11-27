import { ApiError, ApiResponseError, NetworkError, RequestAbortedError } from './errors';
import type { ApiErrorEnvelope, SessionTokens } from '@/shared/types';

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

/** Runtime guard applied at the API boundary before a payload is trusted. */
export type Validator = (value: unknown) => boolean;

export interface RequestOptions {
  method?: HttpMethod;
  body?: unknown;
  query?: Record<string, string | number | undefined>;
  headers?: Record<string, string>;
  signal?: AbortSignal;
  idempotencyKey?: string;
  validate?: Validator;
  /** Stateful GET responses, such as one-time privacy exports, must not be replayed. */
  retryTransient?: boolean;
  responseType?: 'json' | 'text' | 'blob';
}

type ReadOptions = Omit<RequestOptions, 'method' | 'body'>;

export type RefreshOutcome =
  | { status: 'refreshed'; tokens: SessionTokens }
  | { status: 'stale' }
  | { status: 'expired' }
  | { status: 'unavailable'; error: Error };

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
  credentials?: RequestCredentials;
  getClientHeaders?: () => Record<string, string>;
  getAccessToken: () => string | null;
  getSessionEpoch: () => number;
  refreshTokens: (expectedEpoch: number) => Promise<RefreshOutcome>;
  onUnauthorized: () => void;
}

export function createHttpClient(config: HttpClientConfig): HttpClient {
  let refreshInFlight: { epoch: number; promise: Promise<RefreshOutcome> } | null = null;

  function refreshOnce(epoch: number): Promise<RefreshOutcome> {
    if (refreshInFlight !== null && refreshInFlight.epoch === epoch) {
      return refreshInFlight.promise;
    }
    const promise = config.refreshTokens(epoch).finally(() => {
      if (refreshInFlight !== null && refreshInFlight.promise === promise) {
        refreshInFlight = null;
      }
    });
    refreshInFlight = { epoch, promise };
    return promise;
  }

  async function send<T>(path: string, options: RequestOptions, token: string | null): Promise<T> {
    const method = options.method ?? 'GET';
    const url = buildUrl(config.baseUrl, path, options.query);
    const headers = new Headers({ Accept: 'application/json', ...config.getClientHeaders?.() });
    const multipart = options.body instanceof FormData;
    const isBinary = typeof Blob !== 'undefined' && options.body instanceof Blob
      || typeof ArrayBuffer !== 'undefined' && options.body instanceof ArrayBuffer;
    if (options.body !== undefined && !multipart && !isBinary) {
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

    const body = multipart || isBinary ? (options.body as BodyInit) : options.body === undefined ? undefined : JSON.stringify(options.body);

    let response: Response | undefined;
    for (let attempt = 1; response === undefined; attempt += 1) {
      try {
        response = await fetch(url, { method, headers, body, signal: options.signal, credentials: config.credentials ?? 'same-origin', cache: 'no-store' });
      } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
          throw new RequestAbortedError();
        }
        if (method === 'GET' && options.retryTransient !== false && attempt === 1 && options.signal?.aborted !== true) {
          await new Promise(resolve => setTimeout(resolve, 100));
          continue;
        }
        throw new NetworkError();
      }
      if (method === 'GET' && options.retryTransient !== false && attempt === 1 && [502, 503, 504].includes(response.status) && options.signal?.aborted !== true) {
        response = undefined;
        await new Promise(resolve => setTimeout(resolve, 100));
        continue;
      }
    }

    if (response.status === 204) {
      if (options.validate !== undefined) {
        throw new ApiResponseError(path);
      }
      return undefined as T;
    }

    if (response.ok && options.responseType === 'blob') {
      const blob = await response.blob();
      if (options.validate !== undefined && !options.validate(blob)) throw new ApiResponseError(path);
      return blob as T;
    }
    const raw = await response.text();
    const payload = parseJson(raw);

    if (!response.ok) {
      throw toApiError(response.status, payload, response.headers.get('Retry-After'));
    }

    if (options.validate !== undefined && !options.validate(payload)) {
      throw new ApiResponseError(path);
    }

    return (options.responseType === 'text' ? raw : payload) as T;
  }

  async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
    const startEpoch = config.getSessionEpoch();
    const token = config.getAccessToken();
    try {
      return await send<T>(path, options, token);
    } catch (error) {
      if (!(error instanceof ApiError) || error.status !== 401 || token === null) {
        throw error;
      }
      if (config.getSessionEpoch() !== startEpoch) {
        // Session was replaced or cleared while the request was in flight.
        throw error;
      }
      const outcome = await refreshOnce(startEpoch);
      if (outcome.status === 'refreshed') {
        if (config.getSessionEpoch() !== startEpoch) {
          throw error;
        }
        try {
          return await send<T>(path, options, outcome.tokens.access_token);
        } catch (retryError) {
          // The refreshed token is still rejected: the session is unusable.
          if (
            retryError instanceof ApiError &&
            retryError.status === 401 &&
            config.getSessionEpoch() === startEpoch
          ) {
            config.onUnauthorized();
          }
          throw retryError;
        }
      }
      if (outcome.status === 'expired') {
        if (config.getSessionEpoch() === startEpoch) {
          config.onUnauthorized();
        }
        throw error;
      }
      if (outcome.status === 'unavailable') {
        throw outcome.error;
      }
      // 'stale': session changed on purpose; do not resurrect it.
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
  if (envelope !== null) {
    const meta = envelope.error.meta;
    return new ApiError(
      status,
      envelope.error.code,
      envelope.error.message,
      parseRetryAfter(retryAfterHeader),
      typeof meta === 'object' && meta !== null && !Array.isArray(meta)
        ? (meta as Record<string, unknown>)
        : undefined,
    );
  }
  if (typeof payload === 'object' && payload !== null && !Array.isArray(payload)) {
    const obj = payload as Record<string, unknown>;
    if (typeof obj.error === 'string' && typeof obj.message === 'string') {
      return new ApiError(status, obj.error, obj.message, parseRetryAfter(retryAfterHeader), obj);
    }
  }
  return new ApiError(status, 'http_error', 'The request failed.', parseRetryAfter(retryAfterHeader));
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
