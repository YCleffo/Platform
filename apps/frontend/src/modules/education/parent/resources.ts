import { onScopeDispose, ref, shallowRef, watch, type WatchSource } from 'vue';
import { coreAdminSession, coreAdminSessionEpoch } from '@/core/admin/session';
import { ApiError, RequestAbortedError, SupersededOperationError } from '@/shared/api/errors';
import type { ParentChild } from './api';
import { useParentWorkspace } from './workspace';

export function useParentResource<T>(
  load: (child: ParentChild, signal: AbortSignal) => Promise<T>,
  dependencies: WatchSource[] = [],
) {
  const workspace = useParentWorkspace();
  const data = shallowRef<T | null>(null),
    error = shallowRef<unknown>(null),
    loading = ref(false);
  let generation = 0;
  let controller: AbortController | null = null;
  function clear(): void {
    generation++;
    controller?.abort();
    data.value = null;
    error.value = null;
    loading.value = false;
  }
  watch([() => workspace.selectedKey, () => workspace.revision, coreAdminSessionEpoch], clear, {
    flush: 'sync',
  });
  async function refresh(): Promise<void> {
    clear();
    const child = workspace.child;
    if (!child || !coreAdminSession.accessToken()) return;
    const version = generation;
    const epoch = coreAdminSession.epoch();
    const revision = workspace.revision;
    const request = new AbortController();
    controller = request;
    loading.value = true;
    const current = () =>
      !request.signal.aborted &&
      version === generation &&
      epoch === coreAdminSession.epoch() &&
      revision === workspace.revision;
    try {
      const result = await load(child, request.signal);
      if (current()) data.value = result;
    } catch (failure) {
      if (
        current() &&
        !(failure instanceof RequestAbortedError) &&
        !(failure instanceof SupersededOperationError)
      ) {
        if (failure instanceof ApiError && [403, 404].includes(failure.status)) {
          workspace.denyChild();
        }
        error.value = failure;
      }
    } finally {
      if (current()) loading.value = false;
    }
  }
  watch(
    [() => workspace.selectedKey, () => workspace.revision, ...dependencies],
    () => {
      void refresh();
    },
    { immediate: true },
  );
  onScopeDispose(clear);
  return { data, error, loading, refresh };
}
