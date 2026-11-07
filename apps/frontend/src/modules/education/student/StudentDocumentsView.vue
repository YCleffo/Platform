<script setup lang="ts">
import { computed, ref } from 'vue';
import { storageApi } from './storage';
import { studentSession } from './session';
import { useEducationResource } from '../resources';
import { useEducationI18n } from '../i18n';
import EducationState from '../EducationState.vue';
const { t } = useEducationI18n();
const org = computed(() => studentSession.organizationId);
const offset = ref(0);
const uploadBusy = ref(false);
const uploadError = ref('');
const { data, loading, error, refresh } = useEducationResource(
  async (_org, signal) => {
    const [space, files] = await Promise.all([
      storageApi.space(org.value, signal),
      storageApi.files(org.value, offset.value, signal),
    ]);
    return { space, files };
  },
  [org, offset],
);
async function upload(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;
  uploadBusy.value = true;
  uploadError.value = '';
  try {
    const bytes = await file.arrayBuffer();
    const sha256 = await sha256Hex(bytes);
    const ticket = await storageApi.ticket(org.value, 'upload', file.name);
    await storageApi.upload(org.value, ticket.token, bytes, sha256);
    input.value = '';
    await refresh();
  } catch (failure) {
    uploadError.value = failure instanceof Error ? failure.message : 'error';
  } finally {
    uploadBusy.value = false;
  }
}
async function download(path: string): Promise<void> {
  const ticket = await storageApi.ticket(org.value, 'download', path);
  const bytes = await storageApi.download(org.value, ticket.token);
  const blob = new Blob([bytes]);
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = path.split('/').pop() ?? 'file';
  anchor.click();
  URL.revokeObjectURL(url);
}
async function sha256Hex(bytes: ArrayBuffer): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', bytes);
  return [...new Uint8Array(digest)].map(byte => byte.toString(16).padStart(2, '0')).join('');
}
function formatBytes(value: number): string {
  if (value < 1024) return `${value} B`;
  if (value < 1024 * 1024) return `${Math.round(value / 1024)} KB`;
  return `${Math.round(value / 1024 / 1024)} MB`;
}
</script>
<template>
  <section>
    <h2>{{ t('education.documents') }}</h2>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <template v-if="data">
      <p class="edu-subtle">{{ t('education.storageUsed', { used: formatBytes(data.space.totalBytes), total: formatBytes(data.space.maxBytes) }) }}</p>
      <label class="edu-button">
        {{ t('education.uploadFile') }}
        <input
          type="file"
          class="edu-visually-hidden"
          :disabled="uploadBusy"
          @change="upload"
        >
      </label>
      <p
        v-if="uploadError"
        class="edu-error"
        role="alert"
      >
        {{ uploadError }}
      </p>
      <EducationState
        v-if="!data.files.items.length"
        :empty="t('education.storageEmpty')"
      />
      <ul
        v-else
        class="edu-list"
      >
        <li
          v-for="file in data.files.items"
          :key="file.id"
          class="edu-list__item"
        >
          <div class="edu-list__main">
            <strong>{{ file.path }}</strong>
            <p class="edu-subtle">
              v{{ file.version ?? 1 }} · {{ formatBytes(file.sizeBytes ?? 0) }}
            </p>
          </div>
          <button
            type="button"
            class="edu-button"
            @click="download(file.path)"
          >
            {{ t('education.download') }}
          </button>
        </li>
      </ul>
      <div class="edu-form-actions">
        <button
          v-if="offset"
          type="button"
          class="edu-button"
          @click="offset = Math.max(0, offset - 50)"
        >
          {{ t('education.back') }}
        </button>
        <button
          v-if="data.files.nextOffset !== null"
          type="button"
          class="edu-button"
          @click="offset = data.files.nextOffset ?? 0"
        >
          {{ t('education.loadMore') }}
        </button>
      </div>
    </template>
  </section>
</template>
