<script setup lang="ts">
import { useEducationI18n } from '../i18n';
import type { AssessmentResult } from '../activityTypes';

defineProps<{
  result: AssessmentResult;
  title?: string;
}>();

const emit = defineEmits<{
  close: [];
}>();

const { t } = useEducationI18n();

function formatTime(sec: number): string {
  const m = Math.floor(sec / 60);
  const s = sec % 60;
  return `${m} ${t('education.minutes')} ${s} ${t('education.seconds')}`;
}
</script>

<template>
  <div class="edu-result-view">
    <div
      class="edu-result-banner"
      :class="result.passed ? 'edu-result-banner--passed' : 'edu-result-banner--failed'"
    >
      <div class="edu-result-banner__icon">
        {{ result.passed ? '🎉' : '⚠️' }}
      </div>
      <div>
        <h1 class="edu-result-banner__title">
          {{ result.passed ? t('education.assessmentPassed') : t('education.assessmentFailed') }}
        </h1>
        <p class="edu-result-banner__subtitle">
          {{ title || t('education.assessmentResultsTitle') }}
        </p>
      </div>
    </div>

    <!-- Stats Grid -->
    <div class="edu-result-stats">
      <div class="edu-stat-box">
        <span class="edu-stat-box__val">{{ result.score }} / {{ result.maxScore }}</span>
        <span class="edu-stat-box__label">{{ t('education.score') }}</span>
      </div>
      <div class="edu-stat-box">
        <span
          class="edu-stat-box__val"
          :class="result.passed ? 'edu-stat-box__val--passed' : 'edu-stat-box__val--failed'"
        >
          {{ result.percentage }}%
        </span>
        <span class="edu-stat-box__label">
          {{ t('education.passingScoreRequired', { percent: result.passingScorePercent }) }}
        </span>
      </div>
      <div class="edu-stat-box">
        <span class="edu-stat-box__val">
          {{ result.correctQuestions }} / {{ result.totalQuestions }}
        </span>
        <span class="edu-stat-box__label">{{ t('education.answered') }}</span>
      </div>
      <div class="edu-stat-box">
        <span class="edu-stat-box__val">{{ formatTime(result.elapsedSeconds || 0) }}</span>
        <span class="edu-stat-box__label">{{ t('education.duration') }}</span>
      </div>
    </div>

    <!-- Question Evaluations Breakdown -->
    <section
      v-if="result.questions && result.questions.length > 0"
      class="edu-result-breakdown"
    >
      <h2 class="edu-result-breakdown__title">{{ t('education.errorsBreakdown') }}</h2>

      <div class="edu-result-breakdown__list">
        <article
          v-for="(evalQ, idx) in result.questions"
          :key="evalQ.questionId || idx"
          class="edu-breakdown-card"
          :class="evalQ.correct ? 'edu-breakdown-card--correct' : 'edu-breakdown-card--incorrect'"
        >
          <header class="edu-breakdown-card__header">
            <span class="edu-breakdown-card__status">
              {{
                evalQ.correct
                  ? '✓ ' + t('education.correctAnswer')
                  : '✗ ' + t('education.incorrectAnswer')
              }}
            </span>
            <span class="edu-breakdown-card__score">
              {{ evalQ.earnedPoints }} / {{ evalQ.possiblePoints }} {{ t('education.score') }}
            </span>
          </header>

          <div
            v-if="evalQ.correctAnswer !== undefined"
            class="edu-breakdown-card__answers"
          >
            <div
              v-if="!evalQ.correct"
              class="edu-breakdown-card__client-answer"
            >
              <span class="edu-breakdown-label">{{ t('education.lastResult') }}:</span>
              <span>{{ evalQ.clientAnswer }}</span>
            </div>
            <div class="edu-breakdown-card__correct-answer">
              <span class="edu-breakdown-label">{{ t('education.correctOption') }}:</span>
              <span>{{ evalQ.correctAnswer }}</span>
            </div>
          </div>

          <p
            v-if="evalQ.explanation"
            class="edu-breakdown-card__exp"
          >
            <em>💡 {{ evalQ.explanation }}</em>
          </p>
        </article>
      </div>
    </section>

    <footer class="edu-result-view__footer">
      <button
        class="edu-btn edu-btn--primary"
        type="button"
        @click="emit('close')"
      >
        ← {{ t('education.assessments') }}
      </button>
    </footer>
  </div>
</template>

<style scoped>
.edu-result-view {
  max-width: 800px;
  margin: 0 auto;
  width: 100%;
  padding: 2rem 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 2rem;
  color: var(--text-1);
}

.edu-result-banner {
  display: flex;
  align-items: center;
  gap: 1.5rem;
  padding: 1.75rem 2rem;
  border-radius: 12px;
  border: 1px solid var(--border);
}

.edu-result-banner--passed {
  background: var(--success-soft);
  color: var(--success-text);
}

.edu-result-banner--failed {
  background: var(--danger-soft);
  color: var(--danger-text);
}

.edu-result-banner__icon {
  font-size: var(--type-display-size);
  font-weight: var(--type-display-weight);
  line-height: var(--type-display-line);
}

.edu-result-banner__title {
  font-size: var(--type-h1-size);
  font-weight: var(--type-h1-weight);
  line-height: var(--type-h1-line);
  margin: 0;
}

.edu-result-banner__subtitle {
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  line-height: var(--type-body-medium-line);
  margin: 0.25rem 0 0;
  opacity: 0.9;
}

.edu-result-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 1rem;
}

.edu-stat-box {
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 1.25rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 0.25rem;
}

.edu-stat-box__val {
  font-size: var(--type-metric-number-size);
  font-weight: var(--type-metric-number-weight);
  line-height: var(--type-metric-number-line);
}

.edu-stat-box__val--passed {
  color: var(--success-text);
}

.edu-stat-box__val--failed {
  color: var(--danger-text);
}

.edu-stat-box__label {
  font-size: var(--type-body-caption-size);
  font-weight: var(--type-body-caption-weight);
  line-height: var(--type-body-caption-line);
  color: var(--text-2);
}

.edu-result-breakdown {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.edu-result-breakdown__title {
  font-size: var(--type-h3-size);
  font-weight: var(--type-h3-weight);
  line-height: var(--type-h3-line);
  margin: 0;
}

.edu-result-breakdown__list {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.edu-breakdown-card {
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--surface-2);
  padding: 1rem 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.edu-breakdown-card--correct {
  border-left: 4px solid var(--success-text);
}

.edu-breakdown-card--incorrect {
  border-left: 4px solid var(--danger-text);
}

.edu-breakdown-card__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.edu-breakdown-card__status {
  font-size: var(--type-body-small-emphasis-size);
  font-weight: var(--type-body-small-emphasis-weight);
  line-height: var(--type-body-small-line);
}

.edu-breakdown-card--correct .edu-breakdown-card__status {
  color: var(--success-text);
}

.edu-breakdown-card--incorrect .edu-breakdown-card__status {
  color: var(--danger-text);
}

.edu-breakdown-card__score {
  font-size: var(--type-body-caption-size);
  font-weight: var(--type-body-caption-weight);
  line-height: var(--type-body-caption-line);
  color: var(--text-2);
}

.edu-breakdown-card__answers {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  line-height: var(--type-body-small-line);
}

.edu-breakdown-label {
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--text-2);
  margin-right: 0.375rem;
}

.edu-breakdown-card__correct-answer {
  color: var(--success-text);
}

.edu-breakdown-card__client-answer {
  color: var(--danger-text);
}

.edu-breakdown-card__exp {
  font-size: var(--type-body-caption-size);
  font-weight: var(--type-body-caption-weight);
  line-height: var(--type-body-caption-line);
  color: var(--text-2);
  margin: 0;
}

.edu-result-view__footer {
  display: flex;
  justify-content: center;
  padding-top: 1rem;
}
</style>
