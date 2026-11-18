import { createSHA256 } from 'hash-wasm';
import { watch } from 'vue';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { coreAdminSession, coreAdminSessionEpoch } from '@/core/admin/session';
import { ApiError, ApiResponseError, SupersededOperationError } from '@/shared/api/errors';

export interface SignedRequest {
  url: string;
  method: 'GET' | 'PUT';
  headers: Record<string, string>;
  expiresAt: string;
}
export interface TransferProgress { loaded: number; total: number; phase: 'hashing' | 'uploading' | 'verifying' }
export interface TransferOptions { signal?: AbortSignal; progress?: (value: TransferProgress) => void }
const record = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null && !Array.isArray(value);
export function signedRequestGuard(value: unknown): value is SignedRequest {
  return record(value) && typeof value.url === 'string' && ['GET', 'PUT'].includes(String(value.method))
    && typeof value.expiresAt === 'string' && Number.isFinite(Date.parse(value.expiresAt))
    && record(value.headers) && Object.values(value.headers).every(header => typeof header === 'string');
}
export function assertTransferCurrent(epoch: number, signal?: AbortSignal): void {
  if (epoch !== coreAdminSession.epoch()) throw new SupersededOperationError();
  signal?.throwIfAborted();
}
function observeTransferAbort(signal: AbortSignal | undefined, abort: () => void): () => void {
  signal?.addEventListener('abort', abort, { once: true });
  const unwatch = watch(coreAdminSessionEpoch, abort, { flush: 'sync' });
  return () => {
    unwatch();
    signal?.removeEventListener('abort', abort);
  };
}
export function signedRequestRejected(error: unknown): boolean {
  return error instanceof ApiError && error.code === 'storage.signed_request_rejected';
}
function signedResponseError(status: number): ApiError {
  if (status === 403) return new ApiError(status, 'storage.signed_request_rejected', 'The signed object request was rejected.');
  if (status === 404) return new ApiError(status, 'storage.object_missing', 'The object could not be found.');
  if (status >= 400 && status < 500) return new ApiError(status, 'storage.object_transfer_failed', 'The object transfer failed.');
  return new ApiError(503, 'storage.unavailable', 'Object storage is unavailable.');
}
function safeRequest(request: SignedRequest, method: 'GET' | 'PUT'): void {
  let url: URL;
  try { url = new URL(request.url); }
  catch { throw new ApiResponseError('Object transfer'); }
  if (request.method !== method || url.username || url.password || url.hash
    || url.protocol !== 'https:' && !(url.protocol === 'http:' && ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname))
    || Object.keys(request.headers).some(name => /^(authorization|cookie|proxy-authorization)$/i.test(name))) {
    throw new ApiResponseError('Object transfer');
  }
}

/** Hashing retains one four-MiB chunk regardless of the source file size. */
export async function hashBlob(blob: Blob, options: TransferOptions = {}): Promise<string> {
  const epoch = coreAdminSession.epoch();
  const hash = await createSHA256();
  hash.init();
  for (let offset = 0; offset < blob.size; offset += 4 * 1024 * 1024) {
    assertTransferCurrent(epoch, options.signal);
    const chunk = await blob.slice(offset, offset + 4 * 1024 * 1024).arrayBuffer();
    assertTransferCurrent(epoch, options.signal);
    hash.update(new Uint8Array(chunk));
    options.progress?.({ loaded: Math.min(offset + chunk.byteLength, blob.size), total: blob.size, phase: 'hashing' });
  }
  assertTransferCurrent(epoch, options.signal);
  return hash.digest('hex');
}

/** The object request carries only the signed S3 headers and never the Core access token. */
export function uploadSigned(request: SignedRequest, blob: Blob, options: TransferOptions = {}): Promise<void> {
  safeRequest(request, 'PUT');
  const epoch = coreAdminSession.epoch();
  assertTransferCurrent(epoch, options.signal);
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('PUT', request.url);
    xhr.withCredentials = false;
    for (const [name, value] of Object.entries(request.headers)) {
      if (!/^(host|content-length|user-agent)$/i.test(name)) xhr.setRequestHeader(name, value);
    }
    xhr.upload.onprogress = event => {
      if (!options.signal?.aborted && epoch === coreAdminSession.epoch()) {
        options.progress?.({ loaded: event.loaded, total: blob.size, phase: 'uploading' });
      }
    };
    const unobserve = observeTransferAbort(options.signal, () => xhr.abort());
    const finish = (failure?: unknown) => {
      unobserve();
      try {
        assertTransferCurrent(epoch, options.signal);
        if (failure) reject(failure);
        else resolve();
      } catch (error) { reject(error); }
    };
    xhr.onload = () => finish(xhr.status >= 200 && xhr.status < 300 ? undefined
      : signedResponseError(xhr.status));
    xhr.onerror = () => finish(signedResponseError(0));
    xhr.onabort = () => finish(new DOMException('Aborted', 'AbortError'));
    try { xhr.send(blob); }
    catch (error) { finish(error); }
  });
}

/** Education attachments are bounded to ten MiB and verified before the existing Blob consumers receive them. */
export async function downloadVerified(request: SignedRequest, size: number, sha256: string, mime: string, signal?: AbortSignal): Promise<Blob> {
  safeRequest(request, 'GET');
  if (!Number.isSafeInteger(size) || size < 0 || size > 10485760 || !/^[a-f0-9]{64}$/.test(sha256)) throw new ApiResponseError('Education transfer');
  const epoch = coreAdminSession.epoch();
  assertTransferCurrent(epoch, signal);
  const controller = new AbortController();
  const unobserve = observeTransferAbort(signal, () => controller.abort());
  let reader: ReadableStreamDefaultReader<Uint8Array> | undefined;
  try {
    const response = await fetch(request.url, { method: 'GET', headers: request.headers,
      credentials: 'omit', referrerPolicy: 'no-referrer', signal: controller.signal });
    assertTransferCurrent(epoch, signal);
    if (!response.ok || !response.body) throw signedResponseError(response.status);
    reader = response.body.getReader();
    const hash = await createSHA256();
    const chunks: Uint8Array<ArrayBuffer>[] = [];
    let received = 0;
    while (true) {
      assertTransferCurrent(epoch, signal);
      const part = await reader.read();
      if (part.done) break;
      received += part.value.length;
      if (received > size) throw new ApiError(503, 'storage.integrity_failed', 'The downloaded file failed verification.');
      hash.update(part.value);
      chunks.push(new Uint8Array(part.value));
    }
    assertTransferCurrent(epoch, signal);
    if (received !== size || hash.digest('hex') !== sha256) throw new ApiError(503, 'storage.integrity_failed', 'The downloaded file failed verification.');
    return new Blob(chunks, { type: mime });
  } catch (error) {
    assertTransferCurrent(epoch, signal);
    if (error instanceof ApiError) throw error;
    throw signedResponseError(0);
  } finally {
    unobserve();
    if (reader) {
      await reader.cancel().catch(() => undefined);
      reader.releaseLock();
    }
  }
}

export function downloadInBrowser(request: SignedRequest, name: string): void {
  safeRequest(request, 'GET');
  if (Object.keys(request.headers).length !== 0) throw new ApiResponseError('Storage download');
  if (Date.parse(request.expiresAt) <= Date.now()) throw signedResponseError(403);
  const anchor = document.createElement('a');
  anchor.href = request.url;
  anchor.download = name;
  anchor.rel = 'noopener noreferrer';
  anchor.referrerPolicy = 'no-referrer';
  anchor.click();
}

interface EducationTransfer {
  id: string; status: string; expectedSize: number; expectedSha256: string; expiresAt: string;
  request: SignedRequest | null; result: Record<string, unknown> | null;
}
function educationTransferGuard(value: unknown): value is EducationTransfer {
  return record(value) && typeof value.id === 'string' && typeof value.status === 'string'
    && typeof value.expectedSize === 'number' && typeof value.expectedSha256 === 'string'
    && (value.request === null || signedRequestGuard(value.request)) && (value.result === null || record(value.result));
}
export async function educationUpload(org: string, path: string, file: File, settings: Record<string, unknown> = {}, options: TransferOptions = {}): Promise<Record<string, unknown>> {
  const epoch = coreAdminSession.epoch();
  const sha256 = await hashBlob(file, options);
  assertTransferCurrent(epoch, options.signal);
  const headers = { 'X-Education-Organization': org };
  const transfer = await coreAdminHttp.request<unknown>(path, { method: 'POST', headers, signal: options.signal,
    body: { ...settings, name: file.name, sizeBytes: file.size, sha256 } });
  if (!educationTransferGuard(transfer) || !transfer.request) throw new ApiResponseError(path);
  const metadata = `/education/file-transfers/${encodeURIComponent(transfer.id)}`;
  try {
    assertTransferCurrent(epoch, options.signal);
    try { await uploadSigned(transfer.request, file, options); }
    catch (error) {
      if (!signedRequestRejected(error)) throw error;
      assertTransferCurrent(epoch, options.signal);
      const renewed = await coreAdminHttp.request<unknown>(metadata, { method: 'GET', headers, signal: options.signal });
      assertTransferCurrent(epoch, options.signal);
      if (!educationTransferGuard(renewed) || !renewed.request) throw new ApiResponseError(metadata);
      await uploadSigned(renewed.request, file, options);
    }
    assertTransferCurrent(epoch, options.signal);
    options.progress?.({ loaded: file.size, total: file.size, phase: 'verifying' });
    assertTransferCurrent(epoch, options.signal);
    const completed = await coreAdminHttp.request<unknown>(metadata + '/complete', { method: 'POST', headers, body: {}, signal: options.signal });
    if (!educationTransferGuard(completed) || completed.status !== 'confirmed' || !completed.result) throw new ApiResponseError(metadata);
    return completed.result;
  } catch (error) {
    if (options.signal?.aborted && epoch === coreAdminSession.epoch()) {
      await coreAdminHttp.request(metadata + '/abort', { method: 'POST', headers, body: {} }).catch(() => undefined);
    }
    throw error;
  }
}

export async function educationDownload(org: string, path: string, signal?: AbortSignal): Promise<Blob> {
  const epoch = coreAdminSession.epoch();
  const download = async (): Promise<Blob> => {
    assertTransferCurrent(epoch, signal);
    const result = await coreAdminHttp.request<unknown>(path, { method: 'GET', headers: { 'X-Education-Organization': org }, signal });
    assertTransferCurrent(epoch, signal);
    if (!record(result) || typeof result.sizeBytes !== 'number' || typeof result.sha256 !== 'string'
      || typeof result.mime !== 'string' || !signedRequestGuard(result.request)) throw new ApiResponseError(path);
    return downloadVerified(result.request, result.sizeBytes, result.sha256, result.mime, signal);
  };
  try { return await download(); }
  catch (error) {
    if (!signedRequestRejected(error)) throw error;
    return download();
  }
}
