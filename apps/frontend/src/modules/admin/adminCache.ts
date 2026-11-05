import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { watch } from 'vue';
import { coreAdminSession, coreAdminSessionEpoch } from '@/core/admin/session';
import { ApiError, SupersededOperationError } from '@/shared/api/errors';

const cache = new Map<string, unknown>();
const inFlight = new Map<string, Promise<unknown>>();
let generation = 0;

export function peekAdminCache<T>(path: string): T | undefined {
  return cache.get(path) as T | undefined;
}

export async function cachedAdminGet<T>(path: string, force = false): Promise<T> {
  const epoch = coreAdminSession.epoch();
  const version = generation;
  if (!force && cache.has(path)) {
    const value = cache.get(path) as T;
    await Promise.resolve();
    if (epoch !== coreAdminSession.epoch() || version !== generation) throw new SupersededOperationError();
    return value;
  }
  const current = inFlight.get(path);
  if (current !== undefined) {
    return current as Promise<T>;
  }
  const request = coreAdminHttp.get<T>(path)
    .then((data) => {
      if (epoch !== coreAdminSession.epoch() || version !== generation) throw new SupersededOperationError();
      cache.set(path, data);
      return data;
    })
    .catch((error) => {
      if (epoch === coreAdminSession.epoch() && version === generation && inFlight.get(path) === request
        && error instanceof ApiError && [401, 403, 404].includes(error.status)) cache.delete(path);
      throw error;
    })
    .finally(() => {
      if (inFlight.get(path) === request) inFlight.delete(path);
    });
  inFlight.set(path, request);
  return request;
}

export function clearAdminCache(): void {
  generation += 1;
  cache.clear();
  inFlight.clear();
}

watch(coreAdminSessionEpoch, clearAdminCache, { flush: 'sync' });
