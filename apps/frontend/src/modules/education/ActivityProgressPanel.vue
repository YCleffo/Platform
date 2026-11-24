<script setup lang="ts">
import { useEducationI18n } from './i18n';
import type { ActivityProgress } from './activityTypes';
import ActivityTrendChart from './ActivityTrendChart.vue';
import AchievementList from './AchievementList.vue';
defineProps<{ progress: ActivityProgress; showAchievements?: boolean }>();
const { t } = useEducationI18n();
</script>
<template>
  <p
    v-if="progress.historyTruncated"
    class="edu-caption"
  >
    {{ t('education.activityHistoryLimit', { count: progress.historyLimit ?? 500 }) }}
  </p>
  <div class="edu-activity-summary">
    <div class="edu-card">
      <strong>Platform Typing</strong>
      <p>{{ t('education.completedAttempts') }}: {{ progress.typing.attempts }}</p>
      <p>{{ t('education.bestTyping') }}: {{ progress.typing.bestWpm ?? '—' }} WPM</p>
      <p>{{ t('education.averageAccuracy') }}: {{ progress.typing.averageAccuracy ?? '—' }}%</p>
    </div>
    <div class="edu-card">
      <strong>Platform Logic</strong>
      <p>{{ t('education.completedAttempts') }}: {{ progress.logic.attempts }}</p>
      <p>{{ t('education.bestScore') }}: {{ progress.logic.bestScore ?? '—' }}</p>
    </div>
    <div
      v-if="progress.assessments"
      class="edu-card"
    >
      <strong>{{ t('education.assessmentStudio') }}</strong>
      <p>{{ t('education.completedAttempts') }}: {{ progress.assessments.attempts }}</p>
      <p>
        {{ t('education.averageAccuracy') }}:
        {{ progress.assessments.avgScore !== null ? `${progress.assessments.avgScore}%` : '—' }}
      </p>
    </div>
    <div class="edu-card">
      <strong>{{ t('education.completedAssignments') }}</strong>
      <p>{{ progress.completedAssignments }}</p>
    </div>
  </div>
  <div class="edu-activity-charts">
    <ActivityTrendChart
      :entries="progress.typing.history"
      metric="wpm"
      :label="t('education.wpmTrend')"
      unit="WPM"
    />
    <ActivityTrendChart
      :entries="progress.typing.history"
      metric="accuracy"
      :label="t('education.accuracyTrend')"
      unit="%"
    />
    <ActivityTrendChart
      :entries="progress.logic.history"
      metric="score"
      :label="t('education.logicTrend')"
    />
  </div>
  <template v-if="showAchievements">
    <h2>{{ t('education.achievements') }}</h2>
    <AchievementList
      :items="progress.achievements.filter((a) => a.unlockedAt)"
      compact
    />
  </template>
</template>
