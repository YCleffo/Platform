import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminSession } from '@/core/admin/session';
import { educationApi } from './api';
import { activityApi } from './activityApi';
import GroupActivitiesView from './GroupActivitiesView.vue';
vi.mock('vue-router', () => ({ useRoute: () => ({ params: { id: 'group-a' } }) }));
vi.mock('./workspace', () => ({ useEducationWorkspace: () => ({ organizationId: 'school-a' }) }));
vi.mock('./i18n', () => ({ useEducationI18n: () => ({ t: (key: string) => key, locale: ref('en') }) }));
vi.mock('./resources', () => ({ useEducationError: () => () => 'Request failed' }));
vi.mock('./api', () => ({ educationApi: { group: vi.fn(), groupStudents: vi.fn(), activityAssignments: vi.fn(), groupActivityResults: vi.fn(), assignActivity: vi.fn() } }));
vi.mock('./activityApi', () => ({ activityApi: { catalog: vi.fn(), groupProgress: vi.fn() } }));
const wrappers: VueWrapper[] = [];
const page = { items: [], limit: 50, offset: 0, nextOffset: null };
beforeEach(() => {
  vi.clearAllMocks();
  coreAdminSession.set({ accessToken: 'teacher-a', refreshToken: 'refresh', sessionId: 'a', accessExpiresAt: '', sessionExpiresAt: '' });
  vi.mocked(educationApi.group).mockResolvedValue({ id: 'group-a', name: 'Group A' });
  vi.mocked(educationApi.groupStudents).mockResolvedValue({ ...page, items: [{ id: 'student-a', personId: 'person-a', name: 'Ada' }] });
  vi.mocked(educationApi.activityAssignments).mockResolvedValue(page);
  vi.mocked(educationApi.groupActivityResults).mockResolvedValue(page);
  vi.mocked(educationApi.assignActivity).mockResolvedValue({ id: 'assigned' } as never);
  vi.mocked(activityApi.catalog).mockResolvedValue({ items: [
    { id: 'typing', code: 'platform-typing', name: 'Platform Typing', kind: 'typing', enabled: true, contractVersion: 2, config: {}, metrics: [], requiredPermission: 'play' },
    { id: 'logic', code: 'platform-logic', name: 'Platform Logic', kind: 'web-game', enabled: true, contractVersion: 1, config: {}, metrics: [], requiredPermission: 'play' },
  ] });
  vi.mocked(activityApi.groupProgress).mockResolvedValue({ items: [], from: '2026-11-01', to: '2026-11-14' });
});
afterEach(() => { wrappers.splice(0).forEach(w => w.unmount()); coreAdminSession.clear(); });
async function open() { const wrapper = mount(GroupActivitiesView, { global: { stubs: { RouterLink: true, ActivityProgressPanel: true, EducationState: true } } }); wrappers.push(wrapper); await flushPromises(); return wrapper; }
describe('teacher activity assignment and period integration', () => {
  it('assigns a server-catalog Logic activity to one group student using Logic settings', async () => {
    const wrapper = await open();
    await wrapper.findAll('select')[0]!.setValue('platform-logic');
    await wrapper.findAll('select')[1]!.setValue('student-a');
    expect(wrapper.text()).toContain('education.rounds');
    expect(wrapper.text()).not.toContain('education.typingMode');
    await wrapper.findAll('select')[3]!.setValue('3');
    await wrapper.findAll('select')[4]!.setValue('10');
    await wrapper.get('input[type="number"]').setValue(2);
    await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(educationApi.assignActivity).toHaveBeenCalledWith('school-a', 'platform-logic', { studentId: 'student-a', config: { language: 'ru', difficulty: 3, roundCount: 10, maxAttempts: 2 } });
    expect(wrapper.text()).toContain('education.activityAssigned');
  });
  it('applies a valid period to both server analytics and attempt history', async () => {
    const wrapper = await open();
    await wrapper.findAll('input[type="date"]')[0]!.setValue('2026-11-01');
    await wrapper.findAll('input[type="date"]')[1]!.setValue('2026-11-14');
    await wrapper.findAll('form')[1]!.trigger('submit'); await flushPromises();
    expect(activityApi.groupProgress).toHaveBeenLastCalledWith('school-a', 'group-a', '2026-11-01', '2026-11-14', 'platform-typing', expect.any(AbortSignal));
    expect(educationApi.groupActivityResults).toHaveBeenLastCalledWith('school-a', 'group-a', 'platform-typing', 0, expect.any(AbortSignal), { from: '2026-11-01', to: '2026-11-14' });
    await wrapper.findAll('input[type="date"]')[0]!.setValue('2026-12-01');
    expect(wrapper.findAll('form')[1]!.get('button').attributes('disabled')).toBeDefined();
  });
});
