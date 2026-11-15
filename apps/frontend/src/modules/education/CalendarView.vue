<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ChevronLeft, ChevronRight } from '@/shared/ui/icons';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import {
  addCalendarDays,
  calendarWeek,
  formatCalendarDate,
  isoToZonedDateTime,
} from '@/shared/formatting/calendar';
import { validDate } from '@/shared/formatting/temporal';
import { educationApi } from './api';
import CalendarTimeGrid from './CalendarTimeGrid.vue';
import { useEducationWorkspace } from './workspace';
import { useEducationResource } from './resources';
import { useEducationI18n } from './i18n';
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
const view = ref(route.query.view === 'week' ? 'week' : 'day');
const days = computed(() =>
  view.value === 'week' ? calendarWeek(selected.value) : [selected.value],
);
watch(
  () => route.query.date,
  (date) => {
    if (typeof date === 'string' && validDate(date)) selected.value = date;
  },
);
watch([selected, view], ([date, mode]) => {
  if (validDate(date)) void router.replace({ query: { date, view: mode } });
});
const { data, loading, error, refresh } = useEducationResource(
  (org, signal) => educationApi.schedule(org, days.value[0]!, days.value.at(-1)!, signal),
  [days],
);
const columns = computed(() =>
  days.value.map((day) => ({
    day,
    items: [
      ...(data.value?.lessons ?? []).map((lesson) => ({ ...lesson, kind: 'lesson' as const })),
      ...(data.value?.events ?? []),
    ]
      .filter(
        (item) =>
          isoToZonedDateTime(item.startsAt, workspace.header!.timezone).slice(0, 10) === day,
      )
      .sort((a, b) => a.startsAt.localeCompare(b.startsAt)),
  })),
);
watch(data, async (value) => {
  if (value && route.hash.startsWith('#event-')) {
    await nextTick();
    document.getElementById(route.hash.slice(1))?.scrollIntoView({ block: 'center' });
  }
});
function navigate(direction: number): void {
  selected.value = addCalendarDays(selected.value, direction * (view.value === 'week' ? 7 : 1));
}
</script>
<template>
  <section>
    <header class="edu-page-title">
      <h1>{{ t('education.calendar') }}</h1>
      <div class="edu-form-actions">
        <RouterLink
          :to="`/lessons/new?date=${selected}`"
          class="edu-button edu-button--primary"
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
          formatCalendarDate(addCalendarDays(selected, view === 'week' ? -7 : -1), locale, {
            dateStyle: 'full',
          })
        "
        @click="navigate(-1)"
      >
        <ChevronLeft
          :size="20"
          aria-hidden="true"
        />
      </button>
      <AppInput
        id="education-calendar-date"
        v-model="selected"
        type="date"
        :label="t('education.calendar')"
      />
      <button
        type="button"
        class="edu-icon-button"
        :aria-label="
          formatCalendarDate(addCalendarDays(selected, view === 'week' ? 7 : 1), locale, {
            dateStyle: 'full',
          })
        "
        @click="navigate(1)"
      >
        <ChevronRight
          :size="20"
          aria-hidden="true"
        />
      </button>
      <div
        class="edu-pills"
        role="group"
        :aria-label="t('education.calendar')"
      >
        <button
          type="button"
          :aria-pressed="view === 'day'"
          @click="view = 'day'"
        >
          {{ t('education.dayView') }}
        </button>
        <button
          type="button"
          :aria-pressed="view === 'week'"
          @click="view = 'week'"
        >
          {{ t('education.weekView') }}
        </button>
      </div>
      <button
        type="button"
        class="edu-button"
        @click="selected = workspace.header!.date"
      >
        {{ t('education.today') }}
      </button>
    </div>
    <p class="edu-subtle">
      {{ workspace.header!.timezone }}
      <span
        v-if="loading && data"
        role="status"
      >
        · {{ t('education.refreshing') }}
      </span>
    </p>
    <EducationState
      :loading="loading && !data"
      :error="error"
      @retry="refresh"
    />
    <CalendarTimeGrid
      v-if="data"
      :columns="columns"
      :timezone="workspace.header!.timezone"
      :today="workspace.header!.date"
      :now="workspace.header!.now"
      :day-view="view === 'day'"
    />
  </section>
</template>
