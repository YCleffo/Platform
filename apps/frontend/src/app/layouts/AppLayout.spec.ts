import { afterEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory } from 'vue-router';
import { createAppRouter } from '@/app/router';
import { useAuthStore } from '@/core/auth/authStore';
import { beginSession, endSession } from '@/core/auth/session';
import { i18n } from '@/shared/i18n';
import AppLayout from './AppLayout.vue';

afterEach(() => endSession());

describe('primary navigation', () => {
  it('keeps exactly the current section active when leaving the root catalog', async () => {
    const pinia = createPinia();
    setActivePinia(pinia);
    beginSession({ access_token: 'access', refresh_token: 'refresh' });
    useAuthStore().status = 'authenticated';
    const router = createAppRouter(createMemoryHistory());
    await router.push('/');
    const wrapper = mount(AppLayout, {
      global: { plugins: [pinia, router, i18n], stubs: { RouterView: true } },
    });
    try {
      for (const path of ['/', '/cabinet', '/settings', '/settings/security', '/accounts/018f0000-0000-7000-8000-000000000001/members', '/?from=security']) {
        await router.push(path);
        const expected = path.startsWith('/settings') ? '/settings' : path.startsWith('/accounts/') ? '/cabinet' : path.split('?')[0];
        for (const selector of ['.app-layout__nav-link--active', '.app-bottom-nav__link--active']) {
          const active = wrapper.findAll(selector);
          expect(active).toHaveLength(1);
          expect(active[0]?.attributes('href')).toBe(expected);
          expect(active[0]?.attributes('aria-current')).toBe('page');
        }
        expect(wrapper.find('.app-layout__nav .router-link-active').exists()).toBe(false);
      }
    } finally {
      wrapper.unmount();
    }
  });
});
