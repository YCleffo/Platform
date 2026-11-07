<script setup lang="ts">
import { computed, ref } from 'vue';
import AppConfirmDialog from '@/shared/ui/overlays/AppConfirmDialog.vue';
import type { Material, Page } from './api';
import { educationApi } from './api';
import { useEducationWorkspace } from './workspace';
import { useEducationDownload } from './downloads';
import { useEducationMutation, useEducationError } from './resources';
import { useEducationI18n } from './i18n';
import EducationState from './EducationState.vue';
withDefaults(defineProps<{ page: Page<Material>; deletable?: boolean }>(), {
  deletable: true,
});
const emit = defineEmits<{ more: [offset: number]; refresh: [] }>();
const workspace = useEducationWorkspace();
const { t, locale } = useEducationI18n();
const { busy, error, download } = useEducationDownload();
const issue = useEducationError();
const mutation = useEducationMutation();
const confirmOpen = ref(false);
const pending = ref<Material | null>(null);
const unable = computed(() => mutation.busy.value || mutation.uncertain.value);
function askToDelete(file: Material): void {
  pending.value = file;
  confirmOpen.value = true;
}
async function remove(): Promise<void> {
  const file = pending.value;
  if (!file) return;
  await mutation.run(
    () => educationApi.deleteFile(workspace.organizationId, file.id),
    () => {
      confirmOpen.value = false;
      pending.value = null;
      mutation.error.value = null;
      emit('refresh');
    },
  );
}
function close(): void {
  if (mutation.busy.value) return;
  confirmOpen.value = false;
  pending.value = null;
  mutation.error.value = null;
}
</script>
<template>
  <EducationState
    v-if="!page.items.length"
    :empty="t('education.noMaterials')"
  />
  <p
    v-if="error"
    class="edu-error"
    role="alert"
  >
    {{ issue(error) }}
  </p>
  <p
    v-if="mutation.error.value && !confirmOpen"
    class="edu-error"
    role="alert"
  >
    {{ mutation.uncertain.value ? t('education.uncertain') : issue(mutation.error.value) }}
  </p>
  <ul class="edu-list">
    <li
      v-for="file in page.items"
      :key="file.id"
      class="edu-list__item"
      :data-file-id="file.id"
    >
      <div>
        <strong>{{ file.name }}</strong>
        <p class="edu-subtle">
          {{ new Intl.NumberFormat(locale).format(file.sizeBytes) }} B · {{ file.mime }}
        </p>
      </div>
      <div class="edu-form-actions">
        <button
          type="button"
          class="edu-button"
          :disabled="!!busy"
          @click="download(file)"
        >
          {{ t(busy === file.id ? 'education.loading' : 'education.download') }}
        </button>
        <button
          v-if="deletable && file.lessonStatus !== 'completed'"
          type="button"
          class="edu-button"
          :disabled="unable"
          @click="askToDelete(file)"
        >
          {{ t('education.delete') }}
        </button>
      </div>
    </li>
  </ul>
  <button
    v-if="page.nextOffset !== null"
    type="button"
    class="edu-button"
    @click="$emit('more', page.nextOffset!)"
  >
    {{ t('education.loadMore') }}
  </button>
  <AppConfirmDialog
    v-model="confirmOpen"
    :title="t('education.deleteMaterial')"
    :description="t('education.deleteMaterialConfirm')"
    :confirm-label="t('education.delete')"
    :cancel-label="t('education.cancel')"
    :busy="mutation.busy.value"
    danger
    @confirm="remove"
    @update:model-value="(value) => { if (!value) close(); }"
  >
    <p
      v-if="pending"
      class="edu-message"
    >
      {{ pending.name }}
    </p>
    <p
      v-if="mutation.error.value"
      class="edu-error"
      role="alert"
    >
      {{ mutation.uncertain.value ? t('education.uncertain') : issue(mutation.error.value) }}
    </p>
  </AppConfirmDialog>
</template>
