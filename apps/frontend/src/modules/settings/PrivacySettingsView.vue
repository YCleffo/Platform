<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { commercialPrivacy } from '@/core/api';
import { useAsyncAction } from '@/shared/composables/useAsyncAction';
import { useToast } from '@/shared/composables/useToast';
import type { PersonalDataExport } from '@/shared/types';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';

const { t } = useI18n();
const toast = useToast();
const exported = ref<PersonalDataExport | null>(null);
const summary = computed(() => {
  if (exported.value === null) {
    return [];
  }
  return Object.entries(exported.value.data).map(([key, value]) => ({
    key,
    size: Array.isArray(value) ? value.length : value !== null && typeof value === 'object' ? Object.keys(value).length : 1,
  }));
});

function download(result: PersonalDataExport): void {
  const blob = new Blob([JSON.stringify(result, null, 2)], { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = `platform-personal-data-${new Date().toISOString().slice(0, 10)}.json`;
  anchor.click();
  URL.revokeObjectURL(url);
}

const { submitting, message, run } = useAsyncAction(async () => {
  const result = await commercialPrivacy.exportMyData();
  exported.value = result;
  download(result);
  toast.show(t('privacy.downloaded'), 'success');
});
</script>

<template>
  <section class="privacy">
    <header class="privacy__header">
      <h2 class="privacy__title">{{ $t('privacy.title') }}</h2>
      <p class="privacy__subtitle">{{ $t('privacy.subtitle') }}</p>
    </header>

    <AppCard>
      <div class="privacy__card">
        <div class="privacy__copy">
          <h3>{{ $t('privacy.exportTitle') }}</h3>
          <p>{{ $t('privacy.exportDescription') }}</p>
        </div>
        <AppButton :loading="submitting" @click="run">{{ $t('privacy.download') }}</AppButton>
      </div>
      <p v-if="message" class="privacy__error" role="alert">{{ message }}</p>
    </AppCard>

    <AppCard v-if="exported">
      <h3>{{ $t('privacy.exportedTitle') }}</h3>
      <p class="privacy__meta">
        {{ $t('privacy.exportedMeta', { version: exported.schema_version }) }}
      </p>
      <dl class="privacy__summary">
        <div v-for="item in summary" :key="item.key" class="privacy__summary-row">
          <dt>{{ item.key }}</dt>
          <dd>{{ $t('privacy.items', { count: item.size }) }}</dd>
        </div>
      </dl>
    </AppCard>
  </section>
</template>

<style scoped>
.privacy {
  display: grid;
  gap: var(--space-16);
}

.privacy__header {
  display: grid;
  gap: var(--space-4);
}

.privacy__title {
  font-size: var(--type-h2-size);
  line-height: var(--type-h2-line);
  font-weight: var(--type-h2-weight);
  color: var(--text-1);
  transition: var(--theme-transition);
}

.privacy__subtitle {
  max-width: 720px;
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  color: var(--text-2);
  transition: var(--theme-transition);
}

.privacy__card {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-16);
}

.privacy__copy {
  display: grid;
  gap: var(--space-4);
  max-width: 620px;
}

.privacy__copy h3 {
  font-size: var(--type-h3-size);
  line-height: var(--type-h3-line);
  font-weight: var(--type-h3-weight);
  color: var(--text-1);
}

.privacy__copy p,
.privacy__meta,
.privacy__summary {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  color: var(--text-2);
  transition: var(--theme-transition);
}

.privacy__error {
  margin-top: var(--space-12);
  color: var(--danger);
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
}

.privacy__summary {
  display: grid;
  gap: var(--space-8);
  margin-top: var(--space-12);
}

.privacy__summary-row {
  display: flex;
  justify-content: space-between;
  gap: var(--space-12);
  padding-bottom: var(--space-8);
  border-bottom: 1px solid var(--border);
}

.privacy__summary-row dd {
  color: var(--text-1);
  white-space: nowrap;
}
</style>
