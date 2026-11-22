import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { ApiResponseError, SupersededOperationError } from '@/shared/api/errors';
import { applyPlatformBranding } from '@/shared/branding';
import { coreAdminSession } from '@/core/admin/session';

const MAX_ICON_BYTES = 524288;
interface IconUpload {
  id: string;
  request: { url: string; method: 'PUT'; headers: Record<string, string>; expiresAt: string };
}

class SignedIconRequestRejected extends Error {}

function uploadSession(value: unknown): IconUpload {
  if (typeof value !== 'object' || value === null) throw new ApiResponseError('/branding/favicon');
  const item = value as Partial<IconUpload>;
  if (typeof item.id !== 'string' || !/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(item.id)
    || typeof item.request?.url !== 'string' || item.request.method !== 'PUT'
    || typeof item.request.expiresAt !== 'string' || !Number.isFinite(Date.parse(item.request.expiresAt))
    || typeof item.request.headers !== 'object' || item.request.headers === null
    || Array.isArray(item.request.headers)
    || Object.entries(item.request.headers).some(([name, value]) =>
      typeof value !== 'string' || /^(authorization|cookie|proxy-authorization)$/i.test(name))) {
    throw new ApiResponseError('/branding/favicon');
  }
  const url = new URL(item.request.url);
  if (url.username || url.password || url.hash ||
    (url.protocol !== 'https:' && !(url.protocol === 'http:' && ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname)))) {
    throw new ApiResponseError('/branding/favicon');
  }
  return item as IconUpload;
}

function putIcon(session: IconUpload, file: File, signal: AbortSignal, progress: (value: number) => void): Promise<void> {
  if (Date.parse(session.request.expiresAt) <= Date.now()) {
    return Promise.reject(new SignedIconRequestRejected('The upload link expired.'));
  }
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    const abort = () => xhr.abort();
    xhr.open('PUT', session.request.url);
    xhr.timeout = 60000;
    for (const [name, value] of Object.entries(session.request.headers)) xhr.setRequestHeader(name, value);
    xhr.upload.onprogress = event => { if (event.lengthComputable) progress(Math.round(event.loaded / event.total * 100)); };
    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) resolve();
      else if (xhr.status === 403) reject(new SignedIconRequestRejected('The upload link was rejected.'));
      else reject(new Error('Icon transfer failed'));
    };
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
    const epoch = coreAdminSession.epoch();
    const ensureCurrent = () => {
      if (epoch !== coreAdminSession.epoch()) throw new SupersededOperationError();
      signal.throwIfAborted();
    };
    const bytes = await file.arrayBuffer();
    const digest = await crypto.subtle.digest('SHA-256', bytes);
    const sha256 = [...new Uint8Array(digest)].map(value => value.toString(16).padStart(2, '0')).join('');
    ensureCurrent();
    const session = uploadSession(await coreAdminHttp.post('/branding/favicon', { sizeBytes: file.size, sha256 }, signal));
    try {
      ensureCurrent();
      try {
        await putIcon(session, file, signal, progress);
      } catch (error) {
        if (!(error instanceof SignedIconRequestRejected)) throw error;
        ensureCurrent();
        const renewed = uploadSession(await coreAdminHttp.post(`/branding/favicon/${session.id}/refresh`, {}, signal));
        ensureCurrent();
        if (renewed.id !== session.id) throw new ApiResponseError('/branding/favicon');
        progress(0);
        await putIcon(renewed, file, signal, progress);
      }
      ensureCurrent();
    } catch (error) {
      if (epoch === coreAdminSession.epoch()) {
        await coreAdminHttp.post(`/branding/favicon/${session.id}/abort`).catch(() => undefined);
      }
      throw error;
    }
    const branding = await coreAdminHttp.post(`/branding/favicon/${session.id}/complete`, {}, signal);
    ensureCurrent();
    return applyPlatformBranding(branding);
  },
};
