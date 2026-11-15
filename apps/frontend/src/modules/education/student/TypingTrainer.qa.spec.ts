import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminSession } from '@/core/admin/session';
import { homeworkApi } from '../homework/api';
import { studentPortalApi, type TypingExercise, type TypingResult } from './api';
import TypingTrainer from './TypingTrainer.vue';

vi.mock('../i18n', () => ({ useEducationI18n: () => ({ t: (key: string) => key, locale: ref('en') }) }));
vi.mock('../resources', () => ({ useEducationError: () => () => 'Request failed' }));
vi.mock('../cache', () => ({ invalidateEducationCache: vi.fn() }));
vi.mock('./api', () => ({ studentPortalApi: { startAttempt: vi.fn(), finishAttempt: vi.fn() } }));
vi.mock('../homework/api', () => ({ homeworkApi: { startActivity: vi.fn() } }));
const org = '019a0000-0000-7000-8000-000000000001';
const attempt = '019a0000-0000-7000-8000-000000000002';
const started: TypingExercise = { attempt: { id: attempt, status: 'running', startedAt: '2026-11-14T12:00:00Z' }, exercise: { text: 'abc def пример', durationSeconds: 15, language: 'en' } };
const result: TypingResult = { attemptId: attempt, durationSeconds: 15, language: 'en', keystrokes: 3, correctChars: 3, errors: 0, wpm: 2.4, accuracy: 100 };
const wrappers: VueWrapper[] = [];
function deferred<T>() { let resolve!: (value: T) => void; const promise = new Promise<T>(done => { resolve = done; }); return { promise, resolve }; }
function open(props = {}) {
  const wrapper = mount(TypingTrainer, { props: { organizationId: org, ...props }, global: { stubs: { ActivityConfigFields: true } } });
  wrappers.push(wrapper); return wrapper;
}
async function click(wrapper: VueWrapper, key: string) {
  const button = wrapper.findAll('button').find(node => node.text() === `education.${key}`);
  expect(button, `Missing accessible action ${key}`).toBeDefined();
  await button!.trigger('click'); await flushPromises();
}
async function start(wrapper: VueWrapper) { await click(wrapper, 'startTraining'); expect(wrapper.find('textarea').exists()).toBe(true); }
beforeEach(() => {
  vi.clearAllMocks(); vi.useFakeTimers(); vi.setSystemTime(new Date('2026-11-14T12:00:00Z'));
  coreAdminSession.set({ accessToken: 'student-a', refreshToken: 'refresh', sessionId: 'a', accessExpiresAt: '', sessionExpiresAt: '' });
  vi.mocked(studentPortalApi.startAttempt).mockResolvedValue(started);
  vi.mocked(homeworkApi.startActivity).mockResolvedValue(started);
  vi.mocked(studentPortalApi.finishAttempt).mockResolvedValue(result);
});
afterEach(() => { wrappers.splice(0).forEach(wrapper => wrapper.unmount()); coreAdminSession.clear(); vi.useRealTimers(); });

describe('Typing 2 independent QA input and identity regressions', () => {
  it('uses a labelled textarea and native input for touch, deletion and selection replacement', async () => {
    const wrapper = open(); await start(wrapper);
    const input = wrapper.get('textarea');
    expect(input.attributes('aria-label')).toBe('education.typingInput');
    expect(input.attributes('aria-describedby')).toBeTruthy();
    await input.setValue('ax');
    expect(wrapper.findAll('.edu-typing__char--wrong')).toHaveLength(1);
    await input.setValue('a'); // Native deleteContentBackward, no keydown required.
    expect(wrapper.findAll('.edu-typing__char--wrong')).toHaveLength(0);
    await input.setValue('abc');
    input.element.setSelectionRange(1, 2); await input.trigger('select');
    await input.setValue('aZc'); // Replaces a selected character on mobile or desktop.
    expect(wrapper.findAll('.edu-typing__char--wrong')).toHaveLength(1);
    await input.setValue('abc');
    await click(wrapper, 'finishTraining');
    expect(studentPortalApi.finishAttempt).toHaveBeenCalledWith(org, attempt, expect.objectContaining({ typed: 'abc', durationSeconds: 15, corrections: 3 }));
    expect(wrapper.text()).toContain('education.typedCharacters');
  });

  it('ignores intermediate IME input and commits the composed value once', async () => {
    const wrapper = open(); await start(wrapper);
    const input = wrapper.get('textarea');
    await input.trigger('compositionstart');
    input.element.value = 'aX'; input.element.dispatchEvent(new InputEvent('input', { bubbles: true, isComposing: true }));
    await flushPromises();
    expect(wrapper.findAll('.edu-typing__char--wrong')).toHaveLength(0);
    input.element.value = 'abc'; await input.trigger('compositionend');
    await input.trigger('input'); // Browsers may emit a final input after compositionend.
    await click(wrapper, 'finishTraining');
    expect(studentPortalApi.finishAttempt).toHaveBeenCalledWith(org, attempt, expect.objectContaining({ typed: 'abc', corrections: 0, inputErrors: 0 }));
  });

  it('finishes exactly once at the server exercise duration while unfocused', async () => {
    const wrapper = open(); await start(wrapper);
    await wrapper.get('textarea').setValue('abc'); await wrapper.get('textarea').trigger('blur');
    await vi.advanceTimersByTimeAsync(14900); expect(studentPortalApi.finishAttempt).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(200); await flushPromises();
    expect(studentPortalApi.finishAttempt).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(30000); expect(studentPortalApi.finishAttempt).toHaveBeenCalledTimes(1);
    expect(wrapper.emitted('finished')).toEqual([[result]]);
  });

  it('retries a failed save with the same attempt and immutable payload', async () => {
    vi.mocked(studentPortalApi.finishAttempt).mockRejectedValueOnce(new Error('offline'));
    const wrapper = open(); await start(wrapper); await wrapper.get('textarea').setValue('abc');
    await click(wrapper, 'finishTraining'); expect(wrapper.get('[role="alert"]').text()).toContain('Request failed');
    expect(wrapper.get('textarea').attributes('disabled')).toBeDefined();
    await click(wrapper, 'retrySave');
    expect(studentPortalApi.startAttempt).toHaveBeenCalledTimes(1);
    expect(vi.mocked(studentPortalApi.finishAttempt).mock.calls[1]).toEqual(vi.mocked(studentPortalApi.finishAttempt).mock.calls[0]);
    expect(wrapper.emitted('finished')).toHaveLength(1);
    await click(wrapper, 'trainAgain'); expect(studentPortalApi.startAttempt).toHaveBeenCalledTimes(2);
    expect(wrapper.get('textarea').element.value).toBe('');
  });

  it('starts assigned legacy Typing through Homework rather than an unassigned attempt', async () => {
    const wrapper = open({ homeworkId: 'homework-a', assignedConfig: { durationSeconds: 15, language: 'en' } }); await start(wrapper);
    expect(homeworkApi.startActivity).toHaveBeenCalledWith(org, 'homework-a');
    expect(studentPortalApi.startAttempt).not.toHaveBeenCalled();
  });

  it('ignores an old start response after changing organization', async () => {
    const pending = deferred<TypingExercise>(); vi.mocked(studentPortalApi.startAttempt).mockReturnValueOnce(pending.promise);
    const wrapper = open(); await click(wrapper, 'startTraining');
    await wrapper.setProps({ organizationId: 'other-org' }); pending.resolve(started); await flushPromises();
    expect(wrapper.find('textarea').exists()).toBe(false); expect(wrapper.text()).not.toContain('abc def');
    expect(studentPortalApi.finishAttempt).not.toHaveBeenCalled();
  });

  it.each(['logout', 'switch-user'])('clears private input and suppresses stale finish after %s', async action => {
    const pending = deferred<TypingResult>(); vi.mocked(studentPortalApi.finishAttempt).mockReturnValueOnce(pending.promise);
    const wrapper = open(); await start(wrapper); await wrapper.get('textarea').setValue('private learner text');
    await click(wrapper, 'finishTraining');
    if (action === 'logout') coreAdminSession.clear();
    else coreAdminSession.set({ accessToken: 'student-b', refreshToken: 'b', sessionId: 'b', accessExpiresAt: '', sessionExpiresAt: '' });
    await flushPromises(); pending.resolve(result); await flushPromises();
    expect(wrapper.find('textarea').exists()).toBe(false); expect(wrapper.text()).not.toContain('private learner text');
    expect(wrapper.text()).not.toContain('education.trainingResult'); expect(wrapper.emitted('finished')).toBeUndefined();
  });
});
