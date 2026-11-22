import { computed, ref, shallowRef, watch } from 'vue';
import { defineStore } from 'pinia';
import { coreAdminSession, coreAdminSessionEpoch } from '@/core/admin/session';
import { applyContextLocale } from '@/shared/i18n';
import { parentApi, type ParentChild, type ParentContext } from './api';

export const parentChildKey = (child: Pick<ParentChild, 'organizationId' | 'id'>): string =>
  `${child.organizationId}:${child.id}`;

export const useParentWorkspace = defineStore('educationParentWorkspace', () => {
  const context = shallowRef<ParentContext | null>(null);
  const selectedKey = ref('');
  const child = computed(
    () =>
      context.value?.children.find((item) => parentChildKey(item) === selectedKey.value) ?? null,
  );
  const revision = ref(0);
  const loading = ref(false);
  const error = shallowRef<unknown>(null);
  let controller: AbortController | null = null;
  let generation = 0;

  function reset(): void {
    generation++;
    controller?.abort();
    revision.value++;
    context.value = null;
    selectedKey.value = '';
    loading.value = false;
    error.value = null;
  }
  watch(coreAdminSessionEpoch, reset, { flush: 'sync' });

  function chooseChild(key: string): void {
    if (
      key === selectedKey.value ||
      !context.value?.children.some((item) => parentChildKey(item) === key)
    )
      return;
    revision.value++;
    selectedKey.value = key;
  }
  function denyChild(): void {
    const denied = selectedKey.value;
    revision.value++;
    selectedKey.value = '';
    if (context.value)
      context.value = {
        ...context.value,
        children: context.value.children.filter((item) => parentChildKey(item) !== denied),
      };
  }
  function clearSelection(): void {
    if (!selectedKey.value) return;
    revision.value++;
    selectedKey.value = '';
  }
  async function loadContext(preferredKey?: string): Promise<void> {
    controller?.abort();
    const request = new AbortController();
    controller = request;
    const version = ++generation;
    const epoch = coreAdminSession.epoch();
    const previous = preferredKey ?? selectedKey.value;
    revision.value++;
    context.value = null;
    selectedKey.value = '';
    error.value = null;
    loading.value = !!coreAdminSession.accessToken();
    if (!coreAdminSession.accessToken()) return;
    const current = () =>
      !request.signal.aborted && version === generation && epoch === coreAdminSession.epoch();
    try {
      const result = await parentApi.context(request.signal);
      if (!current()) return;
      selectedKey.value = result.children.some((item) => parentChildKey(item) === previous)
        ? previous
        : preferredKey ? '' : result.children[0]
          ? parentChildKey(result.children[0])
          : '';
      // The layout reconciles the latest URL synchronously when the context arrives.
      context.value = result;
      applyContextLocale(result.locale);
    } catch (failure) {
      if (current()) error.value = failure;
    } finally {
      if (current()) loading.value = false;
    }
  }
  return {
    context,
    selectedKey,
    child,
    revision,
    loading,
    error,
    reset,
    chooseChild,
    denyChild,
    clearSelection,
    loadContext,
  };
});
