<script setup lang="ts">
import type { Material, Page } from './api';
import { useEducationDownload } from './downloads';
import { useEducationError } from './resources';
import { useEducationI18n } from './i18n';
import EducationState from './EducationState.vue';
defineProps<{ page: Page<Material> }>();
defineEmits<{ more: [offset: number] }>();
const { t, locale } = useEducationI18n();
const { busy, error, download } = useEducationDownload();
const issue = useEducationError();
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
      <button
        type="button"
        class="edu-button"
        :disabled="!!busy"
        @click="download(file)"
      >
        {{ t(busy === file.id ? 'education.loading' : 'education.download') }}
      </button>
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
</template>
