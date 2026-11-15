import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminSession } from '@/core/admin/session';
import { activityApi } from '../activityApi';
import type { ActivityExercise, LogicResult } from '../activityTypes';
import LogicTrainer from './LogicTrainer.vue';

vi.mock('../i18n', () => ({ useEducationI18n: () => ({ t: (key: string) => key, locale: ref('en') }) }));
vi.mock('../resources', () => ({ useEducationError: () => () => 'Request failed' }));
vi.mock('../cache', () => ({ invalidateEducationCache: vi.fn() }));
vi.mock('../activityApi', () => ({ activityApi: { startLogic: vi.fn(), startHomework: vi.fn(), attempt: vi.fn(), saveState: vi.fn(), finishLogic: vi.fn() } }));
const org = '019a0000-0000-7000-8000-000000000001', id = '019a0000-0000-7000-8000-000000000002';
function exercise(): ActivityExercise {
  return { attempt: { id, activityCode: 'platform-logic', status: 'running', startedAt: '2026-11-14T12:00:00Z', stateVersion: 0, state: { answers: [], currentRound: 0 } }, exercise: { language: 'en', difficulty: 1, version: 1, rounds: [
    { id: 'round-1', kind: 'sequence', prompt: 'Continue 2, 4, 6', options: [{ id: 'a', label: '8' }, { id: 'b', label: '9' }] },
    { id: 'round-2', kind: 'algorithm', prompt: 'Order the actions', options: [{ id: 'c', label: 'Read then count' }, { id: 'd', label: 'Count then read' }] },
  ] } };
}
const result: LogicResult = { attemptId: id, correctAnswers: 2, totalRounds: 2, errors: 0, score: 100, accuracy: 100, passed: true, rounds: [
  { roundId: 'round-1', optionId: 'a', correctOptionId: 'a', correct: true, explanation: 'Add two at each step.' },
  { roundId: 'round-2', optionId: 'c', correctOptionId: 'c', correct: true, explanation: 'Read the input before counting.' },
] };
const wrappers: VueWrapper[] = [];
function deferred<T>() { let resolve!: (value: T) => void; const promise = new Promise<T>(done => { resolve = done; }); return { promise, resolve }; }
function open(props = {}) { const wrapper = mount(LogicTrainer, { props: { organizationId: org, ...props }, global: { stubs: { ActivityConfigFields: true } } }); wrappers.push(wrapper); return wrapper; }
async function click(wrapper: VueWrapper, key: string) { const button = wrapper.findAll('button').find(node => node.text() === `education.${key}`); expect(button).toBeDefined(); await button!.trigger('click'); await flushPromises(); }
beforeEach(() => {
  vi.clearAllMocks(); coreAdminSession.set({ accessToken: 'student', refreshToken: 'refresh', sessionId: 'student', accessExpiresAt: '', sessionExpiresAt: '' });
  vi.mocked(activityApi.startLogic).mockResolvedValue(exercise()); vi.mocked(activityApi.attempt).mockResolvedValue(exercise());
  vi.mocked(activityApi.finishLogic).mockResolvedValue(result);
  vi.mocked(activityApi.saveState).mockImplementation(async (_org, _id, version, state) => ({ ...exercise(), attempt: { ...exercise().attempt, stateVersion: version + 1, state } }));
});
afterEach(() => { wrappers.splice(0).forEach(wrapper => wrapper.unmount()); coreAdminSession.clear(); });

describe('Logic independent QA persisted answers and identity regressions', () => {
  it('saves each choice with optimistic version and shows explanations only after verified finish', async () => {
    const wrapper = open(); await click(wrapper, 'startTraining');
    expect(wrapper.text()).not.toContain(result.rounds[0]!.explanation);
    await wrapper.get('input[value="a"]').setValue(true); await flushPromises();
    expect(activityApi.saveState).toHaveBeenLastCalledWith(org, id, 0, { answers: [{ roundId: 'round-1', optionId: 'a' }], currentRound: 0 });
    await click(wrapper, 'nextRound');
    await wrapper.get('input[value="c"]').setValue(true); await flushPromises();
    await click(wrapper, 'finishTraining');
    expect(activityApi.finishLogic).toHaveBeenCalledWith(org, id, [{ roundId: 'round-1', optionId: 'a' }, { roundId: 'round-2', optionId: 'c' }]);
    expect(wrapper.text()).toContain(result.rounds[0]!.explanation); expect(wrapper.emitted('finished')).toEqual([[result]]);
  });

  it('restores the persisted round and selected radio without creating a new attempt', async () => {
    const saved = exercise(); saved.attempt.stateVersion = 3; saved.attempt.state = { answers: [{ roundId: 'round-1', optionId: 'a' }, { roundId: 'round-2', optionId: 'c' }], currentRound: 1 };
    vi.mocked(activityApi.attempt).mockResolvedValue(saved);
    const wrapper = open({ attemptId: id }); await flushPromises();
    expect(wrapper.text()).toContain('Order the actions'); expect((wrapper.get('input[value="c"]').element as HTMLInputElement).checked).toBe(true);
    expect(activityApi.startLogic).not.toHaveBeenCalled(); await click(wrapper, 'finishTraining'); expect(activityApi.finishLogic).toHaveBeenCalledTimes(1);
  });

  it('renders an already finished server result with empty cleared state without submitting a new finish', async () => {
    const finished = { ...exercise(), result, attempt: { ...exercise().attempt, status: 'finished', state: {} } };
    vi.mocked(activityApi.attempt).mockResolvedValue(finished);
    const wrapper = open({ attemptId: id }); await flushPromises();
    expect(wrapper.text()).toContain(result.rounds[0]!.explanation);
    expect(activityApi.finishLogic).not.toHaveBeenCalled();
    expect(activityApi.startLogic).not.toHaveBeenCalled();
    expect(wrapper.find('[role="alert"]').exists()).toBe(false);
  });

  it('keeps failed state persistence visible and retries the same version before navigation', async () => {
    vi.mocked(activityApi.saveState).mockRejectedValueOnce(new Error('offline'));
    const wrapper = open(); await click(wrapper, 'startTraining'); await wrapper.get('input[value="a"]').setValue(true); await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('Request failed');
    const next = wrapper.findAll('button').find(node => node.text() === 'education.nextRound')!; expect(next.attributes('disabled')).toBeDefined();
    await click(wrapper, 'retrySave'); expect(vi.mocked(activityApi.saveState).mock.calls[1]).toEqual(vi.mocked(activityApi.saveState).mock.calls[0]);
    expect(wrapper.find('[role="alert"]').exists()).toBe(false);
  });

  it.each(['organization', 'logout', 'user'])('clears saved answers and discards a slow resume after changing %s', async change => {
    const pending = deferred<ActivityExercise>(), replacement = deferred<ActivityExercise>();
    vi.mocked(activityApi.attempt).mockReturnValueOnce(pending.promise).mockReturnValue(replacement.promise);
    const wrapper = open({ attemptId: id }); await flushPromises();
    if (change === 'organization') await wrapper.setProps({ organizationId: 'other-org', attemptId: undefined });
    else if (change === 'logout') coreAdminSession.clear();
    else coreAdminSession.set({ accessToken: 'other-student', refreshToken: 'other', sessionId: 'other', accessExpiresAt: '', sessionExpiresAt: '' });
    // A new session can request its own attempt; it must never display the old response.
    pending.resolve(exercise()); await flushPromises();
    expect(wrapper.emitted('finished')).toBeUndefined();
    expect(wrapper.text()).not.toContain('Continue 2, 4, 6');
  });
});
