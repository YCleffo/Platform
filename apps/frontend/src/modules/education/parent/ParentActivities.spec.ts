import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminSession } from '@/core/admin/session';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { ApiResponseError } from '@/shared/api/errors';
import { i18n } from '@/shared/i18n';
import type { Achievement, ActivityProgress } from '../activityTypes';
import { activityExerciseGuard } from '../activityApi';
import { parentApi, type ParentChild, type ParentProgress } from './api';
import { parentChildKey, useParentWorkspace } from './workspace';
import ParentSectionView from './ParentSectionView.vue';

vi.mock('vue-router', () => ({ useRoute: () => ({ meta: { parentSection: 'progress' } }) }));
vi.mock('../i18n', () => ({ useEducationI18n: () => ({ t: (key: string) => key, locale: ref('en') }) }));
vi.mock('@/shared/api/core/adminHttp', () => ({ coreAdminHttp: { request: vi.fn() } }));

const child: ParentChild = {
  id: '01900000-0000-7000-8000-000000000001',
  personId: '01900000-0000-7000-8000-000000000002',
  name: 'Child A',
  organizationId: '01900000-0000-7000-8000-000000000003',
  organizationName: 'School A',
  groups: [],
};
const sibling: ParentChild = { ...child, id: '01900000-0000-7000-8000-000000000004', name: 'Child B' };
const otherOrganization: ParentChild = { ...child, organizationId: '01900000-0000-7000-8000-000000000005', organizationName: 'School B' };
const now = '2026-11-14T12:00:00Z';
const wrappers: VueWrapper[] = [];

function progress(awardName = 'Verified logic achievement'): ParentProgress {
  const award: Achievement = {
    code: 'first-logic', name: awardName, description: 'Finish a verified Logic game.', ruleVersion: 1,
    unlockedAt: now, sourceType: 'attempt', sourceId: child.id, progress: { current: 1, target: 1 },
  };
  const accuracyProgress: Achievement = { ...award, code: 'typing-accuracy', name: 'Accuracy target', unlockedAt: null, sourceType: null, sourceId: null, progress: { current: 71.43, target: 95 } };
  const activities: ActivityProgress = {
    typing: {
      attempts: 1, bestWpm: 42, averageAccuracy: 98,
      history: [{ attemptId: child.id, createdAt: now, language: 'ru', wpm: 42, accuracy: 98, errors: 2 }],
    },
    logic: {
      attempts: 1, bestScore: 400,
      history: [{ attemptId: sibling.id, createdAt: now, language: 'en', score: 400, accuracy: 80, errors: 1, correctAnswers: 4, totalRounds: 5, passed: true }],
    },
    achievements: [award, accuracyProgress], completedAssignments: 1,
  };
  return {
    programs: [], attendance: { present: 2, absent: 0, pending: 0, total: 2 },
    homework: { total: 1, submitted: 1, reviewed: 1, returned: 0 },
    typing: [{ attemptId: child.id, wpm: 42, accuracy: 98 }],
    activities: [{ id: sibling.id, activityName: 'Platform Logic', status: 'finished', finishedAt: now }],
    achievements: [award, accuracyProgress], activityProgress: activities,
  };
}

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => { resolve = done; });
  return { promise, resolve };
}

function open(): VueWrapper {
  const wrapper = mount(ParentSectionView, { global: { plugins: [i18n], stubs: { RouterLink: { template: '<a><slot /></a>' } } } });
  wrappers.push(wrapper);
  return wrapper;
}

beforeEach(() => {
  vi.clearAllMocks();
  setActivePinia(createPinia());
  coreAdminSession.set({ accessToken: 'parent-a', refreshToken: 'refresh', sessionId: 'parent-a', accessExpiresAt: '', sessionExpiresAt: '' });
  const workspace = useParentWorkspace();
  workspace.context = { personId: child.personId, displayName: 'Guardian', locale: 'en', timezone: 'UTC', children: [child, sibling, otherOrganization] };
  workspace.selectedKey = parentChildKey(child);
  vi.mocked(coreAdminHttp.request).mockResolvedValue(progress());
});

afterEach(() => {
  wrappers.splice(0).forEach(wrapper => wrapper.unmount());
  coreAdminSession.clear();
});

describe('parent activity progress integration', () => {
  it('renders verified Typing, Logic charts and earned achievements through a read-only scoped API', async () => {
    const wrapper = open();
    await flushPromises();
    expect(wrapper.text()).toContain('Platform Typing');
    expect(wrapper.text()).toContain('Platform Logic');
    expect(wrapper.text()).toContain('400');
    expect(wrapper.text()).toContain('42');
    expect(wrapper.text()).toContain('Verified logic achievement');
    expect(wrapper.text()).toContain('Finish a verified Logic game.');
    expect(wrapper.get('.edu-achievement time').attributes('datetime')).toBe(now);
    expect(wrapper.findAll('svg[role="img"]')).toHaveLength(3);
    expect(wrapper.findAll('input').map(input => input.attributes('id'))).toEqual(['parent-schedule-from', 'parent-schedule-to']);
    expect(wrapper.find('textarea').exists()).toBe(false);
    expect(wrapper.get('header button').text()).toBe('education.refresh');
    expect(wrapper.get('button[type="submit"]').text()).toBe('education.apply');
    expect(wrapper.html()).not.toMatch(/\/student\/(logic|typing|documents)|education\.(assignActivity|startTraining|finishTraining)/);
    expect(coreAdminHttp.request).toHaveBeenCalledTimes(1);
    expect(coreAdminHttp.request).toHaveBeenCalledWith(
      expect.stringMatching(new RegExp(`^/education/parent/children/${child.id}/progress\\?from=\\d{4}-\\d{2}-\\d{2}&to=\\d{4}-\\d{2}-\\d{2}$`)),
      expect.objectContaining({ method: 'GET', headers: { 'X-Education-Organization': child.organizationId }, signal: expect.any(AbortSignal) }),
    );
    expect(vi.mocked(coreAdminHttp.request).mock.calls.every(([, options]) => options.method === 'GET' && options.body === undefined)).toBe(true);
  });

  it.each([
    ['another child in the same organization', sibling],
    ['the same student identity in another organization', otherOrganization],
  ])('clears old charts and rejects a late refresh after choosing %s', async (_description, selected) => {
    const wrapper = open();
    await flushPromises();
    const oldRefresh = deferred<ParentProgress>();
    const newChild = deferred<ParentProgress>();
    vi.mocked(coreAdminHttp.request).mockReturnValueOnce(oldRefresh.promise).mockReturnValueOnce(newChild.promise);
    await wrapper.get('header button').trigger('click');
    const oldSignal = vi.mocked(coreAdminHttp.request).mock.calls[1]![1].signal;
    useParentWorkspace().chooseChild(parentChildKey(selected));
    await flushPromises();
    expect(wrapper.text()).not.toContain('Verified logic achievement');
    expect(wrapper.find('svg[role="img"]').exists()).toBe(false);
    expect(oldSignal?.aborted).toBe(true);
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith(
      expect.stringContaining(`/parent/children/${selected.id}/progress?`),
      expect.objectContaining({ method: 'GET', headers: { 'X-Education-Organization': selected.organizationId } }),
    );
    newChild.resolve(progress('Current selection award'));
    await flushPromises();
    oldRefresh.resolve(progress('Former selection private award'));
    await flushPromises();
    expect(wrapper.text()).toContain('Current selection award');
    expect(wrapper.text()).not.toContain('Former selection private award');
    expect(wrapper.findAll('svg[role="img"]')).toHaveLength(3);
  });

  it('clears rendered awards and ignores pending private progress when the parent signs out', async () => {
    const wrapper = open();
    await flushPromises();
    expect(wrapper.text()).toContain('Verified logic achievement');
    const pending = deferred<ParentProgress>();
    vi.mocked(coreAdminHttp.request).mockReturnValueOnce(pending.promise);
    await wrapper.get('header button').trigger('click');
    coreAdminSession.clear();
    pending.resolve(progress('Late signed-out award'));
    await flushPromises();
    expect(wrapper.find('svg[role="img"]').exists()).toBe(false);
    expect(wrapper.text()).not.toContain('Verified logic achievement');
    expect(wrapper.text()).not.toContain('Late signed-out award');
    expect(useParentWorkspace().child).toBeNull();
  });

  it('rejects malformed activity histories at the real parent API boundary', async () => {
    expect(activityExerciseGuard({
      attempt: { id: child.id, status: 'running', startedAt: now, stateVersion: 0, state: {} },
      exercise: { language: 'en', rounds: [{ id: 'r1', kind: 'sequence', prompt: '2, 4, ?', options: [{ id: 'o1', label: '6' }, { id: 'o2', label: '7' }] }] },
    })).toBe(true);
    const malformed = progress();
    malformed.activityProgress.logic.history[0]!.accuracy = Number.NaN;
    vi.mocked(coreAdminHttp.request).mockResolvedValue(malformed);
    await expect(parentApi.progress(child)).rejects.toBeInstanceOf(ApiResponseError);
  });
});
