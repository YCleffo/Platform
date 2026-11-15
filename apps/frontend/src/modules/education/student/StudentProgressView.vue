<script setup lang="ts">
import { ref } from 'vue';
import { studentPortalApi } from './api';
import { studentSession } from './session';
import { activityApi } from '../activityApi';
import { useHomeworkResource } from '../homework/resources';
import { useEducationI18n } from '../i18n';
import EducationState from '../EducationState.vue';
import StudentCurriculumProgress from '../StudentCurriculumProgress.vue';
import HomeworkSummary from '../homework/HomeworkSummary.vue';
import ActivityProgressPanel from '../ActivityProgressPanel.vue';
const { t } = useEducationI18n();
const offset = ref(0);
const progress = useHomeworkResource(() => studentSession.organizationId, activityApi.progress);
const { data, loading, error, refresh } = useHomeworkResource(
  () => studentSession.organizationId,
  (org, signal) => studentPortalApi.attempts(org, offset.value, signal),
  [offset],
);
</script>
<template>
  <section>
    <header class="edu-page-title">
      <h2>{{ t('education.myProgress') }}</h2>
      <RouterLink
        to="/student/achievements"
        class="edu-link"
      >
        {{ t('education.achievements') }}
      </RouterLink>
    </header>
    <EducationState
      :loading="progress.loading.value"
      :error="progress.error.value"
      @retry="progress.refresh"
    />
    <ActivityProgressPanel
      v-if="progress.data.value"
      :progress="progress.data.value"
      show-achievements
    />
    <HomeworkSummary
      student
      compact
    />
    <StudentCurriculumProgress />
    <h2>{{ t('education.trainingHistory') }}</h2>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <template v-if="data">
      <EducationState
        v-if="!data.items.length"
        :empty="t('education.noTrainingResults')"
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
            <p class="edu-subtle">
              <time :datetime="attempt.startedAt">
                {{ new Date(attempt.startedAt).toLocaleString() }}
              </time>
            </p>
          </div>
          <span v-if="attempt.wpm !== null && attempt.wpm !== undefined">
            {{ attempt.wpm }} WPM · {{ attempt.accuracy }}%
          </span>
          <span v-else-if="attempt.score !== null && attempt.score !== undefined">
            {{ attempt.correctAnswers }} / {{ attempt.totalRounds }} · {{ attempt.accuracy }}% ·
            {{ attempt.score }}
          </span>
          <RouterLink
            v-else-if="attempt.status === 'running' && attempt.activityCode === 'platform-logic'"
            :to="{
              name: 'education-student-logic',
              params: { code: 'platform-logic' },
              query: { attempt: attempt.id },
            }"
            class="edu-link"
          >
            {{ t('education.restoreAttempt') }}
          </RouterLink>
          <span v-else>{{ t(`education.attempt_${attempt.status}`) }}</span>
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
