import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { commercialAuth } from '@/core/api';
import { i18n } from '@/shared/i18n';
import SecuritySettingsView from './SecuritySettingsView.vue';
import type { ControlSession } from '@/shared/types';

vi.mock('@/core/api', () => ({
  commercialAuth: {
    mfaStatus: vi.fn(),
    sessions: vi.fn(),
    beginTotpSetup: vi.fn(),
    confirmTotpSetup: vi.fn(),
    reauthenticate: vi.fn(),
  },
  commercialAccount: {
    me: vi.fn(),
    listAccounts: vi.fn(),
    getAccount: vi.fn(),
    listMemberships: vi.fn(),
    createAccount: vi.fn(),
    changeMembershipRole: vi.fn(),
    removeMembership: vi.fn(),
  },
  commercialCatalog: { listPlans: vi.fn() },
}));

const session: ControlSession = {
  id: '018f0000-0000-7000-8000-000000000001',
  issued_at: '2026-10-30T15:00:00Z',
  last_used_at: '2026-10-30T15:05:00Z',
  expires_at: '2026-11-29T15:00:00Z',
  revoked_at: null,
  revoke_reason: null,
  user_agent: 'test',
  authentication_method: 'password_totp',
  primary_authenticated_at: '2026-10-30T15:00:00Z',
  mfa_authenticated_at: '2026-10-30T15:00:00Z',
  current: true,
};

describe('SecuritySettingsView', () => {
  beforeEach(() => {
    vi.resetAllMocks();
  });

  it('renders real MFA status and the current session', async () => {
    vi.mocked(commercialAuth.mfaStatus).mockResolvedValue({
      enabled: true,
      factor_id: '018f0000-0000-7000-8000-000000000002',
      recovery_codes_remaining: 7,
    });
    vi.mocked(commercialAuth.sessions).mockResolvedValue({ items: [session] });

    const wrapper = mount(SecuritySettingsView, {
      attachTo: document.body,
      global: { plugins: [i18n] },
    });
    try {
      await vi.waitFor(() => expect(wrapper.text()).toContain('Enabled'));
      expect(wrapper.text()).toContain('7 recovery codes remaining');
      expect(wrapper.text()).toContain('Password + authenticator');
      expect(wrapper.text()).toContain('Active session');
    } finally {
      wrapper.unmount();
    }
  });

  it('shows the connect action when MFA is disabled', async () => {
    vi.mocked(commercialAuth.mfaStatus).mockResolvedValue({
      enabled: false,
      factor_id: null,
      recovery_codes_remaining: 0,
    });
    vi.mocked(commercialAuth.sessions).mockResolvedValue({ items: [] });

    const wrapper = mount(SecuritySettingsView, {
      attachTo: document.body,
      global: { plugins: [i18n] },
    });
    try {
      await vi.waitFor(() => expect(wrapper.text()).toContain('Not enabled'));
      expect(wrapper.text()).toContain('Connect authenticator');
      expect(wrapper.text()).toContain('Session details are unavailable.');
    } finally {
      wrapper.unmount();
    }
  });
});
