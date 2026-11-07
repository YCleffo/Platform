<script setup lang="ts">
import { computed } from 'vue';
import { formatCalendarDate, formatZonedTime } from '@/shared/formatting/calendar';
import { studentPortalApi } from './api';
import { studentSession } from './session';
import { useEducationResource } from '../resources';
import { useEducationI18n } from '../i18n';
import EducationState from '../EducationState.vue';
const { t, locale } = useEducationI18n();
const org = computed(() => studentSession.organizationId);
const { data, loading, error, refresh } = useEducationResource(
  async (_org, signal) => studentPortalApi.home(org.value, signal),
  [org],
);
function time(value: string): string {
  return formatZonedTime(value, locale.value, 'UTC');
}
function date(value: string): string {
  return formatCalendarDate(value, locale.value, { dateStyle: 'medium' });
}
function statusLabel(status: string): string {
  return t(
    `education.${status === 'pending' ? 'notAssessed' : status === 'needs_support' ? 'needsSupport' : status}`,
  );
}
</script>
<template>
  <section>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <template v-if="data">
      <article
        v-if="data.nextLesson"
        class="edu-card"
      >
        <h2>{{ t('education.nextLesson') }}</h2>
        <strong>{{ data.nextLesson.title }}</strong>
        <p class="edu-subtle">
          {{ date(data.nextLesson.startsAt) }} · {{ time(data.nextLesson.startsAt) }} –
          {{ time(data.nextLesson.endsAt) }}
        </p>
        <p>{{ data.nextLesson.groupName }}</p>
        <RouterLink
          :to="`/student/diary/${data.nextLesson.id}`"
          class="edu-button"
        >
          {{ t('education.openLesson') }}
        </RouterLink>
      </article>
      <article class="edu-card">
        <h2>{{ t('education.groups') }}</h2>
        <ul class="edu-list">
          <li
            v-for="group in data.groups"
            :key="group.id"
            class="edu-list__item"
          >
            <strong>{{ group.name }}</strong>
            <span v-if="group.archivedAt">{{ t('education.archived') }}</span>
          </li>
        </ul>
      </article>
      <article class="edu-card">
        <h2>{{ t('education.results') }}</h2>
        <EducationState
          v-if="!data.recentResults.length"
          :empty="t('education.noStudentResults')"
        />
        <ul
          v-else
          class="edu-list"
        >
          <li
            v-for="lesson in data.recentResults"
            :key="lesson.id"
            class="edu-list__item"
          >
            <strong>{{ lesson.title }}</strong>
            <span>{{ statusLabel(lesson.resultStatus) }}</span>
          </li>
        </ul>
      </article>
      <article class="edu-card">
        <h2>{{ t('education.activities') }}</h2>
        <ul class="edu-list">
          <li
            v-for="activity in data.activities"
            :key="activity.id"
            class="edu-list__item"
          >
            <RouterLink
              :to="`/student/typing/${activity.code}`"
              class="edu-list__main"
            >
              <strong>{{ activity.name }}</strong>
            </RouterLink>
          </li>
        </ul>
      </article>
      <article
        v-if="data.bestTyping"
        class="edu-card"
      >
        <h2>{{ t('education.bestTyping') }}</h2>
        <p>
          {{ data.bestTyping.wpm }} {{ t('education.wpm') }} · {{ data.bestTyping.accuracy }}% ·
          {{ data.bestTyping.durationSeconds }} {{ t('education.seconds') }}
        </p>
      </article>
    </template>
  </section>
</template>
