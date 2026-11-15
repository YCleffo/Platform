import { effectScope, nextTick, ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminSession } from '@/core/admin/session';
import { ApiError, ApiResponseError } from '@/shared/api/errors';
import { useHomeworkMutation, useHomeworkResource } from './resources';

function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((accept, fail) => { resolve = accept; reject = fail; });
  return { promise, resolve, reject };
}
const tokens = { accessToken: 'qa-access', refreshToken: 'qa-refresh', accessExpiresAt: '', sessionExpiresAt: '', sessionId: 'qa-session' };
const scopes: ReturnType<typeof effectScope>[] = [];
function scope() { const result = effectScope(); scopes.push(result); return result; }
beforeEach(() => coreAdminSession.set(tokens));
afterEach(() => { scopes.splice(0).forEach(active => active.stop()); coreAdminSession.clear(); });

describe('Homework private reads and submissions', () => {
  it('blocks a second submit while the first request is pending and accepts the saved response once', async () => {
    const pending = deferred<string>(), action = vi.fn(() => pending.promise), accepted = vi.fn();
    const mutation = scope().run(() => useHomeworkMutation(() => 'org', () => 'assignment'))!;
    const first = mutation.run(action, accepted);
    expect(mutation.busy.value).toBe(true);
    await mutation.run(action, accepted);
    expect(action).toHaveBeenCalledTimes(1);
    pending.resolve('SUBMITTED'); await first;
    expect(accepted).toHaveBeenCalledExactlyOnceWith('SUBMITTED');
    expect(mutation.busy.value).toBe(false);
  });

  it('shows a known validation error and allows a corrected answer to be sent', async () => {
    const mutation = scope().run(() => useHomeworkMutation(() => 'org'))!;
    const validation = new ApiError(422, 'education.invalid_input', 'An answer is required.');
    const action = vi.fn().mockRejectedValueOnce(validation).mockResolvedValueOnce('saved');
    const accepted = vi.fn();
    await mutation.run(action, accepted);
    expect(mutation.error.value).toBe(validation);
    expect(mutation.uncertain.value).toBe(false);
    await mutation.run(action, accepted);
    expect(action).toHaveBeenCalledTimes(2);
    expect(accepted).toHaveBeenCalledExactlyOnceWith('saved');
    expect(mutation.error.value).toBeNull();
  });

  it('blocks blind retries if the server response cannot confirm whether submission succeeded', async () => {
    const mutation = scope().run(() => useHomeworkMutation(() => 'org'))!;
    const failure = new ApiResponseError('/education/student/homework/id/submit');
    const action = vi.fn().mockRejectedValue(failure);
    await mutation.run(action, vi.fn()); await mutation.run(action, vi.fn());
    expect(action).toHaveBeenCalledTimes(1);
    expect(mutation.error.value).toBe(failure);
    expect(mutation.uncertain.value).toBe(true);
    expect(mutation.busy.value).toBe(false);
  });

  it('does not apply a private submission result to another assignment or organization', async () => {
    const org = ref('first'), assignment = ref('first-assignment');
    const pending = deferred<string>(), accepted = vi.fn();
    const mutation = scope().run(() => useHomeworkMutation(() => org.value, () => assignment.value))!;
    const running = mutation.run(() => pending.promise, accepted);
    org.value = 'second'; assignment.value = 'second-assignment';
    pending.resolve('private first answer'); await running;
    expect(accepted).not.toHaveBeenCalled();
    expect(mutation.error.value).toBeNull();
    expect(mutation.busy.value).toBe(false);
  });

  it('does not accept a late review or draft response after logout', async () => {
    const pending = deferred<string>(), accepted = vi.fn();
    const mutation = scope().run(() => useHomeworkMutation(() => 'org'))!;
    const running = mutation.run(() => pending.promise, accepted);
    coreAdminSession.clear();
    pending.resolve('private reviewed answer'); await running;
    expect(accepted).not.toHaveBeenCalled();
    expect(mutation.busy.value).toBe(false);
  });

  it('clears the previous organization synchronously and rejects its late transport result', async () => {
    const org = ref('first'), first = deferred<string>(), second = deferred<string>(), signals: AbortSignal[] = [];
    const resource = scope().run(() => useHomeworkResource(() => org.value, (organization, signal) => {
      signals.push(signal); return organization === 'first' ? first.promise : second.promise;
    }))!;
    org.value = 'second';
    expect(resource.data.value).toBeNull();
    expect(signals[0]!.aborted).toBe(true);
    second.resolve('second homework'); await nextTick(); await nextTick();
    first.resolve('secret first homework'); await nextTick(); await nextTick();
    expect(resource.data.value).toBe('second homework');
    expect(resource.error.value).toBeNull();
  });

  it('clears loaded student data on logout and cannot restore it from an old refresh', async () => {
    const pending = deferred<string>(); let calls = 0;
    const resource = scope().run(() => useHomeworkResource(() => 'org', () => ++calls === 1 ? Promise.resolve('private answer') : pending.promise))!;
    await nextTick(); await nextTick(); expect(resource.data.value).toBe('private answer');
    const refresh = resource.refresh();
    coreAdminSession.clear(); expect(resource.data.value).toBeNull();
    pending.resolve('old student answer'); await refresh; await nextTick();
    expect(resource.data.value).toBeNull();
  });

  it('reports read failures and refreshes the reviewed answer from the API', async () => {
    const load = vi.fn().mockRejectedValueOnce(new ApiError(503, 'education.unavailable', 'Unavailable')).mockResolvedValueOnce({ state: 'REVIEWED', comment: 'Accepted.' });
    const resource = scope().run(() => useHomeworkResource(() => 'org', load))!;
    await nextTick(); await nextTick();
    expect(resource.error.value).toBeInstanceOf(ApiError);
    expect(resource.data.value).toBeNull();
    await resource.refresh();
    expect(resource.error.value).toBeNull();
    expect(resource.data.value).toEqual({ state: 'REVIEWED', comment: 'Accepted.' });
    expect(load).toHaveBeenCalledTimes(2);
  });
});
