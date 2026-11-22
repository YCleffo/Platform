import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory } from 'vue-router';
import { createAppRouter } from '@/app/router';
import { useAuthStore } from '@/core/auth/authStore';
import { beginSession, endSession } from '@/core/auth/session';
import { i18n } from '@/shared/i18n';
import AppLayout from './AppLayout.vue';

afterEach(() => {
  endSession();
  vi.unstubAllGlobals();
});

function touch(target: Element, type: string, x: number, y: number, id = 1): void {
  const point = { clientX: x, clientY: y, identifier: id };
  const event = new Event(type, { bubbles: true });
  Object.defineProperties(event, {
    touches: { value: type === 'touchend' || type === 'touchcancel' ? [] : [point] },
    changedTouches: { value: [point] },
  });
  target.dispatchEvent(event);
}

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

  it('switches sections only for an intentional swipe on the mobile page surface', async () => {
    vi.stubGlobal('innerWidth', 393);
    const pinia = createPinia();
    setActivePinia(pinia);
    beginSession({ access_token: 'access', refresh_token: 'refresh' });
    useAuthStore().status = 'authenticated';
    const router = createAppRouter(createMemoryHistory());
    await router.push('/');
    const wrapper = mount(AppLayout, {
      attachTo: document.body,
      global: { plugins: [pinia, router, i18n], stubs: { RouterView: true } },
    });
    const main = wrapper.get('main').element;
    const scroll = document.createElement('div');
    scroll.style.overflowX = 'auto';
    Object.defineProperties(scroll, { scrollWidth: { value: 500 }, clientWidth: { value: 200 } });
    const dialog = document.createElement('div');
    dialog.setAttribute('role', 'dialog');
    const slider = document.createElement('input');
    slider.type = 'range';
    main.append(scroll, dialog, slider);
    try {
      for (const nested of [scroll, dialog, slider]) {
        touch(nested, 'touchstart', 220, 100);
        touch(nested, 'touchend', 120, 100);
      }
      touch(main, 'touchstart', 382, 100);
      touch(main, 'touchend', 220, 100);
      touch(main, 'touchstart', 220, 100);
      touch(main, 'touchmove', 218, 141);
      touch(main, 'touchend', 120, 141);
      touch(main, 'touchstart', 220, 100);
      touch(main, 'touchcancel', 120, 100);
      touch(main, 'touchend', 120, 100);
      await flushPromises();
      expect(router.currentRoute.value.path).toBe('/');

      touch(main, 'touchstart', 220, 100);
      touch(main, 'touchmove', 160, 108);
      touch(main, 'touchend', 120, 108);
      await flushPromises();
      expect(router.currentRoute.value.path).toBe('/cabinet');
    } finally {
      wrapper.unmount();
    }
  });
});
