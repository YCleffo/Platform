import { beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { coreAdminSession } from '@/core/admin/session';
import { cachedAdminGet, clearAdminCache, peekAdminCache } from './adminCache';
import { ApiError, SupersededOperationError } from '@/shared/api/errors';
vi.mock('@/shared/api/core/adminHttp', () => ({ coreAdminHttp: { get: vi.fn() } }));
const tokens = (id: string) => ({ accessToken: id, refreshToken: id, accessExpiresAt: '2099', sessionExpiresAt: '2099', sessionId: id });
function deferred<T>() { let resolve!: (value: T) => void; const promise = new Promise<T>(yes => { resolve = yes; }); return { promise, resolve }; }
beforeEach(() => { vi.resetAllMocks(); coreAdminSession.clear(); clearAdminCache(); });

describe('Core cache isolation', () => {
  it('never returns A cache to B or an anonymous session', async () => {
    coreAdminSession.set(tokens('A')); vi.mocked(coreAdminHttp.get).mockResolvedValueOnce(['private A']);
    await cachedAdminGet('/persons'); expect(peekAdminCache('/persons')).toEqual(['private A']);
    coreAdminSession.clear(); expect(peekAdminCache('/persons')).toBeUndefined();
    coreAdminSession.set(tokens('B')); vi.mocked(coreAdminHttp.get).mockRejectedValue(new Error('403'));
    await expect(cachedAdminGet('/persons')).rejects.toThrow('403'); expect(peekAdminCache('/persons')).toBeUndefined();
  });
  it('does not repopulate the cache from A after B signed in', async () => {
    coreAdminSession.set(tokens('A')); const old = deferred<unknown>(); vi.mocked(coreAdminHttp.get).mockReturnValue(old.promise);
    const request = cachedAdminGet('/persons'); coreAdminSession.set(tokens('B')); old.resolve(['A']);
    await expect(request).rejects.toBeInstanceOf(SupersededOperationError); expect(peekAdminCache('/persons')).toBeUndefined();
  });
  it('does not let an old finally remove a newer pending request after clear', async () => {
    coreAdminSession.set(tokens('A')); const old = deferred<unknown>(); const next = deferred<unknown>();
    vi.mocked(coreAdminHttp.get).mockReturnValueOnce(old.promise).mockReturnValueOnce(next.promise);
    const first = cachedAdminGet('/persons'); clearAdminCache(); const second = cachedAdminGet('/persons');
    old.resolve(['stale']); await expect(first).rejects.toBeInstanceOf(SupersededOperationError);
    const third = cachedAdminGet('/persons'); expect(coreAdminHttp.get).toHaveBeenCalledTimes(2);
    next.resolve(['current']); expect(await second).toEqual(['current']); expect(await third).toEqual(['current']);
  });
  it('preserves same-session cache and deduplication across token rotation', async () => {
    coreAdminSession.set(tokens('A')); vi.mocked(coreAdminHttp.get).mockResolvedValue(['current']);
    await cachedAdminGet('/persons'); const epoch = coreAdminSession.epoch();
    expect(coreAdminSession.rotate({ ...tokens('A'), accessToken: 'new', refreshToken: 'new' }, epoch, 'A')).toBe(true);
    expect(await cachedAdminGet('/persons')).toEqual(['current']); expect(coreAdminHttp.get).toHaveBeenCalledTimes(1);
  });
  it('rejects even an already-cached hit when the actor changes before settlement', async () => {
    vi.mocked(coreAdminHttp.get).mockResolvedValue(['A']); await cachedAdminGet('/persons');
    const hit = cachedAdminGet('/persons'); coreAdminSession.set(tokens('B'));
    await expect(hit).rejects.toBeInstanceOf(SupersededOperationError);
  });
  it('evicts cached data after a fresh permission denial, including the next retry', async () => {
    vi.mocked(coreAdminHttp.get).mockResolvedValueOnce(['previously allowed']); await cachedAdminGet('/persons');
    vi.mocked(coreAdminHttp.get).mockRejectedValue(new ApiError(403, 'http.forbidden', 'Denied'));
    await expect(cachedAdminGet('/persons', true)).rejects.toBeInstanceOf(ApiError);
    expect(peekAdminCache('/persons')).toBeUndefined();
    await expect(cachedAdminGet('/persons')).rejects.toBeInstanceOf(ApiError);
  });
});
