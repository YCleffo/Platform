import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { coreAdminSession } from '@/core/admin/session';
import { brandingApi } from './brandingApi';

vi.mock('@/shared/api/core/adminHttp', () => ({
  coreAdminHttp: { post: vi.fn() },
}));

const id = '01900000-0000-7000-8000-000000000001';
const base = '/branding/favicon';
const publicBranding = { name: 'Platform', favicon: null };
const responses: number[] = [];
const upload = vi.fn();
const makeRequest = (variant: string, expiresAt = new Date(Date.now() + 60_000).toISOString()) => ({
  id,
  request: { url: `https://objects.example/temporary?sign=${variant}`, method: 'PUT', headers: {}, expiresAt },
});

class UploadRequest {
  status = 0;
  upload = {};
  onload?: () => void;
  onloadend?: () => void;
  private url = '';

  open(_method: string, url: string) {
    this.url = url;
  }

  send(file: File) {
    upload(this.url, file);
    this.status = responses.shift() ?? 200;
    this.onload?.();
    this.onloadend?.();
  }
}

beforeEach(() => {
  vi.resetAllMocks();
  responses.length = 0;
  vi.stubGlobal('XMLHttpRequest', UploadRequest);
});

afterEach(() => vi.unstubAllGlobals());

describe('signed favicon upload', () => {
  const file = new File(['icon'], 'favicon.png', { type: 'image/png' });

  it('renews an expired link before sending bytes and applies the confirmed branding', async () => {
    const expired = makeRequest('old', '2000-01-01T00:00:00Z');
    const renewed = makeRequest('renewed');
    const post = vi.mocked(coreAdminHttp.post)
      .mockResolvedValueOnce(expired)
      .mockResolvedValueOnce(renewed)
      .mockResolvedValueOnce(publicBranding);
    const signal = new AbortController().signal;

    await expect(brandingApi.uploadIcon(file, signal, vi.fn())).resolves.toEqual(publicBranding);
    expect(upload).toHaveBeenCalledExactlyOnceWith(renewed.request.url, file);
    expect(post.mock.calls.map(([path]) => path)).toEqual([base, `${base}/${id}/refresh`, `${base}/${id}/complete`]);
  });

  it('renews a rejected link once, then aborts the pending upload on repeated rejection', async () => {
    responses.push(403, 403);
    const post = vi.mocked(coreAdminHttp.post)
      .mockResolvedValueOnce(makeRequest('first'))
      .mockResolvedValueOnce(makeRequest('renewed'))
      .mockResolvedValueOnce(publicBranding);

    await expect(brandingApi.uploadIcon(file, new AbortController().signal, vi.fn())).rejects.toThrow(/rejected/);
    expect(upload).toHaveBeenCalledTimes(2);
    expect(post.mock.calls.map(([path]) => path)).toEqual([base, `${base}/${id}/refresh`, `${base}/${id}/abort`]);
  });

  it('does not renew, finish or abort an old upload using a new session', async () => {
    let currentEpoch = 1;
    const epoch = vi.spyOn(coreAdminSession, 'epoch').mockImplementation(() => currentEpoch);
    const post = vi.mocked(coreAdminHttp.post).mockImplementationOnce(async () => {
      currentEpoch = 2;
      return makeRequest('first', '2000-01-01T00:00:00Z');
    });
    try {
      await expect(brandingApi.uploadIcon(file, new AbortController().signal, vi.fn())).rejects.toThrow();
      expect(upload).not.toHaveBeenCalled();
      expect(post.mock.calls.map(([path]) => path)).toEqual([base]);
    } finally {
      epoch.mockRestore();
    }
  });
});
