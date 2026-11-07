import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { coreAdminSession } from '@/core/admin/session';
import { ApiResponseError } from '@/shared/api/errors';
import type { Page } from '../../education/api';

export interface StorageSpace { id: string; studentId: string; maxFiles: number; maxBytes: number; fileCount: number; totalBytes: number; createdAt: string }
export interface StorageFile { id: string; path: string; version: number | null; sizeBytes: number | null; sha256: string | null; createdAt: string | null }
export interface TransferTicket { id: string; spaceId: string; purpose: 'upload' | 'download'; path: string; token: string; expiresAt: string }
export interface UploadResult { path: string; version: number; sha256: string }

type Guard<T> = (value: unknown) => value is T;
const record = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null && !Array.isArray(value);
const text = (value: unknown): value is string => typeof value === 'string';
const uuid = (value: unknown): value is string => text(value) && /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i.test(value);
const instant = (value: unknown): value is string => text(value) && Number.isFinite(Date.parse(value)) && value.includes('T');
const integer = (value: unknown): value is number => typeof value === 'number' && Number.isSafeInteger(value) && value >= 0;
const pageGuard = <T>(item: Guard<T>): Guard<Page<T>> => (v): v is Page<T> => record(v) && Array.isArray(v.items) && v.items.length <= 50 && v.items.every(item) && integer(v.limit) && integer(v.offset) && (v.nextOffset === null || integer(v.nextOffset));

const spaceGuard: Guard<StorageSpace> = (v): v is StorageSpace => record(v) && uuid(v.id) && uuid(v.studentId) && integer(v.maxFiles) && integer(v.maxBytes) && integer(v.fileCount) && integer(v.totalBytes) && instant(v.createdAt);
const fileGuard: Guard<StorageFile> = (v): v is StorageFile => record(v) && uuid(v.id) && text(v.path) && (v.version === null || integer(v.version)) && (v.sizeBytes === null || integer(v.sizeBytes)) && (v.sha256 === null || text(v.sha256)) && (v.createdAt === null || instant(v.createdAt));
const ticketGuard: Guard<TransferTicket> = (v): v is TransferTicket => record(v) && uuid(v.id) && uuid(v.spaceId) && ['upload', 'download'].includes(String(v.purpose)) && text(v.path) && text(v.token) && instant(v.expiresAt);
const uploadGuard: Guard<UploadResult> = (v): v is UploadResult => record(v) && text(v.path) && integer(v.version) && text(v.sha256);

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

export const storageApi = {
  space: (org: string, signal?: AbortSignal) => get('/api/storage/space', org, spaceGuard, signal),
  files: (org: string, offset = 0, signal?: AbortSignal) => get(`/api/storage/files?offset=${offset}`, org, pageGuard(fileGuard), signal),
  ticket: (org: string, purpose: 'upload' | 'download', path: string) => post('/api/storage/tickets', org, { purpose, path }, ticketGuard),
  upload: async (org: string, ticket: string, bytes: ArrayBuffer, sha256: string): Promise<UploadResult> => {
    const response = await fetch(`/api/storage/files?ticket=${encodeURIComponent(ticket)}`, {
      method: 'PUT',
      headers: {
        'X-Education-Organization': org,
        'X-Content-Sha256': sha256,
        'Content-Type': 'application/octet-stream',
        Authorization: `Bearer ${coreAdminSession.accessToken()}`,
      },
      body: new Blob([bytes]),
    });
    if (!response.ok) {
      throw new ApiResponseError('/api/storage/files');
    }
    const envelope = (await response.json()) as { data?: unknown };
    if (uploadGuard(envelope.data)) return envelope.data;
    throw new ApiResponseError('/api/storage/files');
  },
  download: async (org: string, ticket: string): Promise<ArrayBuffer> => {
    const response = await fetch(`/api/storage/file?ticket=${encodeURIComponent(ticket)}`, {
      headers: {
        'X-Education-Organization': org,
        Authorization: `Bearer ${coreAdminSession.accessToken()}`,
      },
    });
    if (!response.ok) {
      throw new ApiResponseError('/api/storage/file');
    }
    return response.arrayBuffer();
  },
};
