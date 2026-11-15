import { onScopeDispose, shallowRef, ref, watch, type WatchSource } from 'vue';
import { ApiError, ApiResponseError, RequestAbortedError, SupersededOperationError } from '@/shared/api/errors';
import { coreAdminSession, coreAdminSessionEpoch } from '@/core/admin/session';
import { educationMeasurements } from './cache';
import { useEducationWorkspace } from './workspace';
import { useEducationI18n } from './i18n';

export function useEducationError() {
  const { t } = useEducationI18n();
  return (error: unknown): string => {
    if (error instanceof ApiResponseError) return t('education.unexpected');
    if (error instanceof ApiError) return t(`education.${error.status === 403 ? 'denied' : error.status === 404 ? 'notFound' : error.status === 409 ? 'conflict' : error.status < 500 ? 'invalid' : 'error'}`);
    return t('education.error');
  };
}

export function useEducationResource<T>(load: (org: string, signal: AbortSignal) => Promise<T>, dependencies: WatchSource[] = []) {
  const workspace = useEducationWorkspace(); const data = shallowRef<T | null>(null); const loading = ref(false); const error = shallowRef<unknown>(null);
  let generation = 0; let controller: AbortController | null = null;
  let scope = '';
  watch([() => workspace.organizationId, () => workspace.revision, coreAdminSessionEpoch], () => {
    scope = ''; generation++; controller?.abort(); data.value = null; error.value = null; loading.value = false;
  }, { flush: 'sync' });
  async function refresh(): Promise<void> {
    controller?.abort(); controller = new AbortController(); const signal = controller.signal;
    const version = ++generation; const epoch = coreAdminSession.epoch(); const org = workspace.organizationId; const revision = workspace.revision;
    const nextScope = `${epoch}:${org}:${revision}`;
    if (scope !== nextScope) data.value = null;
    scope = nextScope; error.value = null; loading.value = !!org;
    if (!org) return;
    const current = () => !signal.aborted && version === generation && epoch === coreAdminSession.epoch() && revision === workspace.revision;
    try { const result = await load(org, signal); if (current()) data.value = result; }
    catch (caught) {
      if (current() && !(caught instanceof RequestAbortedError) && !(caught instanceof SupersededOperationError)) {
        error.value = caught;
        if (caught instanceof ApiError && caught.status === 403) { workspace.reset(); workspace.error = caught; }
      }
    }
    finally { if (current()) loading.value = false; }
  }
  watch([() => workspace.organizationId, () => workspace.revision, ...dependencies], () => { void refresh(); }, { immediate: true });
  onScopeDispose(() => { generation++; controller?.abort(); });
  return { data, loading, error, refresh };
}

export function useEducationMutation() {
  const workspace = useEducationWorkspace(); const busy = ref(false); const error = shallowRef<unknown>(null); const uncertain = ref(false);
  let active = true;
  onScopeDispose(() => { active = false; });
  async function run<T>(action: () => Promise<T>, accept: (result: T) => void | Promise<void>): Promise<void> {
    if (busy.value || uncertain.value) return;
    const started = performance.now();
    const epoch = coreAdminSession.epoch(); const revision = workspace.revision; busy.value = true; error.value = null;
    const current = () => active && epoch === coreAdminSession.epoch() && revision === workspace.revision;
    try { const result = await action(); if (current()) await accept(result); }
    catch (caught) {
      if (current() && !(caught instanceof SupersededOperationError)) {
        error.value = caught; uncertain.value = !(caught instanceof ApiError && caught.status < 500);
        if (caught instanceof ApiError && caught.status === 403) { workspace.reset(); workspace.error = caught; }
      }
    } finally { educationMeasurements.record('action', started); if (current()) busy.value = false; }
  }
  return { busy, error, uncertain, run };
}
