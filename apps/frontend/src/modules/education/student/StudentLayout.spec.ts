import { flushPromises, mount } from '@vue/test-utils';
import { ref } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { coreAdminSession } from '@/core/admin/session';
import { educationApi } from '../api';
import { studentSession } from './session';
import StudentLayout from './StudentLayout.vue';
vi.mock('../i18n', () => ({ useEducationI18n: () => ({ t: (key: string) => key, locale: ref('en') }) }));
vi.mock('../api', () => ({ educationApi: { context: vi.fn() } }));
afterEach(() => { coreAdminSession.clear(); studentSession.organizationId = ''; studentSession.displayName = null; });
describe('student layout context', () => {
  it('loads student membership without a selected teacher organization and clears identity on logout', async () => {
    coreAdminSession.set({ accessToken: 'access', refreshToken: 'refresh', accessExpiresAt: '2099-01-01T00:00:00Z', sessionExpiresAt: '2099-01-01T00:00:00Z', sessionId: 'session' });
    vi.mocked(educationApi.context).mockResolvedValue({ organizations: [{ id: 'org', name: 'School', student: true }], personId: 'person', displayName: 'Student A', locale: 'en', timezone: 'UTC' });
    const wrapper = mount(StudentLayout, { global: { stubs: { RouterLink: true, RouterView: true, EducationState: true, AppBottomNav: true } } }); await flushPromises();
    expect(educationApi.context).toHaveBeenCalledTimes(1);
    expect(studentSession.organizationId).toBe('org'); expect(wrapper.text()).toContain('Student A');
    coreAdminSession.clear(); await flushPromises();
    expect(studentSession.organizationId).toBe(''); expect(studentSession.displayName).toBeNull(); expect(wrapper.text()).not.toContain('Student A');
    expect(educationApi.context).toHaveBeenCalledTimes(1); wrapper.unmount();
  });
});
