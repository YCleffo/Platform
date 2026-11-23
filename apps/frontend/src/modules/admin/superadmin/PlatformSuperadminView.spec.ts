import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { i18n } from '@/shared/i18n';
import PlatformSuperadminView from './PlatformSuperadminView.vue';
import {
  platformAdminApi,
  type PlatformOrganization,
  type PlatformAccountSummary,
  type PlatformSuspiciousSession,
  type ServiceHealthItem,
} from '@/shared/api/core/platformAdmin';

vi.mock('@/shared/api/core/platformAdmin', () => ({
  platformAdminApi: {
    listOrganizations: vi.fn(),
    createOrganization: vi.fn(),
    updateOrganization: vi.fn(),
    listAccounts: vi.fn(),
    moderateAccount: vi.fn(),
    listSuspiciousSessions: vi.fn(),
    revokeSession: vi.fn(),
    getServicesHealth: vi.fn(),
  },
}));

vi.mock('@/shared/composables/useToast', () => ({
  useToast: () => ({
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
  }),
}));

describe('PlatformSuperadminView', () => {
  const orgs: PlatformOrganization[] = [
    {
      id: 'org-test-1',
      name: 'Acme Enterprise',
      slug: 'acme-enterprise',
      status: 'active',
      memberCount: 50,
      accountCount: 12,
      createdAt: '2026-11-20T00:00:00Z',
      updatedAt: '2026-11-20T00:00:00Z',
    },
  ];

  const accounts: PlatformAccountSummary[] = [
    {
      id: 'acc-mod-1',
      organizationId: 'org-test-1',
      organizationName: 'Acme Enterprise',
      email: 'flagged@acme.test',
      displayName: 'Flagged Member',
      moderationStatus: 'pending',
      moderatedAt: null,
      moderationReason: null,
      createdAt: '2026-11-21T00:00:00Z',
    },
  ];

  const sessions: PlatformSuspiciousSession[] = [
    {
      id: 'sess-susp-1',
      accountId: 'acc-mod-1',
      accountEmail: 'flagged@acme.test',
      ipAddress: '198.51.100.99',
      userAgent: 'SuspiciousScript/1.0',
      location: 'Zurich, CH',
      riskScore: 'high',
      suspiciousReason: 'Rapid failed logins',
      lastActiveAt: '2026-11-23T00:00:00Z',
      createdAt: '2026-11-23T00:00:00Z',
    },
  ];

  const healthItems: ServiceHealthItem[] = [
    {
      name: 'PostgreSQL Database',
      status: 'healthy',
      latencyMs: 5,
      details: 'Schema ready',
      lastCheckedAt: '2026-11-23T00:00:00Z',
    },
    {
      name: 'SeaweedFS S3 Storage',
      status: 'healthy',
      latencyMs: 12,
      details: 'Volume ready',
      lastCheckedAt: '2026-11-23T00:00:00Z',
    },
  ];

  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(platformAdminApi.listOrganizations).mockResolvedValue([...orgs]);
    vi.mocked(platformAdminApi.listAccounts).mockResolvedValue([...accounts]);
    vi.mocked(platformAdminApi.listSuspiciousSessions).mockResolvedValue([...sessions]);
    vi.mocked(platformAdminApi.getServicesHealth).mockResolvedValue([...healthItems]);
  });

  it('renders organizations list on initial mount', async () => {
    const wrapper = mount(PlatformSuperadminView, {
      global: { plugins: [i18n] },
    });
    await flushPromises();

    expect(wrapper.text()).toContain('Acme Enterprise');
    expect(wrapper.text()).toContain('acme-enterprise');
    expect(platformAdminApi.listOrganizations).toHaveBeenCalledTimes(1);
  });

  it('switches to accounts tab and shows moderation table', async () => {
    const wrapper = mount(PlatformSuperadminView, {
      global: { plugins: [i18n] },
    });
    await flushPromises();

    const tabs = wrapper.findAll('.superadmin__tab-btn');
    // Tab index 1: accounts moderation
    await tabs[1]?.trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('flagged@acme.test');
    expect(platformAdminApi.listAccounts).toHaveBeenCalledTimes(1);
  });

  it('switches to sessions tab and revokes suspicious session', async () => {
    vi.mocked(platformAdminApi.revokeSession).mockResolvedValueOnce();

    const wrapper = mount(PlatformSuperadminView, {
      global: { plugins: [i18n] },
    });
    await flushPromises();

    const tabs = wrapper.findAll('.superadmin__tab-btn');
    // Tab index 2: suspicious sessions
    await tabs[2]?.trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('198.51.100.99');
    expect(wrapper.text()).toContain('Rapid failed logins');

    const revokeBtn = wrapper.find('.superadmin__align-right button');
    await revokeBtn.trigger('click');
    await flushPromises();

    expect(platformAdminApi.revokeSession).toHaveBeenCalledWith('sess-susp-1');
  });

  it('switches to system health tab and displays status cards', async () => {
    const wrapper = mount(PlatformSuperadminView, {
      global: { plugins: [i18n] },
    });
    await flushPromises();

    const tabs = wrapper.findAll('.superadmin__tab-btn');
    // Tab index 3: health
    await tabs[3]?.trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('PostgreSQL Database');
    expect(wrapper.text()).toContain('SeaweedFS S3 Storage');
    expect(platformAdminApi.getServicesHealth).toHaveBeenCalledTimes(1);
  });
});
