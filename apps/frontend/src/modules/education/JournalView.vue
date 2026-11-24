<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import { formatCalendarDate, formatZonedTime } from '@/shared/formatting/calendar';
import { educationApi } from './api';
import { useEducationWorkspace } from './workspace';
import { useEducationResource } from './resources';
import { useEducationI18n } from './i18n';
import EducationState from './EducationState.vue';
import HomeworkSummary from './homework/HomeworkSummary.vue';
const route = useRoute();
const workspace = useEducationWorkspace();
const { t, locale } = useEducationI18n();
const id = computed(() => String(route.params.id ?? ''));
const offset = ref(0);
const from = ref('');
const to = ref('');
const studentId = ref('');
const periodInvalid = computed(() => !!from.value && !!to.value && from.value > to.value);
const filters = computed(() => ({
  offset: offset.value,
  ...(from.value && to.value ? { from: from.value, to: to.value } : {}),
  ...(studentId.value ? { studentId: studentId.value } : {}),
}));
const { data, loading, error, refresh } = useEducationResource(
  async (org, signal) => {
    const [group, roster, journal] = await Promise.all([
      educationApi.group(org, id.value, signal),
      educationApi.groupStudents(org, id.value, 0, signal),
      educationApi.journal(org, id.value, filters.value, signal),
    ]);
    return { group, roster, journal };
  },
  [id, filters],
);
watch(
  () => [from.value, to.value, studentId.value],
  () => {
    offset.value = 0;
  },
);
function time(value: string): string {
  return formatZonedTime(value, locale.value, workspace.header!.timezone);
}
function date(value: string): string {
  return formatCalendarDate(
    new Intl.DateTimeFormat('en-CA', {
      timeZone: workspace.header!.timezone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).format(new Date(value)),
    locale.value,
    { dateStyle: 'long' },
  );
}
function statusLabel(status: string): string {
  return t(
    `education.${status === 'pending' ? 'notAssessed' : status === 'needs_support' ? 'needsSupport' : status}`,
  );
}

const viewMode = ref<'table' | 'cards'>('cards');

const sortedLessons = computed(() => {
  if (!data.value) return [];
  return [...data.value.journal.items].reverse();
});

function studentLessonResult(
  results: Array<{ studentId: string; status: string; comment?: string | null; name: string }>,
  sId: string,
) {
  return results.find((r) => r.studentId === sId);
}

function gradeScore(status: string): number | null {
  if (status === 'mastered') return 5;
  if (status === 'developing') return 4;
  if (status === 'needs_support') return 3;
  return null;
}

function studentGradeStats(sId: string) {
  if (!data.value) {
    return {
      attended: 0,
      total: 0,
      percent: 0,
      absences: 0,
      avgGrade: null,
      avgFormatted: '—',
      gradeClass: '',
    };
  }
  let attended = 0;
  let total = 0;
  const grades: number[] = [];
  for (const lesson of data.value.journal.items) {
    const res = lesson.results.find((r) => r.studentId === sId);
    if (res) {
      total++;
      if (res.status !== 'pending') {
        attended++;
      }
      const score = gradeScore(res.status);
      if (score !== null) {
        grades.push(score);
      }
    }
  }
  const percent = total > 0 ? Math.round((attended / total) * 100) : 0;
  const absences = total - attended;
  const avg = grades.length > 0 ? grades.reduce((a, b) => a + b, 0) / grades.length : null;
  const avgFormatted = avg !== null ? avg.toFixed(1) : '—';
  const gradeClass =
    avg !== null ? (avg >= 4.5 ? 'completed' : avg >= 3.5 ? 'now' : 'cancelled') : '';
  return { attended, total, percent, absences, avgGrade: avg, avgFormatted, gradeClass };
}

const activeQuarter = ref<'all' | 'q1' | 'q2' | 'q3' | 'q4' | 'sem1' | 'sem2'>('all');

function setQuarterPreset(preset: 'all' | 'q1' | 'q2' | 'q3' | 'q4' | 'sem1' | 'sem2'): void {
  activeQuarter.value = preset;
  const now = new Date();
  const currentYear = now.getFullYear();
  const baseYear = now.getMonth() >= 7 ? currentYear : currentYear - 1;
  const nextYear = baseYear + 1;

  if (preset === 'q1') {
    from.value = `${baseYear}-09-01`;
    to.value = `${baseYear}-10-31`;
  } else if (preset === 'q2') {
    from.value = `${baseYear}-11-01`;
    to.value = `${baseYear}-12-31`;
  } else if (preset === 'q3') {
    from.value = `${nextYear}-01-10`;
    to.value = `${nextYear}-03-25`;
  } else if (preset === 'q4') {
    from.value = `${nextYear}-04-01`;
    to.value = `${nextYear}-05-31`;
  } else if (preset === 'sem1') {
    from.value = `${baseYear}-09-01`;
    to.value = `${baseYear}-12-31`;
  } else if (preset === 'sem2') {
    from.value = `${nextYear}-01-10`;
    to.value = `${nextYear}-05-31`;
  } else {
    from.value = '';
    to.value = '';
  }
}

const studentFilterMode = ref<'all' | 'needs_support' | 'low_attendance'>('all');

const filteredRoster = computed(() => {
  if (!data.value) return [];
  if (studentFilterMode.value === 'all') return data.value.roster.items;
  return data.value.roster.items.filter((student) => {
    const stats = studentGradeStats(student.id);
    if (studentFilterMode.value === 'needs_support') {
      return stats.avgGrade !== null && stats.avgGrade < 3.8;
    }
    if (studentFilterMode.value === 'low_attendance') {
      return stats.percent < 80;
    }
    return true;
  });
});

const lessonSummaryMap = computed(() => {
  if (!data.value) return {};
  const map: Record<string, { avg: string; attendancePercent: number }> = {};
  for (const lesson of data.value.journal.items) {
    let attended = 0;
    const total = lesson.results.length;
    const scores: number[] = [];
    for (const res of lesson.results) {
      if (res.status !== 'pending') {
        attended++;
      }
      const s = gradeScore(res.status);
      if (s !== null) scores.push(s);
    }
    const pct = total > 0 ? Math.round((attended / total) * 100) : 0;
    const avg =
      scores.length > 0 ? (scores.reduce((a, b) => a + b, 0) / scores.length).toFixed(1) : '—';
    map[lesson.id] = { avg, attendancePercent: pct };
  }
  return map;
});

const overallGroupStats = computed(() => {
  if (!data.value) return { avgGrade: '—', attendancePercent: 0, totalAbsences: 0 };
  let totalAttended = 0;
  let totalLessons = 0;
  let totalAbsences = 0;
  const allScores: number[] = [];
  for (const student of data.value.roster.items) {
    const stats = studentGradeStats(student.id);
    totalAttended += stats.attended;
    totalLessons += stats.total;
    totalAbsences += stats.absences;
    if (stats.avgGrade !== null) {
      allScores.push(stats.avgGrade);
    }
  }
  const pct = totalLessons > 0 ? Math.round((totalAttended / totalLessons) * 100) : 0;
  const avg =
    allScores.length > 0
      ? (allScores.reduce((a, b) => a + b, 0) / allScores.length).toFixed(1)
      : '—';
  return { avgGrade: avg, attendancePercent: pct, totalAbsences };
});

function exportGradebookCsv(): void {
  if (!data.value) return;
  const headers = [
    t('education.students'),
    ...sortedLessons.value.map((l) => `${date(l.startsAt)} (${l.report?.topic || l.title})`),
    t('education.averageGrade'),
    t('education.attendanceRate'),
    t('education.absences'),
  ];
  const rows = data.value.roster.items.map((student) => {
    const stats = studentGradeStats(student.id);
    const lessonCols = sortedLessons.value.map((lesson) => {
      const res = studentLessonResult(lesson.results, student.id);
      if (!res) return '—';
      const score = gradeScore(res.status);
      return score !== null ? `${score} (${statusLabel(res.status)})` : statusLabel(res.status);
    });
    return [
      student.name ?? student.id,
      ...lessonCols,
      stats.avgFormatted,
      `${stats.percent}%`,
      String(stats.absences),
    ];
  });

  const csvContent =
    '\uFEFF' +
    [headers, ...rows]
      .map((r) => r.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(';'))
      .join('\r\n');
  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.setAttribute('download', `${data.value.group.name || 'group'}-gradebook.csv`);
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}
</script>
<template>
  <section>
    <HomeworkSummary
      :group-id="id"
      :student-id="studentId || undefined"
    />
    <header class="edu-page-title">
      <h1>{{ data?.group.name ?? t('education.journal') }} · {{ t('education.journal') }}</h1>
      <RouterLink
        :to="`/groups/${id}`"
        class="edu-link"
      >
        {{ t('education.group') }}
      </RouterLink>
    </header>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <form
      v-if="data"
      class="edu-form edu-card"
      @submit.prevent="refresh"
    >
      <!-- Quarter / Period presets -->
      <div class="edu-journal__presets">
        <button
          type="button"
          class="edu-button"
          :class="{ 'edu-button--primary': activeQuarter === 'all' && !from && !to }"
          @click="setQuarterPreset('all')"
        >
          {{ t('education.filterAll') }}
        </button>
        <button
          type="button"
          class="edu-button"
          :class="{ 'edu-button--primary': activeQuarter === 'q1' }"
          @click="setQuarterPreset('q1')"
        >
          {{ t('education.quarter1') }}
        </button>
        <button
          type="button"
          class="edu-button"
          :class="{ 'edu-button--primary': activeQuarter === 'q2' }"
          @click="setQuarterPreset('q2')"
        >
          {{ t('education.quarter2') }}
        </button>
        <button
          type="button"
          class="edu-button"
          :class="{ 'edu-button--primary': activeQuarter === 'q3' }"
          @click="setQuarterPreset('q3')"
        >
          {{ t('education.quarter3') }}
        </button>
        <button
          type="button"
          class="edu-button"
          :class="{ 'edu-button--primary': activeQuarter === 'q4' }"
          @click="setQuarterPreset('q4')"
        >
          {{ t('education.quarter4') }}
        </button>
        <button
          type="button"
          class="edu-button"
          :class="{ 'edu-button--primary': activeQuarter === 'sem1' }"
          @click="setQuarterPreset('sem1')"
        >
          {{ t('education.semester1') }}
        </button>
        <button
          type="button"
          class="edu-button"
          :class="{ 'edu-button--primary': activeQuarter === 'sem2' }"
          @click="setQuarterPreset('sem2')"
        >
          {{ t('education.semester2') }}
        </button>
      </div>

      <div class="edu-form-actions">
        <AppInput
          id="education-journal-from"
          v-model="from"
          type="date"
          :label="t('education.periodFrom')"
          :error="periodInvalid ? t('education.invalidTime') : undefined"
        />
        <AppInput
          id="education-journal-to"
          v-model="to"
          type="date"
          :label="t('education.periodTo')"
          :error="periodInvalid ? t('education.invalidTime') : undefined"
        />
        <AppDropdown
          id="education-journal-student"
          v-model="studentId"
          :label="t('education.students')"
          :options="[
            { value: '', label: t('education.allStudents') },
            ...data.roster.items.map((student) => ({
              value: student.id,
              label: student.name ?? student.id,
            })),
          ]"
        />
        <button
          type="submit"
          class="edu-button"
          :disabled="periodInvalid"
        >
          {{ t('education.refresh') }}
        </button>
      </div>
    </form>
    <template v-if="data">
      <div
        v-if="data.journal.items.length"
        class="edu-form-actions"
        style="margin-bottom: 12px; justify-content: space-between"
      >
        <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center">
          <button
            type="button"
            class="edu-button"
            :class="{ 'edu-button--primary': viewMode === 'table' }"
            @click="viewMode = 'table'"
          >
            {{ t('education.journalTable') }}
          </button>
          <button
            type="button"
            class="edu-button"
            :class="{ 'edu-button--primary': viewMode === 'cards' }"
            @click="viewMode = 'cards'"
          >
            {{ t('education.journalCards') }}
          </button>

          <!-- Focus filters in table mode -->
          <template v-if="viewMode === 'table'">
            <button
              type="button"
              class="edu-button"
              :class="{ 'edu-button--primary': studentFilterMode === 'all' }"
              @click="studentFilterMode = 'all'"
            >
              {{ t('education.filterAll') }}
            </button>
            <button
              type="button"
              class="edu-button"
              :class="{ 'edu-button--primary': studentFilterMode === 'needs_support' }"
              @click="studentFilterMode = 'needs_support'"
            >
              {{ t('education.filterNeedsSupport') }}
            </button>
            <button
              type="button"
              class="edu-button"
              :class="{ 'edu-button--primary': studentFilterMode === 'low_attendance' }"
              @click="studentFilterMode = 'low_attendance'"
            >
              {{ t('education.filterLowAttendance') }}
            </button>
          </template>
        </div>

        <button
          type="button"
          class="edu-button"
          @click="exportGradebookCsv"
        >
          📥 {{ t('education.exportCsv') }}
        </button>
      </div>

      <EducationState
        v-if="!data.journal.items.length"
        :empty="from || to ? t('education.noJournalPeriod') : t('education.noJournal')"
      />

      <!-- Tabular Grid Mode -->
      <div
        v-else-if="viewMode === 'table'"
        class="edu-journal__matrix-wrapper"
      >
        <table class="edu-journal__matrix">
          <thead>
            <tr>
              <th class="edu-journal__th-student">
                {{ t('education.students') }}
              </th>
              <th
                v-for="entry in sortedLessons"
                :key="entry.id"
              >
                <RouterLink
                  :to="`/lessons/${entry.id}/results`"
                  class="edu-journal__col-header"
                >
                  <span class="edu-journal__col-date">{{ date(entry.startsAt) }}</span>
                  <span
                    class="edu-journal__col-topic"
                    :title="entry.report?.topic || entry.title"
                  >
                    {{ entry.report?.topic || entry.title }}
                  </span>
                </RouterLink>
              </th>
              <th>
                {{ t('education.averageGrade') }}
              </th>
              <th>
                {{ t('education.attendanceRate') }}
              </th>
              <th>
                {{ t('education.absences') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="student in filteredRoster"
              :key="student.id"
            >
              <td class="edu-journal__td-student">
                <strong>{{ student.name ?? student.id }}</strong>
              </td>
              <td
                v-for="entry in sortedLessons"
                :key="entry.id"
                class="edu-journal__score-cell"
              >
                <template v-if="studentLessonResult(entry.results, student.id)">
                  <span
                    class="edu-status"
                    :class="`edu-status--${studentLessonResult(entry.results, student.id)!.status === 'needs_support' ? 'cancelled' : studentLessonResult(entry.results, student.id)!.status}`"
                    :title="`${statusLabel(studentLessonResult(entry.results, student.id)!.status)}${studentLessonResult(entry.results, student.id)!.comment ? ': ' + studentLessonResult(entry.results, student.id)!.comment : ''}`"
                  >
                    <strong class="edu-journal__score-val">
                      {{
                        gradeScore(studentLessonResult(entry.results, student.id)!.status) ?? '✓'
                      }}
                    </strong>
                  </span>
                </template>
                <span
                  v-else
                  class="edu-subtle"
                >
                  —
                </span>
              </td>
              <td>
                <span
                  v-if="studentGradeStats(student.id).avgGrade !== null"
                  class="edu-status"
                  :class="`edu-status--${studentGradeStats(student.id).gradeClass}`"
                >
                  {{ studentGradeStats(student.id).avgFormatted }}
                </span>
                <span
                  v-else
                  class="edu-subtle"
                >
                  —
                </span>
              </td>
              <td>
                <span class="edu-subtle">
                  {{ studentGradeStats(student.id).attended }}/{{
                    studentGradeStats(student.id).total
                  }}
                  ({{ studentGradeStats(student.id).percent }}%)
                </span>
              </td>
              <td>
                <span
                  class="edu-subtle"
                  :style="
                    studentGradeStats(student.id).absences > 0
                      ? 'color: var(--danger-text); font-weight: 600;'
                      : ''
                  "
                >
                  {{ studentGradeStats(student.id).absences }}
                </span>
              </td>
            </tr>
          </tbody>
          <tfoot>
            <tr>
              <th class="edu-journal__th-student">
                {{ t('education.groupSummary') }}
              </th>
              <td
                v-for="entry in sortedLessons"
                :key="entry.id"
                class="edu-journal__score-cell"
              >
                <div style="font-size: var(--type-body-caption-size); display: grid; gap: 2px">
                  <span>Ø {{ lessonSummaryMap[entry.id]?.avg ?? '—' }}</span>
                  <span class="edu-subtle">
                    {{ lessonSummaryMap[entry.id]?.attendancePercent ?? 0 }}%
                  </span>
                </div>
              </td>
              <td>
                <span
                  class="edu-status edu-status--now"
                  style="font-weight: 700"
                >
                  Ø {{ overallGroupStats.avgGrade }}
                </span>
              </td>
              <td>
                <span
                  class="edu-subtle"
                  style="font-weight: 600"
                >
                  {{ overallGroupStats.attendancePercent }}%
                </span>
              </td>
              <td>
                <span class="edu-subtle">
                  {{ overallGroupStats.totalAbsences }}
                </span>
              </td>
            </tr>
          </tfoot>
        </table>

        <!-- Grade Legend -->
        <div class="edu-journal__legend">
          <span>{{ t('education.legendMastered') }}</span>
          <span>{{ t('education.legendDeveloping') }}</span>
          <span>{{ t('education.legendNeedsSupport') }}</span>
          <span>{{ t('education.legendPending') }}</span>
        </div>
      </div>

      <!-- Classic Cards/List Mode -->
      <ul
        v-else
        class="edu-list"
      >
        <li
          v-for="entry in data.journal.items"
          :key="entry.id"
          class="edu-list__item edu-journal__entry"
        >
          <div class="edu-list__main">
            <strong>{{ date(entry.startsAt) }} · {{ entry.title }}</strong>
            <p class="edu-subtle">
              {{ time(entry.startsAt) }} – {{ time(entry.endsAt) }}
              <template v-if="entry.report">· {{ entry.report.topic }}</template>
            </p>
            <p v-if="entry.report">
              {{ entry.report.summary }}
            </p>
            <p
              v-if="entry.report?.teacherComment"
              class="edu-subtle"
            >
              {{ t('education.teacherComment') }}: {{ entry.report.teacherComment }}
            </p>
            <ul class="edu-journal__results">
              <li
                v-for="result in entry.results"
                :key="result.studentId"
              >
                <span>{{ result.name }}:</span>
                <span
                  class="edu-status"
                  :class="`edu-status--${result.status === 'needs_support' ? 'cancelled' : result.status}`"
                >
                  {{ statusLabel(result.status) }}
                </span>
                <span
                  v-if="result.comment"
                  class="edu-subtle"
                >
                  {{ result.comment }}
                </span>
              </li>
            </ul>
          </div>
          <RouterLink
            :to="`/lessons/${entry.id}/results`"
            class="edu-button"
          >
            {{ t('education.openLesson') }}
          </RouterLink>
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
          v-if="data.journal.nextOffset !== null"
          type="button"
          class="edu-button"
          @click="offset = data.journal.nextOffset ?? 0"
        >
          {{ t('education.loadMore') }}
        </button>
      </div>
    </template>
  </section>
</template>
