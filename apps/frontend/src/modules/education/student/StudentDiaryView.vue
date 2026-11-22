<script setup lang="ts">
import { computed, ref } from 'vue';
import {
  addCalendarDays,
  calendarWeek,
  formatCalendarDate,
  formatZonedTime,
  isoToZonedDateTime,
} from '@/shared/formatting/calendar';
import { studentPortalApi, type StudentDiaryLesson } from './api';
import { studentSession } from './session';
import { useHomeworkResource } from '../homework/resources';
import { useEducationI18n } from '../i18n';
import EducationState from '../EducationState.vue';
import HomeworkSummary from '../homework/HomeworkSummary.vue';
const { t, locale } = useEducationI18n();
const props = defineProps<{ schedule?: boolean }>();
const offset = ref(0);
const weekOffset = ref(0);
const week = computed(() =>
  calendarWeek(
    addCalendarDays(
      isoToZonedDateTime(new Date().toISOString(), studentSession.timezone).slice(0, 10),
      weekOffset.value * 7,
    ),
  ),
);
const { data, loading, error, refresh } = useHomeworkResource(
  () => studentSession.organizationId,
  async (org, signal) => {
    if (!props.schedule) return studentPortalApi.diary(org, offset.value, signal);
    const result = await studentPortalApi.schedule(org, week.value[0]!, week.value[6]!, signal);
    return { items: result.items, nextOffset: null };
  },
  [() => props.schedule, offset, weekOffset],
);
function time(value: string): string {
  return formatZonedTime(value, locale.value, studentSession.timezone);
}
function date(value: string): string {
  return formatCalendarDate(
    isoToZonedDateTime(value, studentSession.timezone).slice(0, 10),
    locale.value,
    { dateStyle: 'medium' },
  );
}
function statusLabel(lesson: StudentDiaryLesson): string {
  return t(
    `education.${lesson.status === 'cancelled' ? 'cancelledStatus' : lesson.status === 'scheduled' ? 'scheduled' : 'completed'}`,
  );
}
</script>
<template>
  <section>
    <header class="edu-page-title">
      <h2>{{ t(schedule ? 'education.parentschedule' : 'education.myDiary') }}</h2>
      <RouterLink
        :to="schedule ? '/student/diary' : '/student/schedule'"
        class="edu-link"
      >
        {{ t(schedule ? 'education.myDiary' : 'education.parentschedule') }}
      </RouterLink>
    </header>
    <div
      v-if="schedule"
      class="edu-form-actions"
    >
      <button
        type="button"
        class="edu-button"
        @click="weekOffset--"
      >
        {{ t('education.previousWeek') }}
      </button>
      <p role="status">
        {{ formatCalendarDate(week[0]!, locale, { dateStyle: 'medium' }) }} –
        {{ formatCalendarDate(week[6]!, locale, { dateStyle: 'medium' }) }}
      </p>
      <button
        type="button"
        class="edu-button"
        @click="weekOffset++"
      >
        {{ t('education.nextWeek') }}
      </button>
    </div>
    <HomeworkSummary
      v-else
      student
      compact
    />
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <template v-if="data">
      <EducationState
        v-if="!data.items.length"
        :empty="t(schedule ? 'education.noLessons' : 'education.noAttendance')"
      />
      <ul
        v-else
        class="edu-list"
      >
        <li
          v-for="lesson in data.items"
          :key="lesson.id"
          class="edu-list__item"
        >
          <RouterLink
            :to="`/student/diary/${lesson.id}`"
            class="edu-list__main"
          >
            <strong>{{ lesson.title }}</strong>
            <p class="edu-subtle">
              {{ date(lesson.startsAt) }} · {{ time(lesson.startsAt) }} –
              {{ time(lesson.endsAt) }} ·
              {{ statusLabel(lesson) }}
            </p>
            <p v-if="!schedule">
              {{
                t(
                  `education.${lesson.attendance === 'absent' ? 'absentStatus' : lesson.attendance}`,
                )
              }}
              <template v-if="lesson.resultStatus !== 'pending'">
                ·
                {{
                  t(
                    `education.${lesson.resultStatus === 'needs_support' ? 'needsSupport' : lesson.resultStatus}`,
                  )
                }}
              </template>
            </p>
            <p
              v-if="!schedule && lesson.resultComment"
              class="edu-subtle"
            >
              {{ lesson.resultComment }}
            </p>
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
