import { describe, expect, it, vi, beforeEach } from 'vitest';
import {
  cloudDriveApi,
  isDriveSpace,
  isDriveFile,
  isDriveFileVersion,
  isDriveConflict,
  isUploadTicket,
} from './drive';
import { coreAdminHttp } from '../core/adminHttp';
import { ApiResponseError } from '../errors';

vi.mock('../core/adminHttp', () => ({
  coreAdminHttp: {
    get: vi.fn(),
    post: vi.fn(),
    delete: vi.fn(),
  },
}));

describe('cloudDriveApi', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  const validSpace = {
    bytesUsed: 1048576,
    bytesLimit: 10737418240,
    filesCount: 15,
    versionCount: 30,
  };

  const validFile = {
    id: 'file-1',
    name: 'architecture_diagram.pdf',
    path: '/documents/architecture_diagram.pdf',
    sizeBytes: 524288,
    mimeType: 'application/pdf',
    sha256: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    version: 3,
    updatedAt: '2026-11-23T00:00:00Z',
    isDeleted: false,
  };

  const validVersion = {
    id: 'ver-1',
    fileId: 'file-1',
    versionNumber: 2,
    sizeBytes: 512000,
    sha256: 'a1b2c3d4e5f60718293a4b5c6d7e8f90123456789abcdef0123456789abcdef0',
    createdAt: '2026-11-22T00:00:00Z',
  };

  const validConflict = {
    id: 'conflict-1',
    fileId: 'file-1',
    fileName: 'architecture_diagram.pdf',
    filePath: '/documents/architecture_diagram.pdf',
    serverVersion: 3,
    clientVersion: 2,
    createdAt: '2026-11-23T00:00:00Z',
  };

  const validTicket = {
    ticketId: 'ticket-1',
    fileId: 'file-1',
    uploadUrl: 'https://s3.platform.example/uploads/ticket-1',
    expiresAt: '2026-11-23T01:00:00Z',
  };

  it('validates storage entities with runtime guards', () => {
    expect(isDriveSpace(validSpace)).toBe(true);
    expect(isDriveFile(validFile)).toBe(true);
    expect(isDriveFileVersion(validVersion)).toBe(true);
    expect(isDriveConflict(validConflict)).toBe(true);
    expect(isUploadTicket(validTicket)).toBe(true);
    expect(isDriveFile({ ...validFile, sizeBytes: 'NaN' })).toBe(false);
  });

  it('fetches drive space', async () => {
    vi.mocked(coreAdminHttp.get).mockResolvedValueOnce(validSpace);
    const space = await cloudDriveApi.space();
    expect(space.bytesUsed).toBe(1048576);
    expect(coreAdminHttp.get).toHaveBeenCalledWith('/storage/space');
  });

  it('lists drive files with prefix query', async () => {
    vi.mocked(coreAdminHttp.get).mockResolvedValueOnce({ items: [validFile] });
    const files = await cloudDriveApi.listFiles({ pathPrefix: '/documents' });
    expect(files).toHaveLength(1);
    expect(files[0]?.name).toBe('architecture_diagram.pdf');
    expect(coreAdminHttp.get).toHaveBeenCalledWith('/storage/files?prefix=%2Fdocuments');
  });

  it('requests upload ticket', async () => {
    vi.mocked(coreAdminHttp.post).mockResolvedValueOnce(validTicket);
    const ticket = await cloudDriveApi.requestUploadTicket({
      fileName: 'test.pdf',
      path: '/docs/test.pdf',
      sizeBytes: 1024,
      mimeType: 'application/pdf',
      sha256: 'abc123def456',
    });
    expect(ticket.uploadUrl).toContain('s3.platform.example');
  });

  it('resolves conflict using strategy', async () => {
    vi.mocked(coreAdminHttp.post).mockResolvedValueOnce(validFile);
    const resolved = await cloudDriveApi.resolveConflict('conflict-1', 'keep-mine');
    expect(resolved.id).toBe('file-1');
    expect(coreAdminHttp.post).toHaveBeenCalledWith('/storage/conflicts/conflict-1/resolve', {
      strategy: 'keep-mine',
    });
  });

  it('throws on invalid version list response', async () => {
    vi.mocked(coreAdminHttp.get).mockResolvedValueOnce({ items: [{ invalid: true }] });
    await expect(cloudDriveApi.listVersions('file-1')).rejects.toThrow(ApiResponseError);
  });
});
