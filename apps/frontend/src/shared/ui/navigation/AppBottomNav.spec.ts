import { describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import { Building2, LayoutGrid } from '@/shared/ui/icons';
import AppBottomNav from './AppBottomNav.vue';
import { i18n } from '@/shared/i18n';

describe('AppBottomNav', () => {
  it('marks the active route and exposes a navigation landmark', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', name: 'catalog', component: { template: '<div />' } },
        { path: '/cabinet', name: 'cabinet', component: { template: '<div />' } },
      ],
    });
    await router.push('/');
    await router.isReady();

    const wrapper = mount(AppBottomNav, {
      props: {
        items: [
          { label: 'Plans', icon: LayoutGrid, routeName: 'catalog' },
          { label: 'Cabinet', icon: Building2, routeName: 'cabinet' },
        ],
      },
      global: { plugins: [router, i18n] },
    });

    expect(wrapper.get('nav').attributes('aria-label')).toBe('Primary navigation');
    const links = wrapper.findAll('a');
    expect(links).toHaveLength(2);
    expect(links[0]?.attributes('aria-current')).toBe('page');
    expect(links[1]?.attributes('aria-current')).toBeUndefined();
    wrapper.unmount();
  });

  it('uses the route section for a nested page and respects history and item changes', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', name: 'catalog', component: { template: '<div />' } },
        { path: '/cabinet', name: 'cabinet', component: { template: '<div />' } },
        { path: '/cabinet/detail', name: 'detail', meta: { primaryNavigation: 'cabinet' }, component: { template: '<div />' } },
      ],
    });
    await router.push('/');
    const items = [
      { label: 'Plans', icon: LayoutGrid, routeName: 'catalog' },
      { label: 'Cabinet', icon: Building2, routeName: 'cabinet' },
    ];
    const wrapper = mount(AppBottomNav, { props: { items }, global: { plugins: [router, i18n] } });
    try {
      await router.push('/cabinet/detail');
      expect(wrapper.get('[aria-current="page"]').attributes('href')).toBe('/cabinet');
      await wrapper.setProps({ items: [{ ...items[0]!, label: 'Тарифы' }, items[1]!] });
      expect(wrapper.get('[aria-current="page"]').attributes('href')).toBe('/cabinet');
      router.back();
      await flushPromises();
      expect(wrapper.get('[aria-current="page"]').text()).toBe('Тарифы');
      router.forward();
      await flushPromises();
      expect(wrapper.get('[aria-current="page"]').attributes('href')).toBe('/cabinet');
    } finally {
      wrapper.unmount();
    }
  });

  it('measures a drag once and does not suppress a new deliberate click', async () => {
    const items = ['catalog', 'cabinet', 'settings'].map((routeName) => ({
      label: routeName, icon: LayoutGrid, routeName,
    }));
    const router = createRouter({
      history: createMemoryHistory(),
      routes: items.map(({ routeName }) => ({
        path: routeName === 'catalog' ? '/' : `/${routeName}`,
        name: routeName,
        component: { template: '<div />' },
      })),
    });
    await router.push('/cabinet');
    const wrapper = mount(AppBottomNav, { props: { items }, global: { plugins: [router, i18n] } });
    const nav = wrapper.get('nav');
    const list = wrapper.get('.app-bottom-nav__list').element;
    vi.spyOn(list, 'getBoundingClientRect').mockReturnValue(new DOMRect(0, 0, 300, 52));
    wrapper.findAll('a').forEach((link, i) => {
      vi.spyOn(link.element, 'getBoundingClientRect').mockReturnValue(new DOMRect(i * 100, 0, 100, 52));
    });
    window.dispatchEvent(new Event('resize'));
    const drag = async (x: number) => {
      await nav.trigger('mousedown', { button: 0, clientX: 150 });
      window.dispatchEvent(new MouseEvent('mousemove', { clientX: x }));
      window.dispatchEvent(new MouseEvent('mouseup'));
      await flushPromises();
    };
    try {
      await drag(180);
      expect(router.currentRoute.value.name).toBe('cabinet');
      await drag(207);
      expect(router.currentRoute.value.name).toBe('settings');
      const catalog = wrapper.get('a[href="/"]');
      await catalog.trigger('click', { detail: 1 });
      await flushPromises();
      expect(router.currentRoute.value.name).toBe('settings');
      await catalog.trigger('mousedown', { button: 0, clientX: 40 });
      window.dispatchEvent(new MouseEvent('mouseup'));
      await catalog.trigger('click', { detail: 1 });
      await flushPromises();
      expect(router.currentRoute.value.name).toBe('catalog');
    } finally {
      wrapper.unmount();
      vi.restoreAllMocks();
    }
  });
});
