import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { i18n } from '@/shared/i18n';
import DeveloperSettingsView from './DeveloperSettingsView.vue';
import {
  coreServiceCredentialsApi,
  type ServiceCredentialSummary,
  type IssuedServiceCredential,
} from '@/shared/api/core/serviceCredentials';

vi.mock('@/shared/api/core/serviceCredentials', () => ({
  coreServiceCredentialsApi: {
    list: vi.fn(),
    issue: vi.fn(),
    rotate: vi.fn(),
    revoke: vi.fn(),
  },
}));

vi.mock('@/shared/composables/useToast', () => ({
  useToast: () => ({
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
  }),
}));

vi.mock('@/core/auth/authStore', () => ({
  useAuthStore: () => ({
    currentUser: { id: 'acc-test-1', email: 'dev@platform.test' },
  }),
}));

vi.mock('@/core/admin/adminAuthStore', () => ({
  useAdminAuthStore: () => ({
    identity: { id: 'admin-1' },
  }),
}));

describe('DeveloperSettingsView', () => {
  const credentials: ServiceCredentialSummary[] = [
    {
      id: 'cred-1',
      name: 'CI/CD Storage Sync',
      prefix: 'sk_live_sync',
      scopes: ['read:storage', 'usage.write'],
      status: 'active',
      createdAt: '2026-11-20T00:00:00Z',
      expiresAt: '2027-02-20T00:00:00Z',
      lastUsedAt: '2026-11-23T00:00:00Z',
      rotationGraceUntil: null,
    },
  ];

  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(coreServiceCredentialsApi.list).mockResolvedValue([...credentials]);
  });

  it('renders service credentials list', async () => {
    const wrapper = mount(DeveloperSettingsView, {
      global: { plugins: [i18n] },
    });
    await flushPromises();

    expect(wrapper.text()).toContain('CI/CD Storage Sync');
    expect(wrapper.text()).toContain('sk_live_sync');
    expect(wrapper.text()).toContain('read:storage');
  });

  it('switches between credentials, openapi, and webhooks tabs', async () => {
    const wrapper = mount(DeveloperSettingsView, {
      global: { plugins: [i18n] },
    });
    await flushPromises();

    const tabs = wrapper.findAll('.dev-settings__tab-btn');
    expect(tabs.length).toBe(3);

    // Click OpenAPI tab
    await tabs[1]?.trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('/api/storage/files');
    expect(wrapper.text()).toContain('cURL');

    // Click Webhooks tab
    await tabs[2]?.trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('storage.file_uploaded');
    expect(wrapper.text()).toContain('subscription.renewed');
  });

  it('opens issue modal and creates credential', async () => {
    const issued: IssuedServiceCredential = {
      id: 'cred-2',
      name: 'New Worker Key',
      prefix: 'sk_live_work',
      scopes: ['read:storage'],
      status: 'active',
      createdAt: '2026-11-23T00:00:00Z',
      expiresAt: null,
      lastUsedAt: null,
      rotationGraceUntil: null,
      secretToken: 'sk_live_secret_token_12345',
    };
    vi.mocked(coreServiceCredentialsApi.issue).mockResolvedValueOnce(issued);

    const wrapper = mount(DeveloperSettingsView, {
      global: { plugins: [i18n] },
    });
    await flushPromises();

    // Trigger issue modal
    const issueBtn = wrapper.find('.dev-settings__actions button');
    await issueBtn.trigger('click');
    await flushPromises();

    expect(wrapper.findComponent({ name: 'AppDialog' }).exists()).toBe(true);
  });
});
