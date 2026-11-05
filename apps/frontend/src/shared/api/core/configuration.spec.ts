import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminHttp } from './adminHttp';
import { coreConfiguration, isConfigValue } from './configuration';
import { ApiResponseError } from '../errors';
vi.mock('./adminHttp', () => ({
  coreAdminHttp: { get: vi.fn(), put: vi.fn(), post: vi.fn(), delete: vi.fn() },
}));
const setting = {
  id: 'row',
  key: 'ui.title',
  value: { enabled: false, limit: 0 },
  isPublic: false,
  description: null,
  createdAt: '2026-11-04',
  updatedAt: '2026-11-04',
};
beforeEach(() => vi.resetAllMocks());
afterEach(() => vi.unstubAllGlobals());
describe('configuration boundaries', () => {
  it('rejects a module setting returned for another module', async () => {
    vi.mocked(coreAdminHttp.get).mockResolvedValue({ ...setting, moduleCode: 'foreign' });
    await expect(coreConfiguration.setting('ui.title', 'education')).rejects.toBeInstanceOf(
      ApiResponseError,
    );
  });
  it('rejects a detail for another key', async () => {
    vi.mocked(coreAdminHttp.get).mockResolvedValue({ ...setting, key: 'other' });
    await expect(coreConfiguration.setting('ui.title')).rejects.toBeInstanceOf(ApiResponseError);
  });
  it('sends exact typed values and excludes publication from a module PUT', async () => {
    vi.mocked(coreAdminHttp.put).mockResolvedValue({ ...setting, moduleCode: 'education' });
    await coreConfiguration.saveSetting(
      setting.key,
      { value: setting.value, isPublic: true, description: null },
      'education',
    );
    expect(vi.mocked(coreAdminHttp.put).mock.calls[0]?.slice(0, 2)).toEqual([
      '/core/modules/education/settings/ui.title',
      { value: { enabled: false, limit: 0 }, description: null },
    ]);
  });
  it('rejects nested secrets and oversized values before transport', async () => {
    for (const value of [{ nested: { api_key: 'private' } }, 'x'.repeat(16385)])
      await expect(
        coreConfiguration.saveSetting(setting.key, { value, description: null }),
      ).rejects.toBeInstanceOf(TypeError);
    expect(coreAdminHttp.put).not.toHaveBeenCalled();
  });
  it('rejects non-finite numbers and prototype property names', () => {
    expect(isConfigValue(NaN)).toBe(false);
    expect(isConfigValue(JSON.parse('{"__proto__": {"polluted":true}}'))).toBe(false);
  });
  it('accepts backend empty flag config without changing nonempty nested values', async () => {
    const flag = {
      id: 'row',
      code: 'ui.flag',
      moduleCode: null,
      enabled: false,
      rolloutPercent: 0,
      config: [],
      description: null,
      createdAt: '2026-11-04',
      updatedAt: '2026-11-04',
    };
    vi.mocked(coreAdminHttp.get).mockResolvedValue([flag]);
    expect((await coreConfiguration.flags())[0]?.config).toEqual({});
  });
  it('reads the public API without authorization or cookies', async () => {
    const fetch = vi
      .fn()
      .mockResolvedValue(
        new Response(
          JSON.stringify({ status: 'success', data: { settings: { 'ui.title': 'Published' } } }),
        ),
      );
    vi.stubGlobal('fetch', fetch);
    expect(await coreConfiguration.publicSettings()).toEqual({ 'ui.title': 'Published' });
    expect(fetch.mock.calls[0]?.[1]).toMatchObject({
      credentials: 'omit',
      cache: 'no-store',
      headers: { Accept: 'application/json' },
    });
    expect(coreAdminHttp.get).not.toHaveBeenCalled();
  });
  it('rejects an authenticated/private list masquerading as public config', async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValue(new Response(JSON.stringify({ status: 'success', data: [setting] }))),
    );
    await expect(coreConfiguration.publicSettings()).rejects.toBeInstanceOf(ApiResponseError);
  });
});
