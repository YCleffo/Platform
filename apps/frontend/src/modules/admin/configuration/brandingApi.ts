import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { ApiResponseError } from '@/shared/api/errors';
import { applyPlatformBranding } from '@/shared/branding';

const MAX_ICON_BYTES = 524288;
interface IconUpload {
  id: string;
  request: { url: string; method: 'PUT'; headers: Record<string, string>; expiresAt: string };
}

function uploadSession(value: unknown): IconUpload {
  if (typeof value !== 'object' || value === null) throw new ApiResponseError('/branding/favicon');
  const item = value as Partial<IconUpload>;
  if (typeof item.id !== 'string' || !/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(item.id)
    || typeof item.request?.url !== 'string' || item.request.method !== 'PUT'
    || typeof item.request.headers !== 'object' || item.request.headers === null) {
    throw new ApiResponseError('/branding/favicon');
  }
  const url = new URL(item.request.url);
  if (url.protocol !== 'https:' && !(url.protocol === 'http:' && ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname))) {
    throw new ApiResponseError('/branding/favicon');
  }
  return item as IconUpload;
}

function putIcon(session: IconUpload, file: File, signal: AbortSignal, progress: (value: number) => void): Promise<void> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    const abort = () => xhr.abort();
    xhr.open('PUT', session.request.url);
    xhr.timeout = 60000;
    for (const [name, value] of Object.entries(session.request.headers)) xhr.setRequestHeader(name, value);
    xhr.upload.onprogress = event => { if (event.lengthComputable) progress(Math.round(event.loaded / event.total * 100)); };
    xhr.onload = () => xhr.status >= 200 && xhr.status < 300 ? resolve() : reject(new Error('Icon transfer failed'));
    xhr.onerror = xhr.ontimeout = () => reject(new Error('Icon transfer failed'));
    xhr.onabort = () => reject(new DOMException('Cancelled', 'AbortError'));
    xhr.onloadend = () => signal.removeEventListener('abort', abort);
    signal.addEventListener('abort', abort, { once: true });
    if (signal.aborted) { reject(new DOMException('Cancelled', 'AbortError')); return; }
    xhr.send(file);
  });
}

export const brandingApi = {
  async rename(name: string) {
    return applyPlatformBranding(await coreAdminHttp.put('/branding', { name }));
  },
  async removeIcon() {
    return applyPlatformBranding(await coreAdminHttp.delete('/branding/favicon'));
  },
  async uploadIcon(file: File, signal: AbortSignal, progress: (value: number) => void) {
    if (file.size < 1 || file.size > MAX_ICON_BYTES || !/\.(png|ico)$/i.test(file.name)) {
      throw new TypeError('Choose a PNG or ICO file up to 512 KB');
    }
    const bytes = await file.arrayBuffer();
    const digest = await crypto.subtle.digest('SHA-256', bytes);
    const sha256 = [...new Uint8Array(digest)].map(value => value.toString(16).padStart(2, '0')).join('');
    const session = uploadSession(await coreAdminHttp.post('/branding/favicon', { sizeBytes: file.size, sha256 }, signal));
    try {
      await putIcon(session, file, signal, progress);
      signal.throwIfAborted();
      return applyPlatformBranding(await coreAdminHttp.post(`/branding/favicon/${session.id}/complete`, {}, signal));
    } catch (error) {
      if (signal.aborted) {
        await coreAdminHttp.post(`/branding/favicon/${session.id}/abort`).catch(() => undefined);
      }
      throw error;
    }
  },
};
