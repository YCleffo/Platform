import { beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminHttp } from './adminHttp';
import { coreCredentials } from './credentials';
import { ApiResponseError } from '../errors';
vi.mock('./adminHttp', () => ({ coreAdminHttp: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() } }));
const key = { id: 'key', label: null, createdAt: '2026-11-03', lastUsedAt: null, transports: ['internal'], backupEligible: true, backupState: false };
const identity = { provider: 'google', email: null, emailVerified: false, displayName: 'Name', avatarUrl: null, linkedAt: '2026-11-03', lastLoginAt: null };
beforeEach(() => vi.resetAllMocks());
describe('Core credential contracts', () => {
  it('validates camelCase safe lists and rejects incomplete credentials', async () => {
    vi.mocked(coreAdminHttp.get).mockResolvedValueOnce({ items: [key] }).mockResolvedValueOnce({ items: [identity] });
    expect(await coreCredentials.passkeys()).toEqual([key]); expect(await coreCredentials.identities()).toEqual([identity]);
    vi.mocked(coreAdminHttp.get).mockResolvedValue({ items: [{ id: 'only-id' }] }); await expect(coreCredentials.passkeys()).rejects.toBeInstanceOf(ApiResponseError);
  });
  it('keeps linking available for an enabled provider even when provider login is disabled', async () => {
    vi.mocked(coreAdminHttp.get).mockResolvedValue({ items: [{ code: 'google', loginEnabled: false }, { code: 'unsupported', loginEnabled: true }] });
    expect(await coreCredentials.providers()).toEqual([{ code: 'google', loginEnabled: false }]);
  });
  it('escapes IDs, preserves a null label and checks mutation acknowledgements', async () => {
    vi.mocked(coreAdminHttp.patch).mockResolvedValue(key); await coreCredentials.rename('id/other', null);
    expect(coreAdminHttp.patch).toHaveBeenCalledWith('/auth/me/passkeys/id%2Fother', { label: null });
    vi.mocked(coreAdminHttp.delete).mockResolvedValue({ status: 'wrong' }); await expect(coreCredentials.revoke('key')).rejects.toBeInstanceOf(ApiResponseError);
  });
  it('rejects a callback for the wrong provider and URL-encodes the authorization code', async () => {
    vi.mocked(coreAdminHttp.get).mockResolvedValue({ ...identity, provider: 'telegram' });
    await expect(coreCredentials.completeLink('google', 'state&x', 'one?two')).rejects.toBeInstanceOf(ApiResponseError);
    expect(coreAdminHttp.get).toHaveBeenCalledWith('/auth/me/identities/google/link/callback?state=state%26x&code=one%3Ftwo');
  });
});
