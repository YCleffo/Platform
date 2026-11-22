import { mount, flushPromises } from '@vue/test-utils';
import { reactive, ref } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import EducationLoginView from '../EducationLoginView.vue';

const mocks = vi.hoisted(() => ({
  auth: undefined as unknown,
  route: { query: {} as Record<string, string> },
  context: vi.fn(),
  replace: vi.fn(),
}));
vi.mock('@/core/admin/adminAuthStore', () => ({ useAdminAuthStore: () => mocks.auth }));
vi.mock('vue-router', () => ({ useRoute: () => mocks.route, useRouter: () => ({ replace: mocks.replace }) }));
vi.mock('../api', () => ({ educationApi: { context: mocks.context } }));
vi.mock('../i18n', () => ({ useEducationI18n: () => ({ t: (key: string) => key, locale: ref('en') }) }));
vi.mock('../resources', () => ({ useEducationError: () => (failure: unknown) => String(failure) }));

beforeEach(() => {
  vi.clearAllMocks();
  const auth = reactive({
    isAuthenticated: false,
    status: 'anonymous',
    login: vi.fn(async () => { auth.isAuthenticated = true; }),
    cancelPendingAuthentication: vi.fn(),
  });
  mocks.auth = auth;
  mocks.route.query = {};
});

describe('existing Core parent login', () => {
  it.each(['/parent/activate', '/education/student-activate'])('opens %s before requesting Education permissions', async (target) => {
    mocks.route.query.redirect = target;
    mocks.context.mockRejectedValue(new Error('Education permission is not granted until activation'));
    const wrapper = mount(EducationLoginView, { global: { stubs: ['RouterLink'], mocks: { $t: (key: string) => key } } });
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(mocks.context).not.toHaveBeenCalled();
    expect(mocks.replace).toHaveBeenCalledWith(target);
    wrapper.unmount();
  });

  it('keeps the normal role-based destination for an already activated parent', async () => {
    mocks.context.mockResolvedValue({ organizations: [{ parent: true }] });
    const wrapper = mount(EducationLoginView, { global: { stubs: ['RouterLink'], mocks: { $t: (key: string) => key } } });
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(mocks.context).toHaveBeenCalledOnce();
    expect(mocks.replace).toHaveBeenCalledWith('/parent/home');
    wrapper.unmount();
  });
});
