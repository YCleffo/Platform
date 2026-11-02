<script setup lang="ts">
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { useToast } from '@/shared/composables/useToast';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import type { AdminOperation } from './types';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppJsonResponseDialog from '@/shared/ui/overlays/AppJsonResponseDialog.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';

defineProps<{ title: string; description: string; operations: AdminOperation[] }>();
const { t } = useI18n();
const toast = useToast();
const open = ref(false);
const selected = ref<AdminOperation | null>(null);
const inputs = reactive<Record<string, string>>({});
const body = ref('{}');
const error = ref('');
const busy = ref(false);
const response = ref<unknown>(null);
const responseOpen = ref(false);

function start(operation: AdminOperation): void {
  selected.value = operation;
  for (const key of Object.keys(inputs)) delete inputs[key];
  for (const input of operation.inputs ?? []) inputs[input.name] = '';
  body.value = JSON.stringify(operation.body ?? {}, null, 2);
  error.value = '';
  open.value = true;
}

async function execute(): Promise<void> {
  const operation = selected.value;
  if (operation === null) return;
  error.value = '';
  let parsed: unknown;
  try {
    parsed = JSON.parse(body.value);
  } catch {
    error.value = t('admin.invalidJson');
    return;
  }
  busy.value = true;
  try {
    const path = operation.path({ ...inputs }, parsed);
    const merged = typeof parsed === 'object' && parsed !== null && !Array.isArray(parsed)
      ? { ...parsed, ...inputs }
      : parsed;
    const result = operation.method === 'GET'
      ? await coreAdminHttp.get<unknown>(path)
      : operation.method === 'DELETE'
        ? await coreAdminHttp.delete<unknown>(path)
        : operation.method === 'PATCH'
          ? await coreAdminHttp.patch<unknown>(path, merged)
          : operation.method === 'PUT'
            ? await coreAdminHttp.put<unknown>(path, merged)
            : await coreAdminHttp.post<unknown>(path, merged);
    open.value = false;
    response.value = result ?? { status: 'success' };
    responseOpen.value = true;
    toast.show(t('admin.actionDone'), 'success');
  } catch (caught) {
    error.value = resolveErrorMessage(caught);
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <AppCard>
    <div class="admin-ops__head">
      <div>
        <h3>{{ title }}</h3>
        <p>{{ description }}</p>
      </div>
    </div>
    <div class="admin-ops__buttons">
      <AppButton v-for="operation in operations" :key="operation.label" :variant="operation.danger ? 'danger' : 'secondary'" @click="start(operation)">{{ operation.label }}</AppButton>
    </div>
  </AppCard>

  <AppJsonResponseDialog v-model="responseOpen" :title="$t('admin.response')" :data="response" />

  <AppDialog v-model="open" :title="selected?.label ?? $t('admin.actions')" :description="selected?.description" :persistent="busy">
    <div class="admin-ops__editor">
      <AppInput
        v-for="input in selected?.inputs ?? []"
        :id="`admin-op-${input.name}`"
        :key="input.name"
        :model-value="inputs[input.name]"
        :label="input.label"
        :placeholder="input.placeholder"
        @update:model-value="(value) => inputs[input.name] = String(value)"
      />
      <p class="admin-ops__hint">{{ $t('admin.jsonHint') }}</p>
      <textarea v-model="body" class="admin-ops__textarea" rows="10" spellcheck="false" />
      <p v-if="error" class="admin-ops__error" role="alert">{{ error }}</p>
      <div class="admin-ops__buttons">
        <AppButton variant="secondary" :disabled="busy" @click="open = false">{{ $t('common.cancel') }}</AppButton>
        <AppButton :variant="selected?.danger ? 'danger' : 'primary'" :loading="busy" @click="execute">{{ $t('admin.execute') }}</AppButton>
      </div>
    </div>
  </AppDialog>
</template>

<style scoped>
.admin-ops__head h3 { font-size: var(--type-h3-size); line-height: var(--type-h3-line); font-weight: var(--type-h3-weight); color: var(--text-1); }
.admin-ops__head p { margin-top: var(--space-4); font-size: var(--type-body-medium-size); line-height: var(--type-body-medium-line); color: var(--text-2); }
.admin-ops__buttons { display: flex; flex-wrap: wrap; gap: var(--space-8); margin-top: var(--space-12); }
.admin-ops__editor { display: grid; gap: var(--space-12); }
.admin-ops__hint { font-size: var(--type-body-small-size); color: var(--text-3); }
.admin-ops__textarea { width: 100%; min-height: 170px; padding: var(--space-12); border: 1px solid var(--border); border-radius: var(--radius-control-m); background: var(--surface-2); color: var(--text-1); font-family: var(--font-mono); font-size: var(--type-body-small-size); line-height: 1.5; resize: vertical; }
.admin-ops__error { color: var(--danger); font-size: var(--type-body-small-size); }
</style>
