import { onScopeDispose, ref, shallowRef, watch, type WatchSource } from 'vue';
import { coreAdminSession, coreAdminSessionEpoch } from '@/core/admin/session';
import { ApiError, RequestAbortedError, SupersededOperationError } from '@/shared/api/errors';

export function useHomeworkResource<T>(organization: () => string, load: (org: string, signal: AbortSignal) => Promise<T>, dependencies: WatchSource[] = []) {
  const data = shallowRef<T | null>(null), loading = ref(false), error = shallowRef<unknown>(null);
  let generation = 0, controller: AbortController | null = null;
  async function refresh(): Promise<void> {
    controller?.abort(); controller = new AbortController(); const signal = controller.signal;
    const org = organization(), epoch = coreAdminSession.epoch(), version = ++generation;
    data.value = null; error.value = null; loading.value = !!org && !!coreAdminSession.accessToken();
    if (!org || !coreAdminSession.accessToken()) return;
    const current = () => !signal.aborted && generation === version && organization() === org && coreAdminSession.epoch() === epoch && !!coreAdminSession.accessToken();
    try { const value = await load(org, signal); if (current()) data.value = value; }
    catch (failure) { if (current() && !(failure instanceof RequestAbortedError) && !(failure instanceof SupersededOperationError)) error.value = failure; }
    finally { if (current()) loading.value = false; }
  }
  watch([organization, coreAdminSessionEpoch, ...dependencies], () => { void refresh(); }, { immediate: true, flush: 'sync' });
  onScopeDispose(() => { generation++; controller?.abort(); });
  return { data, loading, error, refresh };
}
export function useHomeworkMutation(organization: () => string, identity: () => string = () => '') {
  const busy = ref(false), error = shallowRef<unknown>(null), uncertain = ref(false);
  let generation = 0, active = true;
  watch([organization, identity, coreAdminSessionEpoch], () => { generation++; busy.value = false; error.value = null; uncertain.value = false; }, { flush: 'sync' });
  onScopeDispose(() => { active = false; generation++; });
  async function run<T>(action: () => Promise<T>, accept: (result: T) => unknown | Promise<unknown>): Promise<void> {
    if (busy.value || uncertain.value) return;
    const version = generation; busy.value = true; error.value = null;
    const current = () => active && generation === version;
    try { const result = await action(); if (current()) await accept(result); }
    catch (failure) { if (current() && !(failure instanceof SupersededOperationError)) { error.value = failure; uncertain.value = !(failure instanceof ApiError && failure.status < 500); } }
    finally { if (current()) busy.value = false; }
  }
  return { busy, error, uncertain, run };
}
