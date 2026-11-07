<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ChevronLeft, ChevronRight } from '@/shared/ui/icons';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import { addCalendarDays, formatCalendarDate, formatZonedTime } from '@/shared/formatting/calendar';
import { validDate } from '@/shared/formatting/temporal';
import { educationApi, type Lesson } from './api';
import { useEducationWorkspace } from './workspace';
import { useEducationResource } from './resources';
import { useEducationI18n } from './i18n';
import { lessonDisplayStatus } from './status';
import EducationState from './EducationState.vue';
const workspace = useEducationWorkspace();
const route = useRoute();
const router = useRouter();
const { t, locale } = useEducationI18n();
const selected = ref(
  typeof route.query.date === 'string' && validDate(route.query.date)
    ? route.query.date
    : workspace.header!.date,
);
watch(
  () => route.query.date,
  (date) => {
    if (typeof date === 'string' && validDate(date)) selected.value = date;
  },
);
watch(selected, (date) => {
  if (validDate(date)) void router.replace({ query: { date } });
});
const { data, loading, error, refresh } = useEducationResource(
  (org, signal) => educationApi.timeline(org, selected.value, signal),
  [selected],
);
const label = computed(() =>
  formatCalendarDate(selected.value, locale.value, {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
  }),
);
watch(data, async (value) => {
  if (value && route.hash.startsWith('#event-')) {
    await nextTick();
    document.getElementById(route.hash.slice(1))?.scrollIntoView({ block: 'center' });
  }
});
function time(value: string): string {
  return formatZonedTime(value, locale.value, workspace.header!.timezone);
}
function status(lesson: Lesson): string {
  const value = lessonDisplayStatus(lesson, workspace.header!.now);
  return t(`education.${value === 'now' ? 'inProgress' : value}`);
}
</script>
<template>
  <section>
    <header class="edu-page-title">
      <h1>{{ t('education.calendar') }}</h1>
      <div class="edu-form-actions">
        <RouterLink
          :to="`/lessons/new?date=${selected}`"
          class="edu-button"
        >
          {{ t('education.createLesson') }}
        </RouterLink>
        <RouterLink
          :to="`/calendar/new?date=${selected}`"
          class="edu-button"
        >
          {{ t('education.createEvent') }}
        </RouterLink>
      </div>
    </header>
    <div class="edu-toolbar">
      <button
        type="button"
        class="edu-icon-button"
        :aria-label="
          formatCalendarDate(addCalendarDays(selected, -1), locale, { dateStyle: 'full' })
        "
        @click="selected = addCalendarDays(selected, -1)"
      >
        <ChevronLeft :size="20" />
      </button>
      <AppInput
        id="education-calendar-date"
        v-model="selected"
        type="date"
        :label="t('education.week')"
      />
      <button
        type="button"
        class="edu-icon-button"
        :aria-label="
          formatCalendarDate(addCalendarDays(selected, 1), locale, { dateStyle: 'full' })
        "
        @click="selected = addCalendarDays(selected, 1)"
      >
        <ChevronRight :size="20" />
      </button>
    </div>
    <p class="edu-subtle">{{ workspace.header!.timezone }}</p>
    <h2>{{ label }}</h2>
    <EducationState
      :loading="loading"
      :error="error"
      :empty="data && !data.items.length ? t('education.emptyDay') : undefined"
      @retry="refresh"
    />
    <ul
      v-if="data"
      class="edu-list"
    >
      <li
        v-for="item in data.items"
        :id="`event-${item.id}`"
        :key="item.id"
      >
        <RouterLink
          v-if="item.kind === 'lesson'"
          :to="`/lessons/${item.id}`"
          class="edu-list__item"
        >
          <div>
            <strong>{{ item.title }}</strong>
            <p>{{ time(item.startsAt) }} – {{ time(item.endsAt) }} · {{ item.groupName }}</p>
            <small>{{ item.location }}</small>
          </div>
          <span
            class="edu-status"
            :class="`edu-status--${lessonDisplayStatus(item, workspace.header!.now)}`"
          >
            {{ status(item) }}
          </span>
          <ChevronRight
            :size="20"
            aria-hidden="true"
          />
        </RouterLink>
        <details
          v-else
          class="edu-list__item"
          :open="route.hash === `#event-${item.id}`"
        >
          <summary>
            <strong>{{ item.title }}</strong>
            · {{ time(item.startsAt) }}
          </summary>
          <p>
            {{ time(item.startsAt) }} – {{ time(item.endsAt) }} · {{ t(`education.${item.kind}`) }}
          </p>
          <p>{{ item.location }}</p>
        </details>
      </li>
    </ul>
  </section>
</template>
