import { coreAdminHttp } from '@/shared/api/core/adminHttp';

const cache = new Map<string, unknown>();
const inFlight = new Map<string, Promise<unknown>>();

export function peekAdminCache<T>(path: string): T | undefined {
  return cache.get(path) as T | undefined;
}

export function primeAdminCache(path: string, value: unknown): void {
  cache.set(path, value);
}

export async function cachedAdminGet<T>(path: string, force = false): Promise<T> {
  if (!force && cache.has(path)) {
    return cache.get(path) as T;
  }
  const current = inFlight.get(path);
  if (current !== undefined) {
    return current as Promise<T>;
  }
  const request = coreAdminHttp.get<T>(path)
    .then((data) => {
      cache.set(path, data);
      return data;
    })
    .finally(() => {
      inFlight.delete(path);
    });
  inFlight.set(path, request);
  return request;
}

export function clearAdminCache(): void {
  cache.clear();
  inFlight.clear();
}
