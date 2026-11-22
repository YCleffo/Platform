import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/shared/api/errors';
import { i18n, setLocale } from '@/shared/i18n';
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

const global = { stubs: ['RouterLink'], plugins: [i18n] };

beforeEach(() => {
  vi.clearAllMocks();
  setLocale('en');
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
    const wrapper = mount(EducationLoginView, { global });
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(mocks.context).not.toHaveBeenCalled();
    expect(mocks.replace).toHaveBeenCalledWith(target);
    wrapper.unmount();
  });

  it('keeps the normal role-based destination for an already activated parent', async () => {
    mocks.context.mockResolvedValue({ organizations: [{ parent: true }] });
    const wrapper = mount(EducationLoginView, { global });
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(mocks.context).toHaveBeenCalledOnce();
    expect(mocks.replace).toHaveBeenCalledWith('/parent/home');
    wrapper.unmount();
  });

  it('retranslates the current login error without losing form input or retrying', async () => {
    const login = (mocks.auth as { login: ReturnType<typeof vi.fn> }).login;
    login.mockRejectedValueOnce(new ApiError(422, 'auth.invalid_credentials', 'Private API detail'));
    const wrapper = mount(EducationLoginView, { global });
    try {
      await wrapper.get('#education-email').setValue('student@example.test');
      await wrapper.get('form').trigger('submit');
      await flushPromises();
      expect(wrapper.get('[role="alert"]').text()).toBe('Check the form fields.');

      await wrapper.get('button[lang="ru"]').trigger('click');
      expect(wrapper.get('[role="alert"]').text()).toBe('Проверьте заполненные поля.');
      expect(wrapper.get('button[lang="ru"]').attributes('aria-pressed')).toBe('true');
      expect(wrapper.get<HTMLInputElement>('#education-email').element.value).toBe('student@example.test');
      expect(login).toHaveBeenCalledOnce();
      expect(document.documentElement.lang).toBe('ru');
    } finally {
      wrapper.unmount();
    }
  });
});
