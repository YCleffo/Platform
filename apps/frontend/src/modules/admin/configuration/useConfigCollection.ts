import { ref, shallowRef, watch } from 'vue';
import { coreAdminSessionEpoch } from '@/core/admin/session';
import { ApiError, SupersededOperationError } from '@/shared/api/errors';
import { useAdminOperation } from '../useAdminOperation';

/** No cache: records, forms and pending operations belong to the mounting Core session. */
export function useConfigCollection<T>(
  loader: () => Promise<T[]>,
  reset: () => void = () => undefined,
) {
  const guard = useAdminOperation();
  const mutationGuard = useAdminOperation();
  const items = shallowRef<T[]>([]);
  const loading = ref(false);
  const ready = ref(false);
  const error = shallowRef<unknown>(null);
  const busy = ref(false);
  const actionError = shallowRef<unknown>(null);
  const uncertain = ref(false);
  const saved = ref(false);
  async function load(): Promise<void> {
    const op = guard.begin();
    loading.value = true;
    ready.value = false;
    error.value = null;
    try {
      const data = await loader();
      if (!guard.current(op)) return;
      items.value = data;
      ready.value = true;
      uncertain.value = false;
    } catch (e) {
      if (!guard.current(op)) return;
      error.value = e;
      if (e instanceof ApiError && [401, 403, 404].includes(e.status)) {
        items.value = [];
        reset();
      }
    } finally {
      if (guard.current(op)) loading.value = false;
    }
  }
  async function mutate<R>(action: () => Promise<R>, done: (result: R) => void): Promise<void> {
    if (busy.value || !ready.value || uncertain.value) return;
    const op = mutationGuard.begin();
    busy.value = true;
    actionError.value = null;
    saved.value = false;
    try {
      const result = await action();
      if (!mutationGuard.current(op)) return;
      saved.value = true;
      done(result);
      await load();
    } catch (e) {
      if (!mutationGuard.current(op) || e instanceof SupersededOperationError) return;
      actionError.value = e;
      uncertain.value =
        !(e instanceof TypeError) &&
        !(e instanceof ApiError && e.status >= 400 && e.status < 500 && e.status !== 408);
      if (e instanceof ApiError && [401, 403].includes(e.status)) {
        items.value = [];
        ready.value = false;
        reset();
      }
    } finally {
      if (mutationGuard.current(op)) busy.value = false;
    }
  }
  watch(
    coreAdminSessionEpoch,
    () => {
      guard.begin();
      mutationGuard.begin();
      items.value = [];
      ready.value = false;
      loading.value = false;
      busy.value = false;
      error.value = null;
      actionError.value = null;
      uncertain.value = false;
      saved.value = false;
      reset();
    },
    { flush: 'sync' },
  );
  return { items, loading, ready, error, busy, actionError, uncertain, saved, load, mutate };
}
