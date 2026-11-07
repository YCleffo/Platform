import { afterEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { i18n } from '@/shared/i18n';
import AuthLayout from './AuthLayout.vue';

const router = createRouter({
  history: createMemoryHistory(),
  routes: [
    { path: '/', name: 'catalog', component: { template: '<div>Catalog</div>' } },
  ],
});

describe('AuthLayout', () => {
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('does not intercept contextmenu events so browser context menu remains available on login', async () => {
    const wrapper = mount(AuthLayout, {
      attachTo: document.body,
      global: {
        plugins: [router, i18n],
      },
    });

    try {
      const card = wrapper.find('.auth-layout__card').element;
      const event = new MouseEvent('contextmenu', { bubbles: true, cancelable: true });
      card.dispatchEvent(event);

      // Default browser behavior MUST NOT be prevented on auth/login pages
      expect(event.defaultPrevented).toBe(false);
      // No custom context menu should be rendered
      expect(document.querySelector('.app-context-menu')).toBeNull();
      expect(document.querySelector('[data-menu-id="settings"]')).toBeNull();
    } finally {
      wrapper.unmount();
    }
  });
});

