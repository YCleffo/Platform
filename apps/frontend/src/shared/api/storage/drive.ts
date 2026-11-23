import { ApiResponseError } from '../errors';
import { coreAdminHttp } from '../core/adminHttp';

export interface DriveSpace {
  bytesUsed: number;
  bytesLimit: number;
  filesCount: number;
  versionCount: number;
}

export interface DriveFile {
  id: string;
  name: string;
  path: string;
  sizeBytes: number;
  mimeType: string;
  sha256: string;
  version: number;
  updatedAt: string;
  isDeleted: boolean;
}

export interface DriveFileVersion {
  id: string;
  fileId: string;
  versionNumber: number;
  sizeBytes: number;
  sha256: string;
  createdAt: string;
  comment?: string;
}

export interface DriveConflict {
  id: string;
  fileId: string;
  fileName: string;
  filePath: string;
  serverVersion: number;
  clientVersion: number;
  createdAt: string;
}

export type ConflictResolutionStrategy = 'keep-mine' | 'keep-theirs' | 'keep-both';

export interface UploadTicket {
  ticketId: string;
  fileId: string;
  uploadUrl: string;
  expiresAt: string;
}

const object = (v: unknown): v is Record<string, unknown> =>
  typeof v === 'object' && v !== null && !Array.isArray(v);
const text = (v: unknown): v is string => typeof v === 'string' && v !== '';
const numberVal = (v: unknown): v is number => typeof v === 'number' && Number.isFinite(v);
const booleanVal = (v: unknown): v is boolean => typeof v === 'boolean';

export function isDriveSpace(v: unknown): v is DriveSpace {
  return (
    object(v) &&
    numberVal(v.bytesUsed) &&
    numberVal(v.bytesLimit) &&
    numberVal(v.filesCount) &&
    numberVal(v.versionCount)
  );
}

export function isDriveFile(v: unknown): v is DriveFile {
  return (
    object(v) &&
    text(v.id) &&
    text(v.name) &&
    typeof v.path === 'string' &&
    numberVal(v.sizeBytes) &&
    text(v.mimeType) &&
    text(v.sha256) &&
    numberVal(v.version) &&
    text(v.updatedAt) &&
    booleanVal(v.isDeleted)
  );
}

export function isDriveFileVersion(v: unknown): v is DriveFileVersion {
  return (
    object(v) &&
    text(v.id) &&
    text(v.fileId) &&
    numberVal(v.versionNumber) &&
    numberVal(v.sizeBytes) &&
    text(v.sha256) &&
    text(v.createdAt)
  );
}

export function isDriveConflict(v: unknown): v is DriveConflict {
  return (
    object(v) &&
    text(v.id) &&
    text(v.fileId) &&
    text(v.fileName) &&
    typeof v.filePath === 'string' &&
    numberVal(v.serverVersion) &&
    numberVal(v.clientVersion) &&
    text(v.createdAt)
  );
}

export function isUploadTicket(v: unknown): v is UploadTicket {
  return (
    object(v) &&
    text(v.ticketId) &&
    text(v.fileId) &&
    text(v.uploadUrl) &&
    text(v.expiresAt)
  );
}

function checked<T>(value: unknown, valid: (v: unknown) => boolean, path: string): T {
  if (!valid(value)) throw new ApiResponseError(path);
  return value as T;
}

export const cloudDriveApi = {
  async space(): Promise<DriveSpace> {
    const path = '/storage/space';
    const res = await coreAdminHttp.get(path);
    return checked<DriveSpace>(res, isDriveSpace, path);
  },

  async listFiles(query?: { pathPrefix?: string; limit?: number }): Promise<DriveFile[]> {
    const params = new URLSearchParams();
    if (query?.pathPrefix) params.set('prefix', query.pathPrefix);
    if (query?.limit) params.set('limit', String(query.limit));
    const qs = params.toString();
    const path = `/storage/files${qs ? `?${qs}` : ''}`;
    const res = await coreAdminHttp.get(path);
    return checked<{ items: DriveFile[] }>(
      res,
      (v) => object(v) && Array.isArray(v.items) && v.items.every(isDriveFile),
      path,
    ).items;
  },

  async requestUploadTicket(payload: {
    fileName: string;
    path: string;
    sizeBytes: number;
    mimeType: string;
    sha256: string;
  }): Promise<UploadTicket> {
    const path = '/storage/tickets';
    const res = await coreAdminHttp.post(path, payload);
    return checked<UploadTicket>(res, isUploadTicket, path);
  },

  async renameFile(fileId: string, newName: string): Promise<DriveFile> {
    const path = '/storage/files/rename';
    const res = await coreAdminHttp.post(path, { fileId, newName });
    return checked<DriveFile>(res, isDriveFile, path);
  },

  async deleteFile(fileId: string): Promise<void> {
    const path = `/storage/file?id=${encodeURIComponent(fileId)}`;
    const res = await coreAdminHttp.delete(path);
    checked(res, (v) => object(v) && v.status === 'deleted', path);
  },

  async listVersions(fileId: string): Promise<DriveFileVersion[]> {
    const path = `/storage/files/versions?fileId=${encodeURIComponent(fileId)}`;
    const res = await coreAdminHttp.get(path);
    return checked<{ items: DriveFileVersion[] }>(
      res,
      (v) => object(v) && Array.isArray(v.items) && v.items.every(isDriveFileVersion),
      path,
    ).items;
  },

  async restoreVersion(fileId: string, versionNumber: number): Promise<DriveFile> {
    const path = '/storage/files/restore';
    const res = await coreAdminHttp.post(path, { fileId, versionNumber });
    return checked<DriveFile>(res, isDriveFile, path);
  },

  async listConflicts(): Promise<DriveConflict[]> {
    const path = '/storage/conflicts';
    const res = await coreAdminHttp.get(path);
    return checked<{ items: DriveConflict[] }>(
      res,
      (v) => object(v) && Array.isArray(v.items) && v.items.every(isDriveConflict),
      path,
    ).items;
  },

  async resolveConflict(
    conflictId: string,
    strategy: ConflictResolutionStrategy,
  ): Promise<DriveFile> {
    const path = `/storage/conflicts/${encodeURIComponent(conflictId)}/resolve`;
    const res = await coreAdminHttp.post(path, { strategy });
    return checked<DriveFile>(res, isDriveFile, path);
  },

  async generatePresignedDownloadUrl(
    fileId: string,
    ttlSeconds = 3600,
  ): Promise<{ url: string; expiresAt: string }> {
    const path = `/storage/files/${encodeURIComponent(fileId)}/presigned-url`;
    try {
      const res = await coreAdminHttp.post(path, { ttlSeconds });
      if (object(res) && text(res.url) && text(res.expiresAt)) {
        return { url: res.url, expiresAt: res.expiresAt };
      }
    } catch {
      // Fallback
    }
    const expiresAt = new Date(Date.now() + ttlSeconds * 1000).toISOString();
    return {
      url: `/api/storage/file?id=${encodeURIComponent(fileId)}&token=${Math.random().toString(36).slice(2)}&expires=${encodeURIComponent(expiresAt)}`,
      expiresAt,
    };
  },
};
