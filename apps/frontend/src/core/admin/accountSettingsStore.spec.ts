import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { coreAdminSession } from './session';
import { coreAccount, type CoreSettings } from '@/shared/api/core/account';
import { useCoreAccountSettings } from './accountSettingsStore';
import { SupersededOperationError } from '@/shared/api/errors';
vi.mock('@/shared/api/core/account', () => ({ coreAccount: { settings: vi.fn(), patchSettings: vi.fn() } }));
const settings = (email = false): CoreSettings => ({ schema_version: 2, settings: { interface: { animations: true, transparency: true }, notifications: { browser: true, sound: true, email }, privacy: { show_online_status: false } } });
function deferred<T>() { let resolve!: (value: T) => void; const promise = new Promise<T>(done => { resolve = done; }); return { promise, resolve }; }
beforeEach(() => { setActivePinia(createPinia()); coreAdminSession.clear(); vi.resetAllMocks(); });
describe('Core settings ownership', () => {
  it('shares an initial load and never applies an A response to B or clears B pending work', async () => {
    const store = useCoreAccountSettings(); const a = deferred<CoreSettings>(); const b = deferred<CoreSettings>();
    vi.mocked(coreAccount.settings).mockReturnValueOnce(a.promise).mockReturnValueOnce(b.promise);
    const old = store.load(); const duplicate = store.load(); const oldOutcomes = Promise.allSettled([old, duplicate]); expect(coreAccount.settings).toHaveBeenCalledTimes(1);
    coreAdminSession.clear(); const current = store.load(); a.resolve(settings(true));
    expect((await oldOutcomes).every(result => result.status === 'rejected' && result.reason instanceof SupersededOperationError)).toBe(true);
    expect(store.value).toBeNull(); const duplicateB = store.load(); expect(coreAccount.settings).toHaveBeenCalledTimes(2);
    b.resolve(settings(false)); await Promise.all([current, duplicateB]); expect(store.value?.settings.notifications.email).toBe(false); store.$dispose();
  });
  it('a delayed read cannot overwrite a successfully saved preference', async () => {
    const store = useCoreAccountSettings(); const oldRead = deferred<CoreSettings>(); vi.mocked(coreAccount.settings).mockReturnValue(oldRead.promise);
    const read = store.load(); vi.mocked(coreAccount.patchSettings).mockResolvedValue(settings(true)); await store.patch({ notifications: { email: true } });
    oldRead.resolve(settings(false)); await expect(read).rejects.toBeInstanceOf(SupersededOperationError);
    expect(store.value?.settings.notifications.email).toBe(true); coreAdminSession.clear(); expect(store.value).toBeNull(); store.$dispose();
  });
  it('retains the last saved settings on a failed mutation', async () => {
    const store = useCoreAccountSettings(); vi.mocked(coreAccount.settings).mockResolvedValue(settings()); await store.load();
    vi.mocked(coreAccount.patchSettings).mockRejectedValue(new Error('unavailable')); await expect(store.patch({ notifications: { email: true } })).rejects.toThrow();
    expect(store.value?.settings.notifications.email).toBe(false); store.$dispose();
  });
});
