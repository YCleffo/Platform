import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils';
import { defineComponent } from 'vue';
import { coreAdminSession } from '@/core/admin/session';
import { ApiError, NetworkError } from '@/shared/api/errors';
import { useConfigCollection } from './useConfigCollection';
function deferred<T>() {
  let resolve!: (v: T) => void;
  const promise = new Promise<T>((yes) => (resolve = yes));
  return { resolve, promise };
}
let wrapper: VueWrapper | undefined;
function setup(loader: () => Promise<string[]>, reset = vi.fn()) {
  let state!: ReturnType<typeof useConfigCollection<string>>;
  wrapper = mount(
    defineComponent({
      setup() {
        state = useConfigCollection(loader, reset);
        return () => null;
      },
    }),
  );
  return state;
}
beforeEach(() => coreAdminSession.clear());
afterEach(() => wrapper?.unmount());
describe('configuration operations', () => {
  it('discards old list responses after a newer refresh', async () => {
    const pending = deferred<string[]>();
    const load = vi.fn().mockReturnValueOnce(pending.promise).mockResolvedValue(['new']);
    const state = setup(load);
    const old = state.load();
    await state.load();
    pending.resolve(['old']);
    await old;
    expect(state.items.value).toEqual(['new']);
  });
  it('never applies a successful mutation to a new session', async () => {
    const pending = deferred<string>();
    const state = setup(async () => ['owned']);
    await state.load();
    const apply = vi.fn();
    const op = state.mutate(() => pending.promise, apply);
    coreAdminSession.clear();
    pending.resolve('late');
    await op;
    expect(apply).not.toHaveBeenCalled();
    expect(state.items.value).toEqual([]);
    expect(state.ready.value).toBe(false);
  });
  it('keeps the unknown outcome blocked until a successful explicit read', async () => {
    const loader = vi.fn().mockResolvedValue(['row']);
    const state = setup(loader);
    await state.load();
    await state.mutate(async () => {
      throw new NetworkError();
    }, vi.fn());
    const second = vi.fn();
    await state.mutate(second, vi.fn());
    expect(second).not.toHaveBeenCalled();
    loader.mockRejectedValueOnce(new NetworkError());
    await state.load();
    expect(state.uncertain.value).toBe(true);
    await state.load();
    expect(state.uncertain.value).toBe(false);
    expect(state.ready.value).toBe(true);
  });
  it('retains rows on 503 but clears them and the form on 403', async () => {
    const reset = vi.fn();
    const loader = vi.fn().mockResolvedValue(['private']);
    const state = setup(loader, reset);
    await state.load();
    loader.mockRejectedValueOnce(new ApiError(503, 'unavailable', ''));
    await state.load();
    expect(state.items.value).toEqual(['private']);
    expect(state.ready.value).toBe(false);
    loader.mockRejectedValueOnce(new ApiError(403, 'http.forbidden', ''));
    await state.load();
    expect(state.items.value).toEqual([]);
    expect(reset).toHaveBeenCalled();
  });
  it('does not mark a local validation rejection as an unknown server write', async () => {
    const state = setup(async () => []);
    await state.load();
    await state.mutate(async () => {
      throw new TypeError();
    }, vi.fn());
    expect(state.uncertain.value).toBe(false);
  });
  it('ignores pending responses after unmount', async () => {
    const pending = deferred<string[]>();
    const state = setup(() => pending.promise);
    const op = state.load();
    wrapper!.unmount();
    wrapper = undefined;
    pending.resolve(['secret']);
    await op;
    await flushPromises();
    expect(state.items.value).toEqual([]);
  });
});
