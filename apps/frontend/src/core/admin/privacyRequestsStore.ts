import { ref, watch } from 'vue';
import { defineStore } from 'pinia';
import { coreAdminSessionEpoch } from './session';
import { corePrivacy, type OwnPrivacyRequest } from '@/shared/api/core/privacy';
export const useCorePrivacyRequests = defineStore('corePrivacyRequests', () => {
  const items = ref<OwnPrivacyRequest[]>([]);
  const loaded = ref(false);
  const loading = ref(false);
  const loadingMore = ref(false);
  const hasMore = ref(false);
  const error = ref<unknown>(null);
  const moreError = ref<unknown>(null);
  let generation = 0;
  let offset = 0;
  function clear(): void {
    generation += 1;
    offset = 0;
    items.value = [];
    loaded.value = false;
    loading.value = false;
    loadingMore.value = false;
    hasMore.value = false;
    error.value = null;
    moreError.value = null;
  }
  watch(coreAdminSessionEpoch, clear, { flush: 'sync' });
  async function load(more = false): Promise<void> {
    if (more && (loading.value || loadingMore.value || !hasMore.value)) return;
    const request = ++generation;
    const epoch = coreAdminSessionEpoch.value;
    const current = () => request === generation && epoch === coreAdminSessionEpoch.value;
    const start = more ? offset : 0;
    loading.value = !more;
    loadingMore.value = more;
    if (more) moreError.value = null;
    else {
      error.value = null;
      moreError.value = null;
    }
    try {
      const result = await corePrivacy.list(start, 26);
      if (!current()) return;
      const page = result.slice(0, 25);
      const rows = more ? [...items.value, ...page] : page;
      items.value = [...new Map(rows.map((row) => [row.id, row])).values()];
      offset = start + page.length;
      hasMore.value = result.length > 25;
      loaded.value = true;
    } catch (caught) {
      if (current()) {
        if (more) moreError.value = caught;
        else error.value = caught;
      }
    } finally {
      if (current()) {
        loading.value = false;
        loadingMore.value = false;
      }
    }
  }
  return { items, loaded, loading, loadingMore, hasMore, error, moreError, load, clear };
});
