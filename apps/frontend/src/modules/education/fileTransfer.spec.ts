import { createHash } from 'node:crypto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminSession } from '@/core/admin/session';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { ApiError, SupersededOperationError } from '@/shared/api/errors';
import { educationDownload, uploadSigned, type SignedRequest } from './fileTransfer';

vi.mock('@/shared/api/core/adminHttp', () => ({ coreAdminHttp: { request: vi.fn() } }));

const expiresAt = '2099-01-01T00:00:00Z';
const path = '/education/homework/files/file/transfer';

function downloadPermission(suffix: string) {
  return {
    mime: 'text/plain',
    sizeBytes: 5,
    sha256: createHash('sha256').update('hello').digest('hex'),
    request: { url: `http://127.0.0.1:18338/bucket/${suffix}`, method: 'GET', headers: {}, expiresAt },
  };
}

beforeEach(() => {
  vi.mocked(coreAdminHttp.request).mockReset();
  coreAdminSession.set({ accessToken: 'core-token', refreshToken: 'refresh', accessExpiresAt: '', sessionExpiresAt: '', sessionId: 'test' });
});
afterEach(() => {
  vi.unstubAllGlobals();
  coreAdminSession.clear();
});

describe('private education objects', () => {
  it('replaces a rejected S3 GET permission once and verifies the replacement bytes', async () => {
    const first = downloadPermission('old'), fresh = downloadPermission('fresh');
    vi.mocked(coreAdminHttp.request).mockResolvedValueOnce(first).mockResolvedValueOnce(fresh);
    const fetch = vi.fn<typeof globalThis.fetch>()
      .mockResolvedValueOnce(new Response(null, { status: 403 }))
      .mockResolvedValueOnce(new Response('hello'));
    vi.stubGlobal('fetch', fetch);

    const blob = await educationDownload('org', path);

    expect(await blob.text()).toBe('hello');
    expect(coreAdminHttp.request).toHaveBeenCalledTimes(2);
    expect(fetch.mock.calls.map(([url]) => url)).toEqual([first.request.url, fresh.request.url]);
    for (const [, options] of fetch.mock.calls) {
      expect(options?.credentials).toBe('omit');
      expect(new Headers(options?.headers).has('Authorization')).toBe(false);
    }
  });

  it('preserves Core access denial rather than using it to retry S3', async () => {
    vi.mocked(coreAdminHttp.request).mockRejectedValue(new ApiError(403, 'auth.permission_denied', 'Denied'));
    const fetch = vi.fn();
    vi.stubGlobal('fetch', fetch);
    await expect(educationDownload('org', path)).rejects.toMatchObject({ status: 403, code: 'auth.permission_denied' });
    expect(coreAdminHttp.request).toHaveBeenCalledTimes(1);
    expect(fetch).not.toHaveBeenCalled();
  });

  it('actively aborts an in-flight object PUT on account change', async () => {
    class ObjectRequest {
      static sent: ObjectRequest;
      withCredentials = true;
      upload = { onprogress: undefined };
      onabort?: () => void;
      open = vi.fn();
      setRequestHeader = vi.fn();
      send = vi.fn(() => { ObjectRequest.sent = this; });
      abort = vi.fn(() => { this.onabort?.(); });
    }
    vi.stubGlobal('XMLHttpRequest', ObjectRequest);
    const request: SignedRequest = {
      url: 'http://localhost:18338/bucket/staging', method: 'PUT', expiresAt, headers: { 'Content-Type': 'application/octet-stream' },
    };
    const pending = uploadSigned(request, new Blob(['hello']));
    const result = expect(pending).rejects.toBeInstanceOf(SupersededOperationError);
    coreAdminSession.clear();
    await result;

    expect(ObjectRequest.sent.abort).toHaveBeenCalledOnce();
    expect(ObjectRequest.sent.withCredentials).toBe(false);
    expect(ObjectRequest.sent.setRequestHeader).toHaveBeenCalledExactlyOnceWith('Content-Type', 'application/octet-stream');
  });
});
