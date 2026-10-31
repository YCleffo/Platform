import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { ref } from 'vue';
import { createMemoryHistory, createRouter } from 'vue-router';
import { i18n } from '@/shared/i18n';
import InvitationAcceptView from './InvitationAcceptView.vue';

const state = vi.hoisted(() => ({
  declined: false,
  hasToken: false,
}));

vi.mock('./useInvitationAcceptance', () => ({
  useInvitationAcceptance: () => ({
    preview: ref(null),
    loading: ref(false),
    error: ref(null),
    errorMessage: ref(null),
    actionError: ref(null),
    actionErrorMessage: ref(null),
    acting: ref(false),
    declined: ref(state.declined),
    hasToken: ref(state.hasToken),
    load: vi.fn(),
    accept: vi.fn(),
    decline: vi.fn(),
  }),
}));

vi.mock('@/core/auth/authStore', () => ({
  useAuthStore: () => ({ isAuthenticated: true }),
}));

vi.mock('@/core/account/accountStore', () => ({
  useAccountStore: () => ({ loadPage: vi.fn() }),
}));

vi.mock('@/shared/composables/useToast', () => ({
  useToast: () => ({ show: vi.fn() }),
}));

describe('InvitationAcceptView', () => {
  beforeEach(() => {
    state.declined = false;
    state.hasToken = false;
  });

  it('shows the confirmed decline state after the token is cleared', async () => {
    state.declined = true;
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', name: 'catalog', component: { template: '<div />' } },
        { path: '/invitation', name: 'invitation', component: { template: '<div />' } },
      ],
    });
    await router.push('/invitation');
    await router.isReady();

    const wrapper = mount(InvitationAcceptView, {
      global: { plugins: [i18n, router] },
    });
    try {
      expect(wrapper.text()).toContain('Invitation declined.');
      expect(wrapper.text()).not.toContain('Open the invitation link from your email');
    } finally {
      wrapper.unmount();
    }
  });

  it('shows the missing-link state for a normal open without a token', () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/', name: 'catalog', component: { template: '<div />' } }],
    });

    const wrapper = mount(InvitationAcceptView, {
      global: { plugins: [i18n, router] },
    });
    try {
      expect(wrapper.text()).toContain('Open the invitation link from your email');
      expect(wrapper.text()).not.toContain('Invitation declined.');
    } finally {
      wrapper.unmount();
    }
  });
});
