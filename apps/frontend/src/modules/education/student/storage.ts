import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { coreAdminSession } from '@/core/admin/session';
import { ApiError, ApiResponseError, SupersededOperationError } from '@/shared/api/errors';
import type { Page } from '../../education/api';
import { hashBlob, uploadSigned, downloadInBrowser, signedRequestGuard, type SignedRequest, type TransferOptions } from '../fileTransfer';

export interface StorageSpace { id: string; studentId: string; maxFiles: number; maxBytes: number; fileCount: number; totalBytes: number; createdAt: string }
export interface StorageFile { id: string; path: string; version: number | null; sizeBytes: number | null; sha256: string | null; createdAt: string | null }
export interface FileVersion { id: string; version: number; sizeBytes: number; sha256: string; createdAt: string }
export interface FileConflict { id: string; path: string; serverVersion: number | null; deviceVersion: number | null; serverSha256: string | null; deviceSha256: string | null; resolvedAt: string | null; createdAt: string }
export interface TransferTicket { id: string; spaceId: string; purpose: 'upload' | 'download'; path: string; token: string; expiresAt: string }
export interface UploadResult { path: string; version: number; sha256: string; size?: number }

type Guard<T> = (value: unknown) => value is T;
const record = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null && !Array.isArray(value);
const text = (value: unknown): value is string => typeof value === 'string';
const uuid = (value: unknown): value is string => text(value) && /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i.test(value);
const instant = (value: unknown): value is string => text(value) && Number.isFinite(Date.parse(value)) && value.includes('T');
const integer = (value: unknown): value is number => typeof value === 'number' && Number.isSafeInteger(value) && value >= 0;
const pageGuard = <T>(item: Guard<T>): Guard<Page<T>> => (v): v is Page<T> => record(v) && Array.isArray(v.items) && v.items.length <= 50 && v.items.every(item) && integer(v.limit) && integer(v.offset) && (v.nextOffset === null || integer(v.nextOffset));

const spaceGuard: Guard<StorageSpace> = (v): v is StorageSpace => record(v) && uuid(v.id) && uuid(v.studentId) && integer(v.maxFiles) && integer(v.maxBytes) && integer(v.fileCount) && integer(v.totalBytes) && instant(v.createdAt);
const fileGuard: Guard<StorageFile> = (v): v is StorageFile => record(v) && uuid(v.id) && text(v.path) && (v.version === null || integer(v.version)) && (v.sizeBytes === null || integer(v.sizeBytes)) && (v.sha256 === null || text(v.sha256)) && (v.createdAt === null || instant(v.createdAt));
const versionGuard: Guard<FileVersion> = (v): v is FileVersion => record(v) && uuid(v.id) && integer(v.version) && integer(v.sizeBytes) && text(v.sha256) && instant(v.createdAt);
const conflictGuard: Guard<FileConflict> = (v): v is FileConflict => record(v) && uuid(v.id) && text(v.path) && (v.serverVersion === null || integer(v.serverVersion)) && (v.deviceVersion === null || integer(v.deviceVersion)) && (v.serverSha256 === null || text(v.serverSha256)) && (v.deviceSha256 === null || text(v.deviceSha256)) && (v.resolvedAt === null || instant(v.resolvedAt)) && instant(v.createdAt);
const ticketGuard: Guard<TransferTicket> = (v): v is TransferTicket => record(v) && uuid(v.id) && uuid(v.spaceId) && ['upload', 'download'].includes(String(v.purpose)) && text(v.path) && text(v.token) && instant(v.expiresAt);
const uploadGuard: Guard<UploadResult> = (v): v is UploadResult => record(v) && text(v.path) && integer(v.version) && text(v.sha256) && (v.size === undefined || integer(v.size));

async function get<T>(path: string, org: string, guard: Guard<T>, signal?: AbortSignal): Promise<T> {
  const payload = await coreAdminHttp.request<unknown>(path, { method: 'GET', headers: { 'X-Education-Organization': org }, signal });
  if (guard(payload)) return payload;
  throw new ApiResponseError(path);
}
async function post<T>(path: string, org: string, body: unknown, guard: Guard<T>): Promise<T> {
  const payload = await coreAdminHttp.request<unknown>(path, { method: 'POST', headers: { 'X-Education-Organization': org }, body: JSON.stringify(body) });
  if (guard(payload)) return payload;
  throw new ApiResponseError(path);
}

interface ObjectTransfer {
  id: string; ticketId: string; direction: 'upload' | 'download'; status: string; mode: 'single' | 'multipart';
  expectedSize: number; expectedSha256: string; partSize: number | null; totalParts: number | null;
  request: SignedRequest | null; completedParts: { partNumber: number; sizeBytes: number; etag: string }[];
  result: UploadResult | null;
}
const objectTransferGuard: Guard<ObjectTransfer> = (value): value is ObjectTransfer => record(value) && uuid(value.id)
  && ['upload', 'download'].includes(String(value.direction)) && ['single', 'multipart'].includes(String(value.mode))
  && text(value.status) && integer(value.expectedSize) && text(value.expectedSha256)
  && (value.request === null || signedRequestGuard(value.request)) && Array.isArray(value.completedParts)
  && value.completedParts.every(part => record(part) && integer(part.partNumber) && integer(part.sizeBytes))
  && (value.result === null || uploadGuard(value.result));
const uploads = new Map<string, string>();
async function transferPost(org: string, path: string, body: unknown, signal?: AbortSignal): Promise<ObjectTransfer> {
  const value = await coreAdminHttp.request<unknown>(path, { method: 'POST', headers: { 'X-Education-Organization': org }, body, signal });
  if (!objectTransferGuard(value)) throw new ApiResponseError(path);
  return value;
}
async function readTransfer(org: string, id: string, signal?: AbortSignal): Promise<ObjectTransfer> {
  return get(`/api/storage/transfers/${encodeURIComponent(id)}`, org, objectTransferGuard, signal);
}

export async function uploadStorageFile(org: string, path: string, file: File, options: TransferOptions = {}): Promise<UploadResult> {
  const epoch = coreAdminSession.epoch();
  const sha256 = await hashBlob(file, options);
  const key = `${epoch}:${org}:${path}:${sha256}:${file.size}`;
  let transfer: ObjectTransfer;
  const previous = uploads.get(key);
  if (previous) transfer = await readTransfer(org, previous, options.signal);
  else {
    const ticket = await storageApi.ticket(org, 'upload', path, { expectedSize: file.size, expectedSha256: sha256 });
    transfer = await transferPost(org, '/api/storage/transfers', { ticketId: ticket.id, token: ticket.token }, options.signal);
    uploads.set(key, transfer.id);
  }
  if (transfer.expectedSize !== file.size || transfer.expectedSha256 !== sha256) throw new ApiResponseError('Storage transfer');
  if (transfer.status === 'confirmed' && transfer.result) { uploads.delete(key); return transfer.result; }
  const metadata = `/api/storage/transfers/${encodeURIComponent(transfer.id)}`;
  try {
    if (transfer.status !== 'verifying') {
      if (transfer.mode === 'single') {
        if (!transfer.request) throw new ApiResponseError(metadata);
        try { await uploadSigned(transfer.request, file, options); }
        catch (error) {
          if (!(error instanceof ApiError) || error.status !== 403) throw error;
          transfer = await transferPost(org, metadata + '/refresh', {}, options.signal);
          if (!transfer.request) throw new ApiResponseError(metadata);
          await uploadSigned(transfer.request, file, options);
        }
      } else {
        if (!integer(transfer.partSize) || transfer.partSize < 5242880 || !integer(transfer.totalParts)) throw new ApiResponseError(metadata);
        const done = new Set(transfer.completedParts.map(part => part.partNumber));
        let uploaded = transfer.completedParts.reduce((sum, part) => sum + part.sizeBytes, 0);
        for (let number = 1; number <= transfer.totalParts; number++) {
          options.signal?.throwIfAborted();
          if (epoch !== coreAdminSession.epoch()) throw new SupersededOperationError();
          if (done.has(number)) continue;
          const part = file.slice((number - 1) * transfer.partSize, Math.min(number * transfer.partSize, file.size));
          const partSha = await hashBlob(part, { signal: options.signal });
          const sign = async (): Promise<SignedRequest> => {
            const value = await coreAdminHttp.request<unknown>(metadata + '/parts', { method: 'POST', headers: { 'X-Education-Organization': org }, signal: options.signal,
              body: { parts: [{ partNumber: number, sha256: partSha, sizeBytes: part.size }] } });
            if (!record(value) || !Array.isArray(value.items) || !record(value.items[0]) || !signedRequestGuard(value.items[0].request)) throw new ApiResponseError(metadata);
            return value.items[0].request;
          };
          const progress = (value: { loaded: number }) => options.progress?.({ loaded: uploaded + value.loaded, total: file.size, phase: 'uploading' });
          try { await uploadSigned(await sign(), part, { signal: options.signal, progress }); }
          catch (error) {
            if (!(error instanceof ApiError) || error.status !== 403) throw error;
            await uploadSigned(await sign(), part, { signal: options.signal, progress });
          }
          uploaded += part.size;
          options.progress?.({ loaded: uploaded, total: file.size, phase: 'uploading' });
        }
      }
    }
    options.progress?.({ loaded: file.size, total: file.size, phase: 'verifying' });
    const result = await transferPost(org, metadata + '/complete', {}, options.signal);
    if (result.status !== 'confirmed' || !result.result) throw new ApiResponseError(metadata);
    uploads.delete(key);
    return result.result;
  } catch (error) {
    if (options.signal?.aborted) {
      uploads.delete(key);
      void transferPost(org, metadata + '/abort', {}).catch(() => undefined);
    }
    throw error;
  }
}

export const storageApi = {
  space: (org: string, signal?: AbortSignal) => get('/api/storage/space', org, spaceGuard, signal),
  files: (org: string, offset = 0, signal?: AbortSignal) => get(`/api/storage/files?offset=${offset}`, org, pageGuard(fileGuard), signal),
  allFiles: async (org: string, signal?: AbortSignal): Promise<StorageFile[]> => {
    const items: StorageFile[] = []; let offset = 0;
    do { const page = await storageApi.files(org, offset, signal); items.push(...page.items); if (page.nextOffset === null) return items; if (page.nextOffset <= offset) throw new ApiResponseError('/api/storage/files'); offset = page.nextOffset; } while (!signal?.aborted);
    throw new DOMException('Aborted', 'AbortError');
  },
  renameFile: (org: string, path: string, newPath: string) => post('/api/storage/files/rename', org, { path, newPath }, fileGuard),
  conflicts: (org: string, offset = 0, signal?: AbortSignal) => get(`/api/storage/conflicts?offset=${offset}`, org, pageGuard(conflictGuard), signal),
  resolveConflict: (org: string, id: string) => post(`/api/storage/conflicts/${encodeURIComponent(id)}/resolve`, org, { resolution: 'keep_server' }, conflictGuard),
  fileVersions: (org: string, path: string, signal?: AbortSignal) => get(`/api/storage/files/versions?path=${encodeURIComponent(path)}`, org, (v): v is { items: FileVersion[] } => record(v) && Array.isArray(v.items) && v.items.every(versionGuard), signal),
  restoreFile: (org: string, path: string, version: number) => post('/api/storage/files/restore', org, { path, version }, fileGuard),
  deleteFile: async (org: string, path: string): Promise<{ path: string; deleted: boolean }> => {
    const payload = await coreAdminHttp.request<unknown>('/api/storage/files', {
      method: 'DELETE',
      headers: { 'X-Education-Organization': org },
      body: JSON.stringify({ path }),
    });
    if (record(payload) && payload.path === path && payload.deleted === true) return { path, deleted: true };
    throw new ApiResponseError('/api/storage/files');
  },
  ticket: (org: string, purpose: 'upload' | 'download', path: string, expected: { expectedSize?: number; expectedSha256?: string } = {}) => post('/api/storage/tickets', org, { purpose, path, ...expected }, ticketGuard),
  uploadFile: uploadStorageFile,
  downloadFile: async (org: string, path: string, signal?: AbortSignal): Promise<void> => {
    const epoch = coreAdminSession.epoch();
    const ticket = await storageApi.ticket(org, 'download', path);
    const transfer = await transferPost(org, '/api/storage/transfers', { ticketId: ticket.id, token: ticket.token }, signal);
    if (epoch !== coreAdminSession.epoch()) throw new SupersededOperationError();
    if (!transfer.request || transfer.direction !== 'download') throw new ApiResponseError('Storage transfer');
    downloadInBrowser(transfer.request, path.split('/').pop() ?? 'file');
  },
};
