<script setup lang="ts">
import { computed } from 'vue';
import { useEducationI18n } from '../i18n';
import type { AttendanceTotals, ParentHomework, ParentLesson } from './api';

const props = withDefaults(
  defineProps<{
    attendance?: AttendanceTotals | null;
    results?: ParentLesson[];
    homework?: ParentHomework[];
    timezone?: string;
  }>(),
  {
    attendance: null,
    results: () => [],
    homework: () => [],
    timezone: 'UTC',
  },
);

const { t, locale } = useEducationI18n();

// 1. Attendance analytics
const attendanceRate = computed(() => {
  if (!props.attendance || !props.attendance.total) return 100;
  return Math.round((props.attendance.present / props.attendance.total) * 100);
});

const presentPercent = computed(() => {
  if (!props.attendance || !props.attendance.total) return 100;
  return Math.round((props.attendance.present / props.attendance.total) * 100);
});

const absentPercent = computed(() => {
  if (!props.attendance || !props.attendance.total) return 0;
  return Math.round((props.attendance.absent / props.attendance.total) * 100);
});

// 2. Academic Progress & Grade Dynamics
const numericResults = computed(() => {
  const map: Record<string, number> = {
    excellent: 5,
    good: 4,
    satisfactory: 3,
    needs_support: 2,
    pass: 5,
  };

  return props.results
    .filter((r) => r.resultStatus && map[r.resultStatus] !== undefined)
    .slice()
    .sort((a, b) => Date.parse(a.startsAt) - Date.parse(b.startsAt))
    .map((r) => ({
      lesson: r,
      score: map[r.resultStatus!] ?? 4,
      date: r.startsAt,
      label: r.title,
    }));
});

const averageScore = computed(() => {
  if (!numericResults.value.length) return null;
  const sum = numericResults.value.reduce((acc, r) => acc + r.score, 0);
  return (sum / numericResults.value.length).toFixed(1);
});

// SVG Trend line points
const trendPoints = computed(() => {
  const list = numericResults.value;
  if (!list.length) return [];
  const width = 320;
  const height = 80;
  const padding = 16;
  const step = list.length > 1 ? (width - padding * 2) / (list.length - 1) : 0;

  return list.map((item, idx) => {
    const x = list.length === 1 ? width / 2 : padding + idx * step;
    // Map score 2..5 to height
    const normalized = (item.score - 2) / 3;
    const y = height - padding - normalized * (height - padding * 2);
    return {
      x,
      y,
      item,
    };
  });
});

const trendPolyline = computed(() => {
  return trendPoints.value.map((p) => `${p.x},${p.y}`).join(' ');
});

// 3. Debts & Overdue Homework
const nowTime = Date.now();
const homeworkDebts = computed(() => {
  return props.homework.filter((hw) => {
    const isOverdue =
      hw.dueAt &&
      Date.parse(hw.dueAt) < nowTime &&
      !['SUBMITTED', 'REVIEWED'].includes(hw.submissionState);
    const isReturned = hw.submissionState === 'RETURNED';
    return isOverdue || isReturned;
  });
});

// 4. Recent grades and comments
const recentFeedback = computed(() => {
  return props.results.filter((r) => r.resultComment || r.resultStatus).slice(0, 4);
});

function formatDate(val: string): string {
  try {
    return new Intl.DateTimeFormat(locale.value, {
      month: 'short',
      day: 'numeric',
      timeZone: props.timezone,
    }).format(new Date(val));
  } catch {
    return val.slice(0, 10);
  }
}
</script>

<template>
  <div class="edu-parent-analytics">
    <!-- Row 1: Attendance & Grade Rate Cards -->
    <div class="edu-parent-analytics__cards">
      <!-- Attendance Card with Gauge/Segmented Bar -->
      <article class="edu-card edu-parent-analytics__card">
        <div class="edu-parent-analytics__card-head">
          <h3 class="edu-parent-analytics__title">
            {{ t('education.parentAttendanceAnalytics') }}
          </h3>
          <span
            class="edu-parent-analytics__score-pill"
            :class="{
              'edu-parent-analytics__score-pill--good': attendanceRate >= 85,
              'edu-parent-analytics__score-pill--warn': attendanceRate < 85,
            }"
          >
            {{ attendanceRate }}%
          </span>
        </div>

        <div class="edu-parent-analytics__progress-bar">
          <div
            class="edu-parent-analytics__bar-fill edu-parent-analytics__bar-fill--present"
            :style="{ width: `${presentPercent}%` }"
            :title="`${t('education.present')}: ${attendance?.present ?? 0}`"
          />
          <div
            class="edu-parent-analytics__bar-fill edu-parent-analytics__bar-fill--absent"
            :style="{ width: `${absentPercent}%` }"
            :title="`${t('education.absentStatus')}: ${attendance?.absent ?? 0}`"
          />
        </div>

        <div class="edu-parent-analytics__legend">
          <span class="edu-parent-analytics__legend-item">
            <span class="edu-parent-analytics__dot edu-parent-analytics__dot--present"></span>
            {{ t('education.present') }}: {{ attendance?.present ?? 0 }}
          </span>
          <span class="edu-parent-analytics__legend-item">
            <span class="edu-parent-analytics__dot edu-parent-analytics__dot--absent"></span>
            {{ t('education.absentStatus') }}: {{ attendance?.absent ?? 0 }}
          </span>
          <span class="edu-parent-analytics__legend-item">
            <span class="edu-parent-analytics__dot edu-parent-analytics__dot--total"></span>
            {{ t('education.totalLessons') }}: {{ attendance?.total ?? 0 }}
          </span>
        </div>
      </article>

      <!-- Academic Progress & Trend Card -->
      <article class="edu-card edu-parent-analytics__card">
        <div class="edu-parent-analytics__card-head">
          <h3 class="edu-parent-analytics__title">
            {{ t('education.parentAcademicAnalytics') }}
          </h3>
          <span
            v-if="averageScore"
            class="edu-parent-analytics__score-pill edu-parent-analytics__score-pill--good"
          >
            ★ {{ averageScore }} / 5.0
          </span>
        </div>

        <!-- SVG Mini-Trend Chart -->
        <div
          v-if="trendPoints.length > 0"
          class="edu-parent-analytics__chart-wrap"
        >
          <svg
            viewBox="0 0 320 80"
            class="edu-parent-analytics__svg"
            role="img"
            :aria-label="t('education.parentGradeDynamics')"
          >
            <!-- Baseline -->
            <line
              x1="16"
              y1="64"
              x2="304"
              y2="64"
              stroke="var(--border)"
              stroke-width="1"
            />
            <!-- Trend line -->
            <polyline
              v-if="trendPoints.length > 1"
              :points="trendPolyline"
              fill="none"
              stroke="var(--accent)"
              stroke-width="2.5"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
            <!-- Dots -->
            <circle
              v-for="(p, i) in trendPoints"
              :key="i"
              :cx="p.x"
              :cy="p.y"
              r="4"
              fill="var(--accent)"
              class="edu-parent-analytics__chart-dot"
            >
              <title>{{ p.item.label }}: {{ p.item.score }} ({{ formatDate(p.item.date) }})</title>
            </circle>
          </svg>
        </div>
        <p
          v-else
          class="edu-parent-analytics__empty-hint"
        >
          {{ t('education.noResultsYet') }}
        </p>

        <p class="edu-parent-analytics__subtle-note">
          {{ t('education.parentGradesAssessed', { count: numericResults.length }) }}
        </p>
      </article>
    </div>

    <!-- Row 2: Homework Debts & Alerts Banner -->
    <div
      class="edu-parent-analytics__debt-banner"
      :class="{
        'edu-parent-analytics__debt-banner--alert': homeworkDebts.length > 0,
        'edu-parent-analytics__debt-banner--ok': homeworkDebts.length === 0,
      }"
    >
      <div class="edu-parent-analytics__debt-content">
        <span class="edu-parent-analytics__debt-icon">
          {{ homeworkDebts.length > 0 ? '⚠️' : '✅' }}
        </span>
        <div class="edu-parent-analytics__debt-info">
          <strong>
            {{
              homeworkDebts.length > 0
                ? t('education.parentHomeworkDebtsTitle', { count: homeworkDebts.length })
                : t('education.parentNoDebtsTitle')
            }}
          </strong>
          <span class="edu-parent-analytics__debt-desc">
            {{
              homeworkDebts.length > 0
                ? t('education.parentHomeworkDebtsDesc')
                : t('education.parentNoDebtsDesc')
            }}
          </span>
        </div>
      </div>

      <!-- Debt chips list if any -->
      <div
        v-if="homeworkDebts.length > 0"
        class="edu-parent-analytics__debt-chips"
      >
        <div
          v-for="hw in homeworkDebts.slice(0, 3)"
          :key="hw.id"
          class="edu-parent-analytics__debt-chip"
        >
          <span class="edu-parent-analytics__chip-title">{{ hw.title }}</span>
          <span class="edu-parent-analytics__chip-due">
            {{ hw.dueAt ? formatDate(hw.dueAt) : t('education.homeworkOverdue') }}
          </span>
        </div>
      </div>
    </div>

    <!-- Row 3: Recent Feedback & Evaluated Lessons -->
    <article
      v-if="recentFeedback.length > 0"
      class="edu-card edu-parent-analytics__feedback-card"
    >
      <h3 class="edu-parent-analytics__title">
        {{ t('education.parentRecentFeedbackTitle') }}
      </h3>
      <div class="edu-parent-analytics__feedback-grid">
        <div
          v-for="item in recentFeedback"
          :key="item.id"
          class="edu-parent-analytics__feedback-item"
        >
          <div class="edu-parent-analytics__feedback-head">
            <span class="edu-parent-analytics__feedback-subject">{{ item.title }}</span>
            <span
              v-if="item.resultStatus"
              class="edu-status"
              :class="`edu-status--${item.resultStatus}`"
            >
              {{ t(`education.${item.resultStatus}`) }}
            </span>
          </div>
          <p
            v-if="item.resultComment"
            class="edu-parent-analytics__feedback-quote"
          >
            «{{ item.resultComment }}»
          </p>
          <span class="edu-parent-analytics__feedback-date">
            {{ formatDate(item.startsAt) }} · {{ item.groupName }}
          </span>
        </div>
      </div>
    </article>
  </div>
</template>

<style scoped>
.edu-parent-analytics {
  display: flex;
  flex-direction: column;
  gap: 16px;
  width: 100%;
}

.edu-parent-analytics__cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 16px;
}

.edu-parent-analytics__card {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.edu-parent-analytics__card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.edu-parent-analytics__title {
  margin: 0;
  font-size: var(--type-h3-size);
  line-height: var(--type-h3-line);
  font-weight: var(--type-h3-weight);
  color: var(--text-1);
}

.edu-parent-analytics__score-pill {
  padding: 2px 8px;
  border-radius: var(--radius-card-s);
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-small-emphasis-weight);
  border: 1px solid var(--border);
}

.edu-parent-analytics__score-pill--good {
  background: var(--success-soft);
  color: var(--success-text);
}

.edu-parent-analytics__score-pill--warn {
  background: var(--danger-soft);
  color: var(--danger-text);
}

.edu-parent-analytics__progress-bar {
  display: flex;
  height: 8px;
  border-radius: 4px;
  overflow: hidden;
  background: var(--surface-2);
}

.edu-parent-analytics__bar-fill {
  height: 100%;
  transition: width var(--theme-transition);
}

.edu-parent-analytics__bar-fill--present {
  background: var(--success);
}

.edu-parent-analytics__bar-fill--absent {
  background: var(--danger);
}

.edu-parent-analytics__legend {
  display: flex;
  align-items: center;
  gap: 14px;
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  color: var(--text-2);
}

.edu-parent-analytics__legend-item {
  display: flex;
  align-items: center;
  gap: 5px;
}

.edu-parent-analytics__dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
}

.edu-parent-analytics__dot--present {
  background: var(--success);
}

.edu-parent-analytics__dot--absent {
  background: var(--danger);
}

.edu-parent-analytics__dot--total {
  background: var(--text-3);
}

.edu-parent-analytics__chart-wrap {
  width: 100%;
  height: 80px;
}

.edu-parent-analytics__svg {
  width: 100%;
  height: 100%;
}

.edu-parent-analytics__chart-dot {
  transition: transform var(--theme-transition);
  cursor: pointer;
}

.edu-parent-analytics__chart-dot:hover {
  transform: scale(1.3);
}

.edu-parent-analytics__empty-hint {
  margin: 0;
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  color: var(--text-2);
}

.edu-parent-analytics__subtle-note {
  margin: 0;
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  color: var(--text-2);
}

/* Debt Banner */
.edu-parent-analytics__debt-banner {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 12px 16px;
  border-radius: var(--radius-card-m);
  border: 1px solid var(--border);
  background: var(--surface);
}

.edu-parent-analytics__debt-banner--alert {
  background: var(--surface-2);
  border-color: var(--danger);
}

.edu-parent-analytics__debt-banner--ok {
  background: var(--surface-2);
  border-color: var(--border);
}

.edu-parent-analytics__debt-content {
  display: flex;
  align-items: center;
  gap: 12px;
}

.edu-parent-analytics__debt-icon {
  font-size: var(--type-h3-size);
  line-height: var(--type-h3-line);
  font-weight: var(--type-h3-weight);
}

.edu-parent-analytics__debt-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.edu-parent-analytics__debt-info strong {
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--text-1);
}

.edu-parent-analytics__debt-desc {
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  color: var(--text-2);
}

.edu-parent-analytics__debt-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.edu-parent-analytics__debt-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: var(--surface-2);
  border: 1px solid var(--border);
  padding: 4px 8px;
  border-radius: var(--radius-card-s);
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
}

.edu-parent-analytics__chip-title {
  color: var(--text-1);
  font-weight: var(--type-body-small-emphasis-weight);
}

.edu-parent-analytics__chip-due {
  color: var(--danger-text);
}

/* Feedback Grid */
.edu-parent-analytics__feedback-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 12px;
  margin-top: 8px;
}

.edu-parent-analytics__feedback-item {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 10px;
  background: var(--surface-2);
  border-radius: var(--radius-card-s);
  border: 1px solid var(--border);
}

.edu-parent-analytics__feedback-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
}

.edu-parent-analytics__feedback-subject {
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--text-1);
}

.edu-parent-analytics__feedback-quote {
  margin: 0;
  font-style: italic;
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  color: var(--text-2);
}

.edu-parent-analytics__feedback-date {
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  color: var(--text-2);
}
</style>
