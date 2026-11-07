<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { studentPortalApi } from './api';
import { studentSession } from './session';
import { useEducationResource } from '../resources';
import { useEducationI18n } from '../i18n';
import EducationState from '../EducationState.vue';
import TypingTrainer from './TypingTrainer.vue';
const { t } = useEducationI18n();
const org = computed(() => studentSession.organizationId);
const offset = ref(0);
const { data, loading, error, refresh } = useEducationResource(
  async (_org, signal) => studentPortalApi.typingHistory(org.value, offset.value, signal),
  [org, offset],
);
watch(
  () => [org.value],
  () => {
    offset.value = 0;
  },
);
function onFinished(): void {
  void refresh();
}
</script>
<template>
  <section>
    <h2>KiberOne Typing</h2>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <TypingTrainer
      v-if="org"
      :organization-id="org"
      @finished="onFinished"
    />
    <template v-if="data">
      <div class="edu-section-title">
        <h2>{{ t('education.bestTyping') }}</h2>
      </div>
      <p v-if="data.best">
        {{ data.best.wpm }} {{ t('education.wpm') }} · {{ data.best.accuracy }}% ·
        {{ data.best.durationSeconds }} {{ t('education.seconds') }}
      </p>
      <EducationState
        v-else
        :empty="t('education.noTrainingResults')"
      />
      <div class="edu-section-title">
        <h2>{{ t('education.trainingHistory') }}</h2>
      </div>
      <EducationState
        v-if="!data.items.length"
        :empty="t('education.noTrainingResults')"
      />
      <ul
        v-else
        class="edu-list"
      >
        <li
          v-for="entry in data.items"
          :key="entry.attemptId"
          class="edu-list__item"
        >
          <strong>{{ entry.wpm }} {{ t('education.wpm') }}</strong>
          <span>{{ entry.accuracy }}% · {{ entry.errors }} · {{ entry.durationSeconds }}{{ t('education.seconds') }}</span>
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
          v-if="data.nextOffset !== null"
          type="button"
          class="edu-button"
          @click="offset = data.nextOffset ?? 0"
        >
          {{ t('education.loadMore') }}
        </button>
      </div>
    </template>
  </section>
</template>
