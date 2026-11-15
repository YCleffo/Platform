<script setup lang="ts">
import { useEducationI18n } from '../i18n';
import type { ParentLesson } from './api';
import EducationState from '../EducationState.vue';
const props = defineProps<{ items: ParentLesson[]; timezone: string; diary?: boolean }>();
const { t, locale } = useEducationI18n();
const when = (value: string) =>
  new Intl.DateTimeFormat(locale.value, {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: props.timezone,
  }).format(new Date(value));
const resultLabel = (value: string) =>
  t(
    `education.${value === 'pending' ? 'notAssessed' : value === 'needs_support' ? 'needsSupport' : value}`,
  );
</script>
<template>
  <EducationState
    v-if="!items.length"
    :empty="t('education.noLessons')"
  />
  <ul
    v-else
    class="edu-list"
  >
    <li
      v-for="lesson in items"
      :key="lesson.id"
      class="edu-list__item"
    >
      <div>
        <strong>{{ lesson.title }}</strong>
        <p class="edu-subtle">{{ when(lesson.startsAt) }} · {{ lesson.groupName }}</p>
        <p
          v-if="lesson.location"
          class="edu-subtle"
        >
          {{ lesson.location }}
        </p>
        <p v-if="diary && lesson.topic">{{ lesson.topic }}</p>
        <p v-if="diary && lesson.summary">{{ lesson.summary }}</p>
        <p
          v-if="diary && lesson.resultComment"
          class="edu-message"
        >
          {{ lesson.resultComment }}
        </p>
        <div
          v-if="diary"
          class="edu-form-actions"
        >
          <span
            v-if="lesson.attendance"
            class="edu-status"
          >
            {{
              t(`education.${lesson.attendance === 'absent' ? 'absentStatus' : lesson.attendance}`)
            }}
          </span>
          <span
            v-if="lesson.resultStatus"
            class="edu-status"
          >
            {{ resultLabel(lesson.resultStatus) }}
          </span>
        </div>
      </div>
      <span
        class="edu-status"
        :class="`edu-status--${lesson.status}`"
      >
        {{ t(`education.${lesson.status}`) }}
      </span>
    </li>
  </ul>
</template>
