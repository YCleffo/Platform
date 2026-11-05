import { effectScope, nextTick, reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiResponseError } from '@/shared/api/errors';
import { useEducationMutation, useEducationResource } from './resources';

const state = vi.hoisted(() => ({ workspace: undefined as unknown }));
vi.mock('./workspace', () => ({ useEducationWorkspace: () => state.workspace }));
vi.mock('./i18n', () => ({ useEducationI18n: () => ({ t: (key: string) => key }) }));
function deferred<T>() { let resolve!: (value: T) => void; const promise = new Promise<T>(accept => { resolve = accept; }); return { promise, resolve }; }

describe('Education asynchronous scope', () => {
  beforeEach(() => { state.workspace = reactive({ organizationId: 'first', revision: 0, reset: vi.fn(), error: null }); });
  it('discards a late response from the previous organization even if transport ignores abort', async () => {
    const first = deferred<string>(); const second = deferred<string>(); const signals: AbortSignal[] = [];
    const scope = effectScope();
    const resource = scope.run(() => useEducationResource((org, signal) => { signals.push(signal); return org === 'first' ? first.promise : second.promise; }))!;
    const workspace = state.workspace as { organizationId: string; revision: number };
    workspace.organizationId = 'second'; workspace.revision++; await nextTick();
    second.resolve('second data'); await second.promise; await nextTick();
    first.resolve('private first data'); await first.promise; await nextTick();
    expect(signals[0]!.aborted).toBe(true); expect(resource.data.value).toBe('second data');
    scope.stop(); expect(signals[1]!.aborted).toBe(true);
  });
  it('does not accept mutation results after the organization changes', async () => {
    const pending = deferred<string>(); const accept = vi.fn(); const scope = effectScope();
    const mutation = scope.run(useEducationMutation)!; const running = mutation.run(() => pending.promise, accept);
    (state.workspace as { revision: number }).revision++;
    pending.resolve('saved elsewhere'); await running; expect(accept).not.toHaveBeenCalled(); scope.stop();
  });
  it('blocks duplicate submission when the write response cannot confirm its outcome', async () => {
    const scope = effectScope(); const mutation = scope.run(useEducationMutation)!;
    const action = vi.fn().mockRejectedValue(new ApiResponseError('/education/groups'));
    await mutation.run(action, vi.fn()); await mutation.run(action, vi.fn());
    expect(action).toHaveBeenCalledTimes(1); expect(mutation.uncertain.value).toBe(true); scope.stop();
  });
});
