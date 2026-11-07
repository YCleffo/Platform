<script setup lang="ts">
import { ref } from 'vue';
import { educationApi } from './api';
import { useEducationResource } from './resources';
import { useEducationI18n } from './i18n';
import EducationState from './EducationState.vue';
import MaterialList from './MaterialList.vue';
const { t } = useEducationI18n();
const offset = ref(0);
const { data, loading, error, refresh } = useEducationResource(
  (org, signal) => educationApi.files(org, offset.value, undefined, signal),
  [offset],
);
</script>
<template>
  <section>
    <header class="edu-page-title">
      <h1>{{ t('education.materials') }}</h1>
      <RouterLink
        to="/materials/upload"
        class="edu-button edu-button--primary"
      >
        {{ t('education.uploadMaterials') }}
      </RouterLink>
    </header>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <MaterialList
      v-if="data"
      :page="data"
      @more="(value) => (offset = value)"
      @refresh="refresh"
    />
    <button
      v-if="offset"
      type="button"
      class="edu-button"
      @click="offset = Math.max(0, offset - 50)"
    >
      {{ t('education.back') }}
    </button>
  </section>
</template>
