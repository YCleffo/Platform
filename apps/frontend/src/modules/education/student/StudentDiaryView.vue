<script setup lang="ts">
import { computed, ref } from 'vue';
import { formatCalendarDate, formatZonedTime } from '@/shared/formatting/calendar';
import { studentPortalApi, type StudentDiaryLesson } from './api';
import { studentSession } from './session';
import { useEducationResource } from '../resources';
import { useEducationI18n } from '../i18n';
import EducationState from '../EducationState.vue';
const { t, locale } = useEducationI18n();
const org = computed(() => studentSession.organizationId);
const offset = ref(0);
const { data, loading, error, refresh } = useEducationResource(
  async (_org, signal) => studentPortalApi.diary(org.value, offset.value, signal),
  [org, offset],
);
function time(value: string): string {
  return formatZonedTime(value, locale.value, 'UTC');
}
function date(value: string): string {
  return formatCalendarDate(value, locale.value, { dateStyle: 'medium' });
}
function statusLabel(lesson: StudentDiaryLesson): string {
  return t(
    `education.${lesson.status === 'cancelled' ? 'cancelledStatus' : lesson.status === 'scheduled' ? 'scheduled' : 'completed'}`,
  );
}
</script>
<template>
  <section>
    <h2>{{ t('education.myDiary') }}</h2>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <template v-if="data">
      <EducationState
        v-if="!data.items.length"
        :empty="t('education.noAttendance')"
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
            <p>
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
              v-if="lesson.resultComment"
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
