<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { homeworkApi } from '../homework/api';
import { useHomeworkResource } from '../homework/resources';
import { studentPortalApi } from './api';
import { studentSession } from './session';
import { useEducationI18n } from '../i18n';
import EducationState from '../EducationState.vue';
import TypingTrainer from './TypingTrainer.vue';
const { t } = useEducationI18n();
const org = computed(() => studentSession.organizationId);
const route = useRoute();
const homeworkId = computed(() =>
  typeof route.query.homework === 'string' ? route.query.homework : '',
);
const assigned = useHomeworkResource(
  () => org.value,
  (organization, signal) =>
    homeworkId.value
      ? homeworkApi.studentDetail(organization, homeworkId.value, signal)
      : Promise.resolve(null),
  [homeworkId],
);
const canRunAssigned = computed(
  () =>
    assigned.data.value &&
    assigned.data.value.assignment.status === 'PUBLISHED' &&
    ['typing', 'activity'].includes(assigned.data.value.assignment.type) &&
    ['NOT_STARTED', 'IN_PROGRESS', 'RETURNED'].includes(assigned.data.value.submission.state),
);
const offset = ref(0);
const { data, loading, error, refresh } = useHomeworkResource(
  () => org.value,
  async (organization, signal) =>
    studentPortalApi.typingHistory(organization, offset.value, signal),
  [offset],
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
    <header class="edu-page-title">
      <h2>Platform Typing</h2>
      <RouterLink
        to="/student/activities"
        class="edu-link"
      >
        {{ t('education.activities') }}
      </RouterLink>
    </header>
    <template v-if="homeworkId">
      <RouterLink
        :to="`/student/homework/${homeworkId}`"
        class="edu-link"
      >
        {{ t('education.homeworkBack') }}
      </RouterLink>
      <p>{{ t('education.homeworkAssignedTyping') }}</p>
      <EducationState
        :loading="assigned.loading.value"
        :error="assigned.error.value"
        @retry="assigned.refresh"
      />
      <p v-if="assigned.data.value">
        {{ assigned.data.value.assignment.title }} · {{ t('education.homeworkMaxAttempts') }}:
        {{ assigned.data.value.assignment.maxAttempts }}
      </p>
    </template>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <TypingTrainer
      v-if="org && (!homeworkId || canRunAssigned)"
      :key="`${org}:${homeworkId}`"
      :organization-id="org"
      :homework-id="homeworkId || undefined"
      :assigned-config="assigned.data.value?.assignment.activityConfig"
      @finished="onFinished"
    />
    <p
      v-if="homeworkId && assigned.data.value && !canRunAssigned"
      class="edu-subtle"
    >
      {{ t('education.homeworkLocked') }}
    </p>
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
          <span>
            {{ entry.accuracy }}% · {{ entry.errors }} · {{ entry.durationSeconds
            }}{{ t('education.seconds') }}
          </span>
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
