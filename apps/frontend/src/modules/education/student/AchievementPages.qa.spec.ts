import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminSession } from '@/core/admin/session';
import { activityApi } from '../activityApi';
import type { Achievement } from '../activityTypes';
import { studentSession } from './session';
import StudentAchievementsView from './StudentAchievementsView.vue';

vi.mock('../i18n', () => ({ useEducationI18n: () => ({ t: (key: string) => key, locale: ref('en') }) }));
vi.mock('../activityApi', () => ({ activityApi: { achievements: vi.fn() } }));
const award: Achievement = { code: 'first-training', name: 'First training', description: 'Complete a verified training.', ruleVersion: 1, unlockedAt: '2026-11-14T12:00:00Z', sourceType: 'attempt', sourceId: 'source', progress: { current: 1, target: 1 } };
const wrappers: VueWrapper[] = [];
function deferred<T>() { let resolve!: (value: T) => void; const promise = new Promise<T>(done => { resolve = done; }); return { promise, resolve }; }
function open() { const wrapper = mount(StudentAchievementsView, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' }, EducationState: true } } }); wrappers.push(wrapper); return wrapper; }
beforeEach(() => {
  vi.clearAllMocks(); studentSession.organizationId = 'school-a';
  coreAdminSession.set({ accessToken: 'student-a', refreshToken: 'refresh', sessionId: 'a', accessExpiresAt: '', sessionExpiresAt: '' });
  vi.mocked(activityApi.achievements).mockResolvedValue({ items: [award, { ...award, code: 'successful-streak', name: 'Learning streak', unlockedAt: null, sourceType: null, sourceId: null, progress: { current: 2, target: 3 } }] });
});
afterEach(() => { wrappers.splice(0).forEach(wrapper => wrapper.unmount()); studentSession.organizationId = ''; coreAdminSession.clear(); });

describe('student achievements independent QA', () => {
  it('renders server awards, conditions, earned date and genuine locked progress', async () => {
    const wrapper = open(); await flushPromises();
    expect(wrapper.text()).toContain('First training'); expect(wrapper.text()).toContain(award.description);
    expect(wrapper.get('time').attributes('datetime')).toBe(award.unlockedAt);
    expect(wrapper.get('progress').attributes('value')).toBe('2'); expect(wrapper.get('progress').attributes('max')).toBe('3');
    expect(wrapper.findAll('.edu-achievement--unlocked')).toHaveLength(1);
    expect(wrapper.find('input, textarea, button').exists()).toBe(false);
  });

  it('clears the former school immediately and rejects its late private awards', async () => {
    const old = deferred<{ items: Achievement[] }>(), current = deferred<{ items: Achievement[] }>();
    vi.mocked(activityApi.achievements).mockReturnValueOnce(old.promise).mockReturnValueOnce(current.promise);
    const wrapper = open(); await flushPromises(); studentSession.organizationId = 'school-b'; await flushPromises();
    current.resolve({ items: [{ ...award, name: 'Current school award' }] }); await flushPromises();
    old.resolve({ items: [{ ...award, name: 'Former school private award' }] }); await flushPromises();
    expect(wrapper.text()).toContain('Current school award'); expect(wrapper.text()).not.toContain('Former school private award');
    expect(vi.mocked(activityApi.achievements).mock.calls.map(args => args[0])).toEqual(['school-a', 'school-b']);
  });

  it('clears earned awards on logout and rejects a late refresh', async () => {
    const pending = deferred<{ items: Achievement[] }>(); vi.mocked(activityApi.achievements).mockReturnValueOnce(pending.promise);
    const wrapper = open(); await flushPromises(); coreAdminSession.clear(); pending.resolve({ items: [award] }); await flushPromises();
    expect(wrapper.text()).not.toContain('First training'); expect(wrapper.find('time').exists()).toBe(false);
  });
});
