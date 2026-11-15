<script setup lang="ts">
import { computed } from 'vue';
import { useEducationI18n } from './i18n';
import type { ActivityHistoryEntry } from './activityTypes';
const props = defineProps<{
  entries: ActivityHistoryEntry[];
  metric: 'wpm' | 'accuracy' | 'score';
  label: string;
  unit?: string;
}>();
const { t, locale } = useEducationI18n();
const values = computed(() =>
  props.entries
    .filter((e) => typeof e[props.metric] === 'number')
    .slice()
    .sort((a, b) => Date.parse(a.createdAt) - Date.parse(b.createdAt)),
);
const max = computed(() =>
  Math.max(props.metric === 'accuracy' ? 100 : 1, ...values.value.map((e) => e[props.metric] ?? 0)),
);
const points = computed(() => {
  const dates = values.value.map((e) => Date.parse(e.createdAt)),
    first = dates[0] ?? 0,
    span = (dates[dates.length - 1] ?? 0) - first;
  return values.value.map((entry, index) => ({
    entry,
    x:
      values.value.length === 1
        ? 300
        : span > 0
          ? 24 + (((dates[index] ?? 0) - first) / span) * 552
          : 24 + (index / (values.value.length - 1)) * 552,
    y: 132 - ((entry[props.metric] ?? 0) / max.value) * 112,
  }));
});
const line = computed(() => points.value.map((p) => `${p.x},${p.y}`).join(' '));
function date(value: string): string {
  return new Intl.DateTimeFormat(locale.value, { month: 'short', day: 'numeric' }).format(
    new Date(value),
  );
}
</script>
<template>
  <figure class="edu-trend edu-card">
    <figcaption>
      <strong>{{ label }}</strong>
      <span class="edu-caption">{{ t('education.completedAttempts') }}: {{ values.length }}</span>
    </figcaption>
    <p
      v-if="!values.length"
      class="edu-subtle"
    >
      {{ t('education.noTrainingResults') }}
    </p>
    <template v-else>
      <svg
        viewBox="0 0 600 160"
        role="img"
        :aria-label="label"
        class="edu-trend__svg"
      >
        <title>{{ label }}</title>
        <path
          d="M24 20V132H576"
          fill="none"
          stroke="var(--border-strong)"
        />
        <text
          x="4"
          y="16"
        >
          {{ max }}
        </text>
        <text
          x="4"
          y="136"
        >
          0
        </text>
        <polyline
          v-if="points.length > 1"
          :points="line"
          fill="none"
          stroke="var(--accent)"
          stroke-width="3"
          stroke-linejoin="round"
        />
        <circle
          v-for="point in points"
          :key="point.entry.attemptId"
          :cx="point.x"
          :cy="point.y"
          r="4"
          fill="var(--accent)"
        >
          <title>{{ date(point.entry.createdAt) }}: {{ point.entry[metric] }} {{ unit }}</title>
        </circle>
        <text
          x="24"
          y="154"
        >
          {{ date(values[0]!.createdAt) }}
        </text>
        <text
          x="576"
          y="154"
          text-anchor="end"
        >
          {{ date(values[values.length - 1]!.createdAt) }}
        </text>
      </svg>
      <details>
        <summary class="edu-link">{{ t('education.chartData') }}</summary>
        <ul class="edu-list">
          <li
            v-for="entry in values"
            :key="entry.attemptId"
            class="edu-list__item"
          >
            <time :datetime="entry.createdAt">
              {{ new Date(entry.createdAt).toLocaleString(locale) }}
            </time>
            <span>{{ entry[metric] }} {{ unit }}</span>
          </li>
        </ul>
      </details>
    </template>
  </figure>
</template>
