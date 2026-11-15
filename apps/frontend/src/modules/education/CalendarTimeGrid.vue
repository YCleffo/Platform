<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import {
  formatCalendarDate,
  formatZonedTime,
  isoToZonedDateTime,
} from '@/shared/formatting/calendar';
import type { TimelineItem } from './api';
import { useEducationI18n } from './i18n';
import { lessonDisplayStatus } from './status';
const props = defineProps<{
  columns: { day: string; items: TimelineItem[] }[];
  timezone: string;
  today: string;
  now: string;
  dayView: boolean;
}>();
const { t, locale } = useEducationI18n();
const route = useRoute();
const scale = 84;
function minute(instant: string): number {
  const local = isoToZonedDateTime(instant, props.timezone);
  return Number(local.slice(11, 13)) * 60 + Number(local.slice(14, 16));
}
const startHour = computed(() =>
  Math.min(
    8,
    ...props.columns.flatMap((c) => c.items.map((item) => Math.floor(minute(item.startsAt) / 60))),
  ),
);
const endHour = computed(() =>
  Math.max(
    20,
    ...props.columns.flatMap((column) =>
      column.items.map((item) => {
        const endsNextDay =
          isoToZonedDateTime(item.endsAt, props.timezone).slice(0, 10) > column.day;
        return Math.min(24, Math.ceil((endsNextDay ? 1440 : minute(item.endsAt)) / 60));
      }),
    ),
  ),
);
const hours = computed(() =>
  Array.from({ length: endHour.value - startHour.value }, (_, index) => startHour.value + index),
);
const height = computed(() => hours.value.length * scale);
const layout = computed(() =>
  props.columns.map((column) => {
    const items = column.items
      .map((item) => {
        const start = minute(item.startsAt);
        const end =
          isoToZonedDateTime(item.endsAt, props.timezone).slice(0, 10) > column.day
            ? 1440
            : minute(item.endsAt);
        return { item, start, end: Math.max(end, start + 38), lane: 0, lanes: 1 };
      })
      .sort((a, b) => a.start - b.start);
    let cluster: typeof items = [];
    let active: typeof items = [];
    let lanes = 1;
    const finish = () => {
      for (const item of cluster) item.lanes = lanes;
      cluster = [];
      lanes = 1;
    };
    for (const item of items) {
      active = active.filter((other) => other.end > item.start);
      if (!active.length) finish();
      let lane = 0;
      while (active.some((other) => other.lane === lane)) lane++;
      item.lane = lane;
      lanes = Math.max(lanes, lane + 1);
      active.push(item);
      cluster.push(item);
    }
    finish();
    return { ...column, entries: items };
  }),
);
function time(instant: string): string {
  return formatZonedTime(instant, locale.value, props.timezone);
}
</script>
<template>
  <div
    class="edu-time-scroll"
    tabindex="0"
    :aria-label="t('education.calendar')"
  >
    <div
      class="edu-time-grid"
      :class="{ 'edu-time-grid--day': dayView }"
      :style="{ '--edu-day-count': columns.length }"
    >
      <div class="edu-time-hours">
        <div
          class="edu-time-heading"
          aria-hidden="true"
        ></div>
        <div
          class="edu-time-track"
          :style="{ height: `${height}px` }"
        >
          <span
            v-for="hour in hours"
            :key="hour"
            :style="{ top: `${(hour - startHour) * scale}px` }"
          >
            {{ String(hour).padStart(2, '0') }}:00
          </span>
        </div>
      </div>
      <section
        v-for="column in layout"
        :key="column.day"
        class="edu-time-day"
      >
        <h2
          class="edu-time-heading"
          :aria-current="column.day === today ? 'date' : undefined"
        >
          {{
            formatCalendarDate(column.day, locale, {
              weekday: 'short',
              day: 'numeric',
              month: 'short',
            })
          }}
        </h2>
        <div
          class="edu-time-track"
          :style="{ height: `${height}px` }"
        >
          <span
            v-for="hour in hours"
            :key="hour"
            class="edu-time-line"
            :style="{ top: `${(hour - startHour) * scale}px` }"
            aria-hidden="true"
          ></span>
          <p
            v-if="!column.entries.length"
            class="edu-time-empty edu-subtle"
          >
            {{ t('education.emptyDay') }}
          </p>
          <template
            v-for="entry in column.entries"
            :key="entry.item.id"
          >
            <RouterLink
              v-if="entry.item.kind === 'lesson'"
              :id="`event-${entry.item.id}`"
              :to="`/lessons/${entry.item.id}`"
              class="edu-time-event"
              :class="`edu-calendar-entry--${entry.item.status}`"
              :style="{
                top: `${((entry.start - startHour * 60) * scale) / 60}px`,
                height: `${((entry.end - entry.start) * scale) / 60 - 4}px`,
                left: `calc(${(entry.lane * 100) / entry.lanes}% + 4px)`,
                width: `calc(${100 / entry.lanes}% - 8px)`,
              }"
            >
              <time :datetime="entry.item.startsAt">
                {{ time(entry.item.startsAt) }} · {{ time(entry.item.endsAt) }}
              </time>
              <strong>{{ entry.item.title }}</strong>
              <small>
                {{ entry.item.groupName }}
                <template v-if="entry.item.location">· {{ entry.item.location }}</template>
              </small>
              <span>{{ t(`education.${lessonDisplayStatus(entry.item, now)}`) }}</span>
            </RouterLink>
            <details
              v-else
              :id="`event-${entry.item.id}`"
              class="edu-time-event"
              :open="route.hash === `#event-${entry.item.id}`"
              :style="{
                top: `${((entry.start - startHour * 60) * scale) / 60}px`,
                height: `${((entry.end - entry.start) * scale) / 60 - 4}px`,
                left: `calc(${(entry.lane * 100) / entry.lanes}% + 4px)`,
                width: `calc(${100 / entry.lanes}% - 8px)`,
              }"
            >
              <summary>{{ time(entry.item.startsAt) }} · {{ entry.item.title }}</summary>
              <p>{{ entry.item.location }}</p>
            </details>
          </template>
        </div>
      </section>
    </div>
  </div>
</template>
<style scoped>
.edu-time-scroll {
  overflow: auto;
  max-height: 75dvh;
  border: 1px solid var(--border);
  border-radius: var(--radius-card-m);
  overscroll-behavior-inline: contain;
}
.edu-time-grid {
  display: grid;
  grid-template-columns: 56px repeat(var(--edu-day-count), minmax(145px, 1fr));
  min-width: calc(56px + var(--edu-day-count) * 145px);
  background: var(--surface);
}
.edu-time-grid--day {
  min-width: 0;
  grid-template-columns: 56px minmax(0, 1fr);
}
.edu-time-heading {
  display: grid;
  align-content: center;
  min-height: 64px;
  padding: 12px 8px;
  position: sticky;
  top: 0;
  z-index: 4;
  background: var(--surface-2);
  border-bottom: 1px solid var(--border);
  font-size: var(--type-body-small-size);
  margin: 0;
}
.edu-time-heading[aria-current='date'] {
  color: var(--accent-text);
  background: var(--accent-soft);
}
.edu-time-day {
  min-width: 0;
  border-left: 1px solid var(--border);
}
.edu-time-track {
  position: relative;
}
.edu-time-hours {
  position: sticky;
  left: 0;
  z-index: 5;
  background: var(--surface);
}
.edu-time-hours .edu-time-track > span {
  position: absolute;
  left: 5px;
  padding: 4px 0;
  color: var(--text-2);
  font-size: var(--type-body-caption-size);
}
.edu-time-line {
  position: absolute;
  width: 100%;
  height: 1px;
  background: var(--border);
}
.edu-time-event {
  position: absolute;
  display: flex;
  flex-direction: column;
  gap: 4px;
  overflow: auto;
  padding: 7px;
  background: var(--accent-soft);
  border: 1px solid var(--border);
  border-left: 3px solid var(--accent);
  border-radius: var(--radius-control-s);
  color: var(--text-1);
  text-decoration: none;
  font-size: var(--type-body-caption-size);
  overflow-wrap: anywhere;
}
.edu-time-event strong {
  font-size: var(--type-body-small-size);
}
.edu-time-event :is(time, small) {
  color: var(--text-2);
}
.edu-time-event.edu-calendar-entry--completed {
  background: var(--success-soft);
  border-left-color: var(--success);
}
.edu-time-event.edu-calendar-entry--cancelled {
  background: var(--surface-2);
  border-left-color: var(--text-3);
}
.edu-time-empty {
  position: absolute;
  top: 20px;
  padding: 10px;
}
</style>
