import { beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminHttp } from './adminHttp';
import { coreSecurity, isCoreSession } from './security';
import { ApiResponseError } from '../errors';
vi.mock('./adminHttp', () => ({ coreAdminHttp: { get: vi.fn(), post: vi.fn(), delete: vi.fn() } }));
beforeEach(() => vi.resetAllMocks());

describe('Core security HTTP contracts', () => {
  it('uses the Core array response and camelCase status, not Commercial DTOs', async () => {
    vi.mocked(coreAdminHttp.get).mockResolvedValueOnce({ totpEnabled: false, verifiedAt: null, recoveryCodesRemaining: 0 }).mockResolvedValueOnce([]);
    expect((await coreSecurity.status()).totpEnabled).toBe(false); expect(await coreSecurity.sessions()).toEqual([]);
    vi.mocked(coreAdminHttp.get).mockResolvedValue({ items: [] }); await expect(coreSecurity.sessions()).rejects.toBeInstanceOf(ApiResponseError);
  });
  it('rejects incomplete device/location contracts instead of breaking the page', () => {
    expect(isCoreSession({ id: 'id', current: true })).toBe(false);
  });
  it('never turns an incomplete setup or missing recovery codes into success', async () => {
    vi.mocked(coreAdminHttp.post).mockResolvedValueOnce({ secret: 'secret' }).mockResolvedValueOnce({ recoveryCodes: [] });
    await expect(coreSecurity.setup()).rejects.toBeInstanceOf(ApiResponseError);
    await expect(coreSecurity.confirm('setup', '123456')).rejects.toBeInstanceOf(ApiResponseError);
  });
  it('sends the required DELETE body for MFA, with nullable external-only password', async () => {
    await coreSecurity.disable(null, '123456');
    expect(coreAdminHttp.delete).toHaveBeenCalledWith('/auth/mfa/totp', { password: null, code: '123456' });
  });
  it('escapes session identifiers and validates the revoked count', async () => {
    await coreSecurity.revoke('id/other'); expect(coreAdminHttp.delete).toHaveBeenCalledWith('/auth/sessions/id%2Fother');
    vi.mocked(coreAdminHttp.post).mockResolvedValue({ revokedCount: -1 }); await expect(coreSecurity.revokeOthers()).rejects.toBeInstanceOf(ApiResponseError);
  });
});
