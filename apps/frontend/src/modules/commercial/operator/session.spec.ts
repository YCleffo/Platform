import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  clearOperator,
  loginOperator,
  logoutOperator,
  operatorHttp,
  operatorIdentity,
  operatorChallenge,
  verifyOperator,
} from './session';
import { SupersededOperationError } from '@/shared/api/errors';
const response = (body: unknown, status = 200) =>
  new Response(body === undefined ? null : JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
const tokens = (id: string) => ({
  access_token: 'operator-access-' + id,
  refresh_token: 'operator-refresh-' + id,
});
const identity = (id: string) => ({
  user: { id, email: id + '@example.test' },
  privacy: true,
  finance: false,
  catalog: false,
});
function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((r) => {
    resolve = r;
  });
  return { promise, resolve };
}
beforeEach(() => {
  clearOperator();
  vi.restoreAllMocks();
});
afterEach(() => {
  clearOperator();
  vi.unstubAllGlobals();
});
describe('independent Commercial operator session', () => {
  it('refreshes capabilities after a denied write and removes revoked finance access', async () => {
    const fetch = vi.fn()
      .mockResolvedValueOnce(response(tokens('a')))
      .mockResolvedValueOnce(response({ ...identity('a'), finance: true }))
      .mockResolvedValueOnce(response({ error: { code: 'access_denied', message: 'Access denied.' } }, 403))
      .mockResolvedValueOnce(response(identity('a')));
    vi.stubGlobal('fetch', fetch);
    await loginOperator('a', 'pass');
    await expect(operatorHttp.post('/finance/review', {})).rejects.toThrow();
    expect(operatorIdentity.value).toEqual(identity('a'));
    expect(new URL(fetch.mock.calls[3]![0]).pathname).toBe('/api/control/operator/me');
  });
  it('preserves capabilities on a recent MFA challenge without fetching identity', async () => {
    const fetch = vi.fn()
      .mockResolvedValueOnce(response(tokens('a')))
      .mockResolvedValueOnce(response(identity('a')))
      .mockResolvedValueOnce(response({ error: { code: 'recent_mfa_required', message: 'Recent MFA required.' } }, 403));
    vi.stubGlobal('fetch', fetch);
    await loginOperator('a', 'pass');
    await expect(operatorHttp.post('/privacy/operator/requests/intake', {})).rejects.toThrow();
    expect(operatorIdentity.value).toEqual(identity('a'));
    expect(fetch).toHaveBeenCalledTimes(3);
  });
  it('logs in explicitly, fetches server capabilities and uses only its own bearer', async () => {
    const fetch = vi
      .fn()
      .mockResolvedValueOnce(response(tokens('a')))
      .mockResolvedValueOnce(response(identity('a')))
      .mockResolvedValueOnce(response({ items: [], total: 0 }));
    vi.stubGlobal('fetch', fetch);
    await loginOperator('a@example.test', 'typed-by-user');
    expect(operatorIdentity.value?.user.id).toBe('a');
    await operatorHttp.get('/operator/privacy/requests');
    expect(new Headers(fetch.mock.calls[2]![1].headers).get('Authorization')).toBe(
      'Bearer operator-access-a',
    );
    expect(fetch.mock.calls[2]![1].credentials).toBe('omit');
    expect(new Headers(fetch.mock.calls[0]![1].headers).has('Authorization')).toBe(false);
  });
  it('rejects customer identity without capabilities and revokes the attempted session', async () => {
    const fetch = vi
      .fn()
      .mockResolvedValueOnce(response(tokens('a')))
      .mockResolvedValueOnce(response({ code: 'access_denied' }, 403))
      .mockResolvedValueOnce(response(undefined, 204));
    vi.stubGlobal('fetch', fetch);
    await expect(loginOperator('a@example.test', 'pass')).rejects.toThrow();
    expect(operatorIdentity.value).toBeNull();
    expect(fetch.mock.calls[2]![0]).toBe('/api/control/auth/logout');
  });
  it('does not establish an operator session until MFA and capabilities succeed', async () => {
    const fetch = vi
      .fn()
      .mockResolvedValueOnce(response({ status: 'mfa_required', challenge_token: 'challenge' }))
      .mockResolvedValueOnce(response(tokens('a')))
      .mockResolvedValueOnce(response(identity('a')));
    vi.stubGlobal('fetch', fetch);
    await loginOperator('a@example.test', 'pass');
    expect(operatorIdentity.value).toBeNull();
    expect(operatorChallenge.value).toBe('challenge');
    await verifyOperator('123456', false);
    expect(operatorIdentity.value?.user.id).toBe('a');
    expect(JSON.parse(fetch.mock.calls[1]![1].body)).toEqual({
      challenge_token: 'challenge',
      code: '123456',
    });
  });
  it('discards a late successful response after logout', async () => {
    const late = deferred<Response>();
    const fetch = vi
      .fn()
      .mockResolvedValueOnce(response(tokens('a')))
      .mockResolvedValueOnce(response(identity('a')))
      .mockImplementationOnce(() => late.promise);
    vi.stubGlobal('fetch', fetch);
    await loginOperator('a', 'pass');
    const request = operatorHttp.get('/operator/privacy/requests');
    clearOperator();
    late.resolve(response({ items: [{ id: 'private-a' }] }));
    await expect(request).rejects.toBeInstanceOf(SupersededOperationError);
  });
  it('late logout A cannot clear the newly established B session', async () => {
    const late = deferred<Response>();
    const fetch = vi
      .fn()
      .mockResolvedValueOnce(response(tokens('a')))
      .mockResolvedValueOnce(response(identity('a')))
      .mockImplementationOnce(() => late.promise)
      .mockResolvedValueOnce(response(tokens('b')))
      .mockResolvedValueOnce(response(identity('b')));
    vi.stubGlobal('fetch', fetch);
    await loginOperator('a', 'pass');
    const logout = logoutOperator();
    await loginOperator('b', 'pass');
    late.resolve(response(undefined, 204));
    await logout;
    expect(operatorIdentity.value?.user.id).toBe('b');
    expect(new Headers(fetch.mock.calls[2]![1].headers).get('Authorization')).toBe(
      'Bearer operator-access-a',
    );
  });
  it('does not persist session data in browser storage', async () => {
    const local = vi.spyOn(Storage.prototype, 'setItem');
    const fetch = vi
      .fn()
      .mockResolvedValueOnce(response(tokens('a')))
      .mockResolvedValueOnce(response(identity('a')));
    vi.stubGlobal('fetch', fetch);
    await loginOperator('a', 'pass');
    expect(local).not.toHaveBeenCalled();
  });
});
