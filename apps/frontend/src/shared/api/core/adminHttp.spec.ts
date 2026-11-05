import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';
import { coreAdminSession } from '@/core/admin/session';
import { coreAdminAuth } from './adminAuth';
import { coreAdminHttp } from './adminHttp';
import { ApiError, NetworkError, SupersededOperationError } from '@/shared/api/errors';
vi.mock('./adminAuth', () => ({ coreAdminAuth: { refresh: vi.fn() } }));
const tokens = (id: string) => ({ accessToken: `access-${id}`, refreshToken: `refresh-${id}`, accessExpiresAt: '2099', sessionExpiresAt: '2099', sessionId: id });
const reply = (value: unknown, status = 200) => new Response(JSON.stringify(status === 200 ? { status: 'success', data: value } : { error: { code: 'auth.required' } }), { status });
function deferred<T>() { let resolve!: (value: T) => void; let reject!: (error: unknown) => void; const promise = new Promise<T>((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; }
beforeEach(() => { vi.resetAllMocks(); coreAdminSession.clear(); coreAdminSession.set(tokens('A')); });
afterEach(() => vi.unstubAllGlobals());

describe('Core HTTP session ownership', () => {
  it('rejects a late raw document after the owning session changes', async () => {
    const pending=deferred<Response>();vi.stubGlobal('fetch',vi.fn().mockReturnValue(pending.promise));
    const request=coreAdminHttp.document('/auth/me/privacy/requests/id/data');coreAdminSession.set(tokens('B'));
    pending.resolve(new Response('{"schemaVersion":"1","sections":[]}'));
    await expect(request).rejects.toBeInstanceOf(SupersededOperationError);
  });
  it('retains the MFA DELETE body through the authenticated transport', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(reply({ message: 'updated' })));
    await coreAdminHttp.delete('/auth/mfa/totp', { password: null, code: '123456' });
    const init = vi.mocked(fetch).mock.calls[0]?.[1];
    expect(init?.method).toBe('DELETE'); expect(JSON.parse(String(init?.body))).toEqual({ password: null, code: '123456' });
  });
  it.each([200, 403, 500])('rejects a late A HTTP %s after B login', async status => {
    const response = deferred<Response>(); vi.stubGlobal('fetch', vi.fn().mockReturnValue(response.promise));
    const request = coreAdminHttp.get('/persons'); coreAdminSession.set(tokens('B')); response.resolve(reply(['A'], status));
    await expect(request).rejects.toBeInstanceOf(SupersededOperationError); expect(coreAdminSession.accessToken()).toBe('access-B');
  });
  it('rotates tokens without changing ownership and retries both concurrent 401 requests once', async () => {
    const fetch = vi.fn().mockImplementation((_url, init) => Promise.resolve(reply(['current'], init.headers.get('Authorization') === 'Bearer access-new' ? 200 : 401)));
    vi.stubGlobal('fetch', fetch);
    vi.mocked(coreAdminAuth.refresh).mockResolvedValue({ ...tokens('A'), accessToken: 'access-new', refreshToken: 'refresh-new' });
    const epoch = coreAdminSession.epoch();
    expect(await Promise.all([coreAdminHttp.get('/persons'), coreAdminHttp.get('/accounts')])).toEqual([['current'], ['current']]);
    expect(coreAdminAuth.refresh).toHaveBeenCalledTimes(1); expect(coreAdminSession.epoch()).toBe(epoch); expect(fetch).toHaveBeenCalledTimes(4);
  });
  it.each(['success', 'failure'] as const)('does not let a late A refresh %s replace or log out B', async kind => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(reply(null, 401)));
    const refresh = deferred<ReturnType<typeof tokens>>(); vi.mocked(coreAdminAuth.refresh).mockReturnValue(refresh.promise);
    const request = coreAdminHttp.get('/persons'); await vi.waitFor(() => expect(coreAdminAuth.refresh).toHaveBeenCalled());
    coreAdminSession.set(tokens('B'));
    if (kind === 'success') refresh.resolve(tokens('A')); else refresh.reject(new ApiError(401, 'auth.required', 'expired'));
    await expect(request).rejects.toBeInstanceOf(SupersededOperationError); expect(coreAdminSession.accessToken()).toBe('access-B');
  });
  it('keeps the current session when refresh is temporarily unavailable', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(reply(null, 401))); vi.mocked(coreAdminAuth.refresh).mockRejectedValue(new NetworkError());
    await expect(coreAdminHttp.get('/persons')).rejects.toBeInstanceOf(NetworkError); expect(coreAdminSession.accessToken()).toBe('access-A');
  });
});
