import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils';
import { i18n } from '@/shared/i18n';
import { coreAdminSession } from '@/core/admin/session';
import { coreConfiguration } from '@/shared/api/core/configuration';
import { NetworkError } from '@/shared/api/errors';
import ConfigSettingsPanel from './ConfigSettingsPanel.vue';
const permissions = vi.hoisted(() => ({ manage: true }));
vi.mock('@/core/admin/adminAuthStore', () => ({
  useAdminAuthStore: () => ({ can: (p: string) => p.endsWith('.read') || permissions.manage }),
}));
vi.mock('@/shared/api/core/configuration', async (original) => ({
  ...(await original<typeof import('@/shared/api/core/configuration')>()),
  coreConfiguration: {
    settings: vi.fn(),
    setting: vi.fn(),
    saveSetting: vi.fn(),
    removeSetting: vi.fn(),
  },
}));
const row = {
  id: 'row',
  key: 'ui.title',
  value: { count: 0, enabled: false },
  isPublic: false,
  description: 'Existing description',
  createdAt: '2026-11-04',
  updatedAt: '2026-11-04',
};
let wrapper: VueWrapper | undefined;
const buttons = () => Array.from(document.querySelectorAll<HTMLButtonElement>('button'));
async function click(text: string, dialog = false) {
  const b = buttons().find(
    (b) => b.textContent?.trim() === text && (!dialog || b.closest('[role=dialog]')),
  );
  expect(b).toBeDefined();
  b!.click();
  await flushPromises();
}
async function setup() {
  wrapper = mount(ConfigSettingsPanel, { attachTo: document.body, global: { plugins: [i18n] } });
  await flushPromises();
}
beforeEach(() => {
  vi.resetAllMocks();
  coreAdminSession.clear();
  i18n.global.locale.value = 'en';
  permissions.manage = true;
  vi.mocked(coreConfiguration.settings).mockResolvedValue([row]);
  vi.mocked(coreConfiguration.setting).mockResolvedValue(row);
  vi.mocked(coreConfiguration.saveSetting).mockResolvedValue(row);
});
afterEach(() => wrapper?.unmount());
describe('configuration setting forms', () => {
  it('reads the current key before editing and preserves false/zero/description', async () => {
    await setup();
    await click('Edit');
    expect(coreConfiguration.setting).toHaveBeenCalledWith('ui.title', undefined);
    await click('Save changes', true);
    expect(coreConfiguration.saveSetting).toHaveBeenCalledWith(
      'ui.title',
      { value: { count: 0, enabled: false }, description: 'Existing description', isPublic: false },
      undefined,
    );
  });
  it('requires an explicit acknowledgement before publishing', async () => {
    await setup();
    await click('Edit');
    document.querySelector<HTMLInputElement>('#config-public')!.click();
    await flushPromises();
    expect(buttons().find((b) => b.textContent?.trim() === 'Save changes')?.disabled).toBe(true);
    document.querySelector<HTMLInputElement>('#config-ack')!.click();
    await flushPromises();
    await click('Save changes', true);
    expect(coreConfiguration.saveSetting).toHaveBeenCalledWith(
      'ui.title',
      expect.objectContaining({ isPublic: true }),
      undefined,
    );
  });
  it('has no mutation actions for read-only accounts', async () => {
    permissions.manage = false;
    await setup();
    expect(wrapper!.text()).not.toContain('Add setting');
    expect(wrapper!.text()).not.toContain('Delete');
    await click('View details');
    expect(buttons().some((b) => b.textContent?.trim() === 'Save changes')).toBe(false);
  });
  it('retains an uncertain-write block after cancelling and permits review through refresh', async () => {
    vi.mocked(coreConfiguration.saveSetting).mockRejectedValue(new NetworkError());
    await setup();
    await click('Edit');
    await click('Save changes', true);
    expect(document.body.textContent).toContain('The result could not be confirmed.');
    await click('Cancel', true);
    expect(buttons().find((b) => b.textContent?.trim() === 'Add setting')?.disabled).toBe(true);
    await click('Refresh');
    expect(buttons().find((b) => b.textContent?.trim() === 'Add setting')?.disabled).toBe(false);
    expect(coreConfiguration.saveSetting).toHaveBeenCalledTimes(1);
  });
  it('clears the form and private values synchronously on session change', async () => {
    await setup();
    await click('Edit');
    coreAdminSession.clear();
    await flushPromises();
    expect(document.querySelector('[role=dialog]')).toBeNull();
    expect(wrapper!.text()).not.toContain('Existing description');
  });
});
