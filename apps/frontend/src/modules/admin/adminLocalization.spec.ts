import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import { createMemoryHistory, createRouter } from 'vue-router';
import en from '@/shared/i18n/messages/en';
import ru from '@/shared/i18n/messages/ru';
import AdminRbacView from './AdminRbacView.vue';
import AdminSettingsView from './AdminSettingsView.vue';
import AdminPrivacyView from './AdminPrivacyView.vue';
import AdminResourcePageView from './AdminResourcePageView.vue';

vi.mock('./adminCache', () => ({
  peekAdminCache: () => undefined,
  cachedAdminGet: vi.fn(async () => ({ items: [], total: 0 })),
}));
vi.mock('@/shared/api/core/adminHttp', () => ({ coreAdminHttp: {} }));
vi.mock('@/core/admin/adminAuthStore', () => ({ useAdminAuthStore: () => ({ can: () => true }) }));
vi.mock('@/shared/api/core/configuration', async original => ({ ...await original<typeof import('@/shared/api/core/configuration')>(), coreConfiguration: { settings: vi.fn(async () => []), flags: vi.fn(async () => []), modules: vi.fn(async () => []) } }));

const wrappers: VueWrapper[] = [];
const translations = () => createI18n({ legacy: false, locale: 'en', messages: { en, ru } });
afterEach(() => { for (const wrapper of wrappers.splice(0)) wrapper.unmount(); });

describe('admin locale changes without remounting', () => {
  it('updates RBAC tabs and toolbar actions in both directions', async () => {
    const i18n = translations();
    const wrapper = mount(AdminRbacView, { global: { plugins: [i18n] } });
    wrappers.push(wrapper);
    const assign = wrapper.get('.admin-resource__head').findAll('button')[1]!.element;
    expect(wrapper.get('nav').text()).toContain(en.admin.permissions);
    expect(wrapper.get('.admin-resource__head').text()).toContain(en.admin.assignRole);

    i18n.global.locale.value = 'ru';
    await nextTick();
    expect(wrapper.get('nav').text()).toContain(ru.admin.permissions);
    expect(wrapper.get('.admin-resource__head').text()).toContain(ru.admin.assignRole);
    expect(wrapper.get('.admin-resource__head').text()).not.toContain(en.admin.assignRole);
    expect(wrapper.get('.admin-resource__head').findAll('button')[1]!.element).toBe(assign);
    expect(wrapper.find('.admin-resource__head p').exists()).toBe(false);

    i18n.global.locale.value = 'en';
    await nextTick();
    expect(wrapper.get('.admin-resource__head').text()).toContain(en.admin.revokeRole);
  });

  it('updates settings tab titles and actions', async () => {
    const i18n = translations();
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/settings', component: AdminSettingsView }],
    });
    await router.push('/settings');
    const wrapper = mount(AdminSettingsView, { global: { plugins: [i18n, router] } });
    wrappers.push(wrapper);
    expect(wrapper.text()).toContain(en.configuration.addSetting);
    i18n.global.locale.value = 'ru';
    await nextTick();
    expect(wrapper.text()).toContain(ru.configuration.addSetting);
    expect(wrapper.get('nav').text()).toContain(ru.admin.featureFlags);
  });

  it('updates privacy tabs and advanced operation labels', async () => {
    const i18n = translations();
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/privacy', component: AdminPrivacyView }],
    });
    await router.push('/privacy');
    const wrapper = mount(AdminPrivacyView, { global: { plugins: [i18n, router], stubs: { PrivacyOperatorRequests: true } } });
    wrappers.push(wrapper);
    await wrapper.get('nav').findAll('button')[2]!.trigger('click');
    await flushPromises();
    expect(router.currentRoute.value.query.tab).toBe('advanced');
    expect(wrapper.text()).toContain(en.admin.getErasureCase);
    i18n.global.locale.value = 'ru';
    await nextTick();
    expect(wrapper.text()).toContain(ru.admin.getErasureCase);
    expect(wrapper.text()).not.toContain(en.admin.getErasureCase);
    expect(wrapper.get('nav').text()).toContain(ru.admin.advancedPrivacy);
  });

  it('updates action labels on a resource selected from route metadata', async () => {
    const i18n = translations();
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/accounts', component: AdminResourcePageView, meta: { adminResource: 'accounts' } }],
    });
    await router.push('/accounts');
    const wrapper = mount(AdminResourcePageView, { global: { plugins: [i18n, router] } });
    wrappers.push(wrapper);
    expect(wrapper.text()).toContain(en.admin.accountRoles);
    i18n.global.locale.value = 'ru';
    await nextTick();
    expect(wrapper.text()).toContain(ru.admin.accountRoles);
    expect(wrapper.text()).toContain(ru.admin.assignRole);
  });
});
