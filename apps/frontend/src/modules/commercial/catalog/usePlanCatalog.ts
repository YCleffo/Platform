import { computed, onMounted, ref } from 'vue';
import { commercialCatalog } from '@/core/api';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import type { Plan } from '@/shared/types';

/** Loads the published plan catalog. Data fetching is kept out of the view. */
export function usePlanCatalog() {
  const plans = ref<Plan[]>([]);
  const loading = ref(true);
  const error = ref<unknown>(null);
  const errorMessage = computed(() => (error.value === null ? null : resolveErrorMessage(error.value)));

  async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      const result = await commercialCatalog.listPlans();
      plans.value = result.plans;
    } catch (err) {
      error.value = err;
    } finally {
      loading.value = false;
    }
  }

  onMounted(() => {
    void load();
  });

  return { plans, loading, error, errorMessage, load };
}
