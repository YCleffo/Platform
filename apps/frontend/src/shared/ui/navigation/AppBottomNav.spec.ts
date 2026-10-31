import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
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
  });
});
