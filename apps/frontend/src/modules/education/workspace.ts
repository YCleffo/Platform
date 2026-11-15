import { defineStore } from 'pinia';
import { ref, watch } from 'vue';
import { coreAdminSession, coreAdminSessionEpoch } from '@/core/admin/session';
import { clearEducationCache, invalidateEducationCache, scopeEducationCache } from './cache';
import { setLocale } from '@/shared/i18n';
import { educationApi, type EducationContext, type Header } from './api';

export const useEducationWorkspace = defineStore('educationWorkspace', () => {
  const context = ref<EducationContext | null>(null);
  const organizationId = ref('');
  const header = ref<Header | null>(null);
  const revision = ref(0);
  const loading = ref(false);
  const error = ref<unknown>(null);
  const headerError = ref<unknown>(null);
  let contextController: AbortController | null = null;
  let headerController: AbortController | null = null;

  function reset(): void {
    clearEducationCache(); contextController?.abort(); headerController?.abort();
    revision.value++; context.value = null; organizationId.value = ''; header.value = null;
    error.value = null; headerError.value = null; loading.value = false;
  }
  watch(coreAdminSessionEpoch, reset, { flush: 'sync' });
  function chooseOrganization(id: string): void {
    if (!context.value?.organizations.some(item => item.id === id)) return;
    scopeEducationCache(id); headerController?.abort(); revision.value++; header.value = null; headerError.value = null; organizationId.value = id;
  }
  async function refreshHeader(force = false): Promise<void> {
    if (force) invalidateEducationCache(['lessons']);
    headerController?.abort(); const controller = new AbortController(); headerController = controller;
    const org = organizationId.value; const version = revision.value; const epoch = coreAdminSession.epoch();
    if (!org) return;
    if (context.value?.organizations.find(item => item.id === org)?.parent && !context.value.organizations.some(item => item.id === org && (item.teacher || item.student))) return;
    headerError.value = null;
    try {
      const result = await educationApi.header(org, controller.signal);
      if (controller.signal.aborted || version !== revision.value || epoch !== coreAdminSession.epoch()) return;
      header.value = result; setLocale(result.locale.toLowerCase().startsWith('ru') ? 'ru' : 'en');
    } catch (caught) { if (!controller.signal.aborted && version === revision.value && epoch === coreAdminSession.epoch()) { headerError.value = caught; } }
  }
  watch(organizationId, () => { void refreshHeader(); }, { flush: 'sync' });
  async function loadContext(): Promise<void> {
    contextController?.abort(); const controller = new AbortController(); contextController = controller;
    const epoch = coreAdminSession.epoch(); loading.value = true; error.value = null; context.value = null; header.value = null;
    try {
      const result = await educationApi.context(controller.signal);
      if (controller.signal.aborted || epoch !== coreAdminSession.epoch()) return;
      context.value = result;
      if (result.organizations.some(item => item.id === organizationId.value)) { await refreshHeader(); }
      else if (result.organizations.length === 1) chooseOrganization(result.organizations[0]!.id);
      else organizationId.value = '';
    } catch (caught) { if (!controller.signal.aborted && epoch === coreAdminSession.epoch()) error.value = caught; }
    finally { if (!controller.signal.aborted && epoch === coreAdminSession.epoch()) loading.value = false; }
  }
  return { context, organizationId, header, revision, loading, error, headerError, reset, chooseOrganization, loadContext, refreshHeader };
});
