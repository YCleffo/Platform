import { beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { coreAdminSession } from '@/core/admin/session';
import { ApiError, ApiResponseError, SupersededOperationError } from '@/shared/api/errors';
import { storageApi } from './storage';
vi.mock('@/shared/api/core/adminHttp', () => ({ coreAdminHttp: { request: vi.fn() } }));
vi.mock('../fileTransfer', async (original) => ({ ...await original<typeof import('../fileTransfer')>(), hashBlob: vi.fn().mockResolvedValue('a'.repeat(64)) }));
const id = '11111111-2222-3333-4444-555555555555', org = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
const file = { id, path: 'projects/code.txt', version: 2, sizeBytes: 3, sha256: 'a'.repeat(64), createdAt: '2026-03-08T10:00:00Z' };
beforeEach(() => { vi.clearAllMocks(); vi.unstubAllGlobals(); });
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
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith('/api/storage/files/rename', expect.objectContaining({ body: JSON.stringify({ path: 'old.txt', newPath: file.path }) }));
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
});
