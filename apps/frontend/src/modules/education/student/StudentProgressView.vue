<script setup lang="ts">
import { computed, ref } from 'vue';
import { studentPortalApi } from './api';
import { studentSession } from './session';
import { useEducationResource } from '../resources';
import { useEducationI18n } from '../i18n';
import EducationState from '../EducationState.vue';
const { t } = useEducationI18n();
const org = computed(() => studentSession.organizationId);
const offset = ref(0);
const { data, loading, error, refresh } = useEducationResource(
  async (_org, signal) => studentPortalApi.attempts(org.value, offset.value, signal),
  [org, offset],
);
</script>
<template>
  <section>
    <h2>{{ t('education.myProgress') }}</h2>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <template v-if="data">
      <EducationState
        v-if="!data.items.length"
        :empty="t('education.noActivities')"
      />
      <ul
        v-else
        class="edu-list"
      >
        <li
          v-for="attempt in data.items"
          :key="attempt.id"
          class="edu-list__item"
        >
          <div class="edu-list__main">
            <strong>{{ attempt.activityName }}</strong>
            <p class="edu-subtle">{{ attempt.startedAt }}</p>
          </div>
          <span v-if="attempt.wpm !== null && attempt.wpm !== undefined">
            {{ attempt.wpm }} {{ t('education.wpm') }} · {{ attempt.accuracy }}%
          </span>
          <span v-else>{{ attempt.status }}</span>
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
