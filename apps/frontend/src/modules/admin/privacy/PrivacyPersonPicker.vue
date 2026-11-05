<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { privacyOperator, type PersonOption } from '@/shared/api/core/privacyOperator';
import { coreAdminSessionEpoch } from '@/core/admin/session';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { useAdminOperation } from '../useAdminOperation';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
defineProps<{ id: string; label: string; disabled?: boolean }>();
const model = defineModel<string>({ required: true });
const { t } = useI18n();
const auth = useAdminAuthStore();
const guard = useAdminOperation();
const rows = ref<PersonOption[]>([]);
const offset = ref(0);
const total = ref(0);
const loading = ref(false);
const error = ref<unknown>(null);
const options = computed(() =>
  rows.value.map((p) => ({
    value: p.id,
    label: p.displayName || [p.givenName, p.familyName].join(' '),
    description: p.id,
  })),
);
async function load() {
  if (loading.value || !auth.can('core.persons.read')) return;
  const request = guard.begin();
  loading.value = true;
  error.value = null;
  try {
    const page = await privacyOperator.persons(offset.value);
    if (!guard.current(request)) return;
    rows.value = [...new Map([...rows.value, ...page.items].map((p) => [p.id, p])).values()];
    offset.value += page.items.length;
    total.value = page.items.length === 0 ? offset.value : page.pagination.total;
  } catch (caught) {
    if (guard.current(request)) error.value = caught;
  } finally {
    if (guard.current(request)) loading.value = false;
  }
}
watch(
  coreAdminSessionEpoch,
  () => {
    guard.begin();
    rows.value = [];
    model.value = '';
    loading.value = false;
    error.value = null;
  },
  { flush: 'sync' },
);
onMounted(() => {
  void load();
});
</script>
<template>
  <div class="core-privacy__field">
    <label :for="id">{{ label }}</label>
    <AppDropdown
      :id="id"
      v-model="model"
      :label="label"
      :options="options"
      :disabled="disabled || !auth.can('core.persons.read')"
      searchable
      clearable
    />
    <p>
      {{
        $t(
          auth.can('core.persons.read')
            ? 'privacyOperator.loadedSearch'
            : 'privacyOperator.personPermission',
        )
      }}
    </p>
    <p
      v-if="error"
      role="alert"
    >
      {{ resolveErrorMessage(error) }}
    </p>
    <AppButton
      v-if="error || rows.length < total || loading"
      variant="secondary"
      :loading="loading"
      :disabled="disabled || loading"
      @click="load"
    >
      {{ t(error ? 'common.retry' : 'privacyOperator.morePersons') }}
    </AppButton>
  </div>
</template>
