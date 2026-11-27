import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { mediaApi, uploadBinaryWithProgress } from './mediaApi';

vi.mock('@/shared/api/core/adminHttp', () => ({
  coreAdminHttp: {
    request: vi.fn(),
  },
}));

describe('mediaApi uploadBinaryWithProgress and cancelUpload', () => {
  const originalXhr = globalThis.XMLHttpRequest;

  beforeEach(() => {
    vi.clearAllMocks();
  });

  afterEach(() => {
    globalThis.XMLHttpRequest = originalXhr;
  });

  it('reports real upload progress via XMLHttpRequest upload.onprogress', async () => {
    const sent: unknown[] = [];
    const headers: Record<string, string> = {};

    class MockXhr {
      upload: { onprogress: ((e: { loaded: number; total: number; lengthComputable: boolean }) => void) | null } = {
        onprogress: null,
      };
      onload: (() => void) | null = null;
      onerror: (() => void) | null = null;
      onabort: (() => void) | null = null;
      withCredentials = false;
      status = 200;
      responseText = JSON.stringify({ status: 'success', data: { id: 'm-1', status: 'uploaded' } });
      open = vi.fn();
      setRequestHeader = vi.fn((k: string, v: string) => {
        headers[k] = v;
      });
      abort = vi.fn();
      send = vi.fn((body: unknown) => {
        sent.push(body);
        this.upload.onprogress?.({ loaded: 50, total: 100, lengthComputable: true });
        this.onload?.();
      });
    }

    globalThis.XMLHttpRequest = MockXhr as unknown as typeof XMLHttpRequest;

    const progressSpy = vi.fn();
    const blob = new Blob(['hello world'], { type: 'text/plain' });
    const res = await uploadBinaryWithProgress('/api/media/upload/m-1', blob, {
      headers: { 'X-Content-Sha256': 'abc' },
      onProgress: progressSpy,
    });

    expect(sent).toHaveLength(1);
    expect(headers['X-Content-Sha256']).toBe('abc');
    expect(progressSpy).toHaveBeenCalledWith({
      loaded: 50,
      total: 100,
      lengthComputable: true,
      percent: 50,
    });
    expect(res).toEqual({ id: 'm-1', status: 'uploaded' });
  });

  it('aborts XMLHttpRequest and rejects with AbortError when AbortSignal fires', async () => {
    const abortSpy = vi.fn();
    class HangingXhr {
      upload = { onprogress: null };
      onload: (() => void) | null = null;
      onerror: (() => void) | null = null;
      onabort: (() => void) | null = null;
      withCredentials = false;
      status = 0;
      responseText = '';
      open = vi.fn();
      setRequestHeader = vi.fn();
      abort = abortSpy;
      send = vi.fn();
    }

    globalThis.XMLHttpRequest = HangingXhr as unknown as typeof XMLHttpRequest;

    const controller = new AbortController();
    const promise = uploadBinaryWithProgress('/api/media/upload/m-2', new Blob(['x']), {
      signal: controller.signal,
    });
    controller.abort();

    await expect(promise).rejects.toMatchObject({ name: 'AbortError' });
    expect(abortSpy).toHaveBeenCalledTimes(1);
  });

  it('calls POST /api/media/{id}/cancel via cancelUpload', async () => {
    vi.mocked(coreAdminHttp.request).mockResolvedValueOnce({
      asset: { id: 'm-9', status: 'cancelled' },
      cancelled: true,
    });

    const res = await mediaApi.cancelUpload('m-9', 'org-1');
    expect(res.cancelled).toBe(true);
    expect(coreAdminHttp.request).toHaveBeenCalledWith('/media/m-9/cancel', {
      method: 'POST',
      body: {},
      headers: { 'X-Education-Organization': 'org-1' },
    });
  });
});
