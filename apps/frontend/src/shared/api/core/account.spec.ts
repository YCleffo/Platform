import { beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminHttp } from './adminHttp';
import { coreAccount, isCoreSettings, type CoreConsentDefinition } from './account';
import { ApiResponseError } from '../errors';
vi.mock('./adminHttp', () => ({ coreAdminHttp: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() } }));
beforeEach(() => vi.resetAllMocks());
const v2 = { schema_version: 2, settings: { interface: { animations: true, transparency: true }, notifications: { browser: true, sound: true, email: false }, privacy: { show_online_status: false } } };
const definition: CoreConsentDefinition = { code: 'example', purpose_code: 'example', current_document_version: 'v1', current_document_sha256: 'a'.repeat(64), withdrawable: true, representative_allowed: false, document: { title: 'Example', text: 'Document' } };
describe('Core account DTO boundary', () => {
  it('accepts v2 and rejects legacy/future versions or string booleans', () => {
    expect(isCoreSettings(v2)).toBe(true); expect(isCoreSettings({ ...v2, schema_version: 1 })).toBe(false); expect(isCoreSettings({ ...v2, schema_version: 3 })).toBe(false);
    expect(isCoreSettings({ ...v2, settings: { ...v2.settings, privacy: { show_online_status: 'false' } } })).toBe(false);
  });
  it('preserves explicit null resets and sends a sparse preference update', async () => {
    vi.mocked(coreAdminHttp.patch).mockResolvedValue({ locale: null, timezone: 'UTC' }); await coreAccount.patchPreferences({ locale: null });
    expect(coreAdminHttp.patch).toHaveBeenCalledWith('/auth/me/preferences', { locale: null });
    vi.mocked(coreAdminHttp.patch).mockResolvedValue(v2); await coreAccount.patchSettings({ privacy: { show_online_status: null } });
    expect(coreAdminHttp.patch).toHaveBeenLastCalledWith('/auth/me/settings', { privacy: { show_online_status: null } });
  });
  it('rejects malformed consent records and normalizes legacy definitions without text', async () => {
    vi.mocked(coreAdminHttp.get).mockResolvedValue({ definitions: [{ ...definition, document: undefined }], consents: [] });
    expect((await coreAccount.consents()).definitions[0]?.document).toBeNull();
    vi.mocked(coreAdminHttp.get).mockResolvedValue({ definitions: [definition], consents: [{ current: 'true' }] }); await expect(coreAccount.consents()).rejects.toBeInstanceOf(ApiResponseError);
  });
  it('binds grant to the reviewed version/hash and rejects a different document in the response', async () => {
    vi.mocked(coreAdminHttp.post).mockResolvedValue({ id: 'id', consent_code: 'example', purpose_code: 'example', document_version: 'v2', document_sha256: 'b'.repeat(64), locale: 'en-US', source: 'self_api', granted_at: '2026-11-03T00:00:00Z', withdrawn_at: null, current: true });
    await expect(coreAccount.grant(definition)).rejects.toBeInstanceOf(ApiResponseError);
    expect(coreAdminHttp.post).toHaveBeenCalledWith('/auth/me/consents/example', { document_version: 'v1', document_sha256: 'a'.repeat(64) });
  });
});
