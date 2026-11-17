import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { defineComponent, reactive, ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminSession } from '@/core/admin/session';
import { i18n } from '@/shared/i18n';
import { parentApi, type ParentChild, type ParentContext } from './api';
import { parentChildKey, useParentWorkspace } from './workspace';
import ParentLayout from './ParentLayout.vue';

const state = vi.hoisted(() => ({ route: undefined as unknown, push: vi.fn(), replace: vi.fn() }));
vi.mock('vue-router', () => ({
  useRoute: () => state.route,
  useRouter: () => ({ push: state.push, replace: state.replace }),
}));
vi.mock('@/core/admin/adminAuthStore', () => ({
  useAdminAuthStore: () => ({ isAuthenticated: true, logout: vi.fn() }),
}));
vi.mock('@/app/theme', () => ({ useTheme: () => ({ isDark: ref(false), toggleTheme: vi.fn() }) }));
vi.mock('../i18n', () => ({
  useEducationI18n: () => ({ t: (key: string) => key, locale: ref('en') }),
}));
vi.mock('./api', () => ({ parentApi: { context: vi.fn() } }));
const first: ParentChild = {
  id: '01900000-0000-7000-8000-000000000001',
  personId: '01900000-0000-7000-8000-000000000002',
  name: 'Child A',
  organizationId: '01900000-0000-7000-8000-000000000003',
  organizationName: 'School A',
  groups: [],
};
const second: ParentChild = {
  ...first,
  id: '01900000-0000-7000-8000-000000000004',
  name: 'Child B',
  organizationId: '01900000-0000-7000-8000-000000000005',
  organizationName: 'School B',
};
const context: ParentContext = {
  personId: first.personId,
  displayName: 'Guardian',
  locale: 'en',
  timezone: 'UTC',
  children: [first, second],
};
const dropdown = defineComponent({
  props: ['modelValue', 'options', 'label'],
  emits: ['update:modelValue'],
  template:
    '<select :aria-label="label" :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><option value=""></option><option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option></select>',
});
const wrappers: VueWrapper[] = [];
function open() {
  const wrapper = mount(ParentLayout, {
    global: {
      plugins: [i18n],
      stubs: {
        AppDropdown: dropdown,
        RouterLink: { template: '<a><slot /></a>' },
        RouterView: true,
      },
    },
  });
  wrappers.push(wrapper);
  return wrapper;
}
beforeEach(() => {
  vi.clearAllMocks();
  setActivePinia(createPinia());
  coreAdminSession.set({
    accessToken: 'access',
    refreshToken: 'refresh',
    accessExpiresAt: '',
    sessionExpiresAt: '',
    sessionId: 'qa',
  });
  state.route = reactive({
    path: '/parent/homework',
    fullPath: '/parent/homework',
    query: { child: second.id, organization: second.organizationId },
  });
  vi.mocked(parentApi.context).mockResolvedValue(context);
});
afterEach(() => {
  wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
  coreAdminSession.clear();
});

describe('parent child navigation', () => {
  it('honors a cross-organization notification link before rendering child content', async () => {
    const wrapper = open();
    await flushPromises();
    expect(useParentWorkspace().selectedKey).toBe(parentChildKey(second));
    expect((wrapper.find('select').element as HTMLSelectElement).value).toBe(
      parentChildKey(second),
    );
    expect(wrapper.find('router-view-stub').exists()).toBe(true);
  });
  it('keeps another child hidden when the requested child is inaccessible', async () => {
    state.route = reactive({
      path: '/parent/homework',
      fullPath: '/parent/homework',
      query: { child: 'unlinked-child', organization: second.organizationId },
    });
    const wrapper = open();
    await flushPromises();
    expect(useParentWorkspace().selectedKey).toBe('');
    expect(useParentWorkspace().child).toBeNull();
    expect(wrapper.find('router-view-stub').exists()).toBe(false);
    expect(wrapper.text()).toContain('education.parentChildUnavailable');
  });
  it('updates navigation history on a child change and follows Back to the earlier child', async () => {
    const wrapper = open();
    await flushPromises();
    await wrapper.find('select').setValue(parentChildKey(first));
    await flushPromises();
    expect(state.push).toHaveBeenCalledWith({
      path: '/parent/homework',
      query: { child: first.id, organization: first.organizationId },
    });
    expect(useParentWorkspace().child?.id).toBe(first.id);
    (state.route as { query: { child: string; organization: string } }).query = {
      child: first.id,
      organization: first.organizationId,
    };
    await flushPromises();
    (state.route as { query: { child: string; organization: string } }).query = {
      child: second.id,
      organization: second.organizationId,
    };
    await flushPromises();
    expect(useParentWorkspace().child?.id).toBe(second.id);
  });
});
