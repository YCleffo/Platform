<script setup lang="ts">
import { computed } from 'vue';
import { studentPortalApi } from './api';
import { studentSession } from './session';
import { activityApi } from '../activityApi';
import { useHomeworkResource } from '../homework/resources';
import { useEducationI18n } from '../i18n';
import EducationState from '../EducationState.vue';
const { t } = useEducationI18n();
const organization = () => studentSession.organizationId;
const { data, loading, error, refresh } = useHomeworkResource(
  organization,
  studentPortalApi.activities,
);
const progress = useHomeworkResource(organization, activityApi.progress);
const available = computed(() =>
  (data.value?.items ?? []).filter(
    (activity) => activity.enabled && ['platform-typing', 'platform-logic'].includes(activity.code),
  ),
);
function target(code: string): string {
  return code === 'platform-logic' ? '/student/logic/platform-logic' : `/student/typing/${code}`;
}
function latest(code: string): string {
  const history =
    code === 'platform-logic'
      ? progress.data.value?.logic.history
      : progress.data.value?.typing.history;
  const entry = history
    ?.slice()
    .sort((a, b) => Date.parse(b.createdAt) - Date.parse(a.createdAt))[0];
  return entry
    ? `${code === 'platform-logic' ? `${entry.correctAnswers} / ${entry.totalRounds}` : `${entry.wpm} WPM`} · ${entry.accuracy}%`
    : '—';
}
</script>
<template>
  <section>
    <header class="edu-page-title">
      <h2>{{ t('education.activities') }}</h2>
      <RouterLink
        to="/student/achievements"
        class="edu-link"
      >
        {{ t('education.achievements') }}
      </RouterLink>
    </header>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <EducationState
      v-if="data && !available.length"
      :empty="t('education.noActivities')"
    />
    <ul
      v-if="data"
      class="edu-activity-catalog"
    >
      <li
        v-for="activity in available"
        :key="activity.id"
        class="edu-card edu-activity-card"
      >
        <span class="edu-tag">
          {{
            t(activity.code === 'platform-logic' ? 'education.logicType' : 'education.typingType')
          }}
          · {{ t('education.difficulty') }} 1–3
        </span>
        <h3>{{ activity.name }}</h3>
        <p>
          {{
            t(
              activity.code === 'platform-logic'
                ? 'education.logicDescription'
                : 'education.typingDescription',
            )
          }}
        </p>
        <p class="edu-caption">
          RU / EN ·
          {{
            activity.code === 'platform-logic'
              ? t('education.logicModes')
              : t('education.typingModes')
          }}
        </p>
        <p>{{ t('education.lastResult') }}: {{ latest(activity.code) }}</p>
        <p v-if="progress.data.value">
          {{ t('education.bestTyping') }}:
          {{
            activity.code === 'platform-logic'
              ? (progress.data.value.logic.bestScore ?? '—')
              : `${progress.data.value.typing.bestWpm ?? '—'} WPM`
          }}
        </p>
        <RouterLink
          :to="target(activity.code)"
          class="edu-button edu-button--primary"
        >
          {{ t('education.openActivity') }}
        </RouterLink>
      </li>
    </ul>
    <EducationState
      :error="progress.error.value"
      @retry="progress.refresh"
    />
  </section>
</template>
