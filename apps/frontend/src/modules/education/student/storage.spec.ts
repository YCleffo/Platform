import { beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { coreAdminSession } from '@/core/admin/session';
import { ApiError, ApiResponseError, SupersededOperationError } from '@/shared/api/errors';
import { uploadSigned } from '../fileTransfer';
import { storageApi } from './storage';
vi.mock('@/shared/api/core/adminHttp', () => ({ coreAdminHttp: { request: vi.fn() } }));
vi.mock('../fileTransfer', async (original) => ({
  ...await original<typeof import('../fileTransfer')>(),
  hashBlob: vi.fn().mockResolvedValue('a'.repeat(64)),
  uploadSigned: vi.fn(),
}));
const id = '11111111-2222-3333-4444-555555555555', org = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
const file = { id, path: 'projects/code.txt', version: 2, sizeBytes: 3, sha256: 'a'.repeat(64), createdAt: '2026-03-08T10:00:00Z' };
beforeEach(() => {
  coreAdminSession.clear();
  vi.mocked(coreAdminHttp.request).mockReset();
  vi.mocked(uploadSigned).mockReset();
});
describe('storage operations', () => {
  it('loads every file page and rejects a looping server cursor', async () => {
    vi.mocked(coreAdminHttp.request).mockResolvedValueOnce({ items: [file], limit: 50, offset: 0, nextOffset: 50 }).mockResolvedValueOnce({ items: [{ ...file, path: 'nested/other.txt' }], limit: 50, offset: 50, nextOffset: null });
    expect(await storageApi.allFiles(org)).toHaveLength(2);
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ items: [], limit: 50, offset: 0, nextOffset: 0 });
    await expect(storageApi.allFiles(org)).rejects.toBeInstanceOf(ApiResponseError);
  });
  it('renames using the actual API and validates deletion acknowledgements', async () => {
    vi.mocked(coreAdminHttp.request).mockResolvedValue(file);
    await storageApi.renameFile(org, 'old.txt', file.path);
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith('/storage/files/rename', expect.objectContaining({ body: { path: 'old.txt', newPath: file.path } }));
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ path: 'someone-else.txt', deleted: true });
    await expect(storageApi.deleteFile(org, file.path)).rejects.toBeInstanceOf(ApiResponseError);
  });
  it('preserves server HTTP failure status for file upload errors', async () => {
    vi.mocked(coreAdminHttp.request).mockRejectedValueOnce(new ApiError(409, 'quota_exceeded', 'Quota exceeded'));
    await expect(storageApi.uploadFile(org, 'file.txt', new File(['a'], 'file.txt'))).rejects.toMatchObject({ status: 409, code: 'quota_exceeded' } satisfies Partial<ApiError>);
  });
  it('rejects a completed download after logout', async () => {
    let finish: (value: unknown) => void = () => undefined;
    vi.mocked(coreAdminHttp.request)
      .mockResolvedValueOnce({ id, spaceId: org, purpose: 'download', path: 'file.txt', token: 'ticket', expiresAt: '2030-01-01T00:00:00Z' })
      .mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
    const pending = storageApi.downloadFile(org, 'file.txt');
    await vi.waitFor(() => expect(coreAdminHttp.request).toHaveBeenCalledTimes(2));
    coreAdminSession.clear(); finish({ id, ticketId: id, direction: 'download', status: 'pending', mode: 'single',
      expectedSize: 1, expectedSha256: 'a'.repeat(64), partSize: null, totalParts: null, completedParts: [], result: null,
      request: { url: 'https://objects.example/file', method: 'GET', headers: {}, expiresAt: '2030-01-01T00:00:00Z' } });
    await expect(pending).rejects.toBeInstanceOf(SupersededOperationError);
  });

  it('starts a new transfer on retry when the saved upload has expired', async () => {
    const path = 'file.txt';
    const expiresAt = '2099-01-01T00:00:00Z';
    const ticket = { id, spaceId: org, purpose: 'upload', path, token: 'ticket', expiresAt };
    const transfer = {
      id, ticketId: id, direction: 'upload', status: 'pending', mode: 'single',
      expectedSize: 1, expectedSha256: file.sha256, partSize: null, totalParts: null,
      completedParts: [], result: null,
      request: { url: 'https://objects.example/file', method: 'PUT', headers: {}, expiresAt },
    };
    const result = { path, version: 1, sha256: file.sha256 };
    vi.mocked(coreAdminHttp.request)
      .mockResolvedValueOnce(ticket)
      .mockResolvedValueOnce(transfer)
      .mockResolvedValueOnce({ ...transfer, status: 'expired', request: null })
      .mockResolvedValueOnce(ticket)
      .mockResolvedValueOnce(transfer)
      .mockResolvedValueOnce({ ...transfer, status: 'confirmed', request: null, result });
    vi.mocked(uploadSigned).mockRejectedValueOnce(new ApiError(503, 'storage.unavailable', 'Offline'));
    const content = new File(['a'], path);
    await expect(storageApi.uploadFile(org, path, content)).rejects.toMatchObject({ status: 503 });
    await expect(storageApi.uploadFile(org, path, content)).resolves.toEqual(result);
    expect(vi.mocked(coreAdminHttp.request).mock.calls.map(([route]) => route)).toEqual([
      '/storage/tickets', '/storage/transfers', `/storage/transfers/${id}`,
      '/storage/tickets', '/storage/transfers', `/storage/transfers/${id}/complete`,
    ]);
  });
});
