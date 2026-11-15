<script setup lang="ts">
import type { ParentHomework } from './api';
import { useEducationI18n } from '../i18n';
import EducationState from '../EducationState.vue';
const props = defineProps<{ items: ParentHomework[]; timezone: string; compact?: boolean }>();
const { t, locale } = useEducationI18n();
const when = (value: string) =>
  new Intl.DateTimeFormat(locale.value, {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: props.timezone,
  }).format(new Date(value));
</script>
<template>
  <EducationState
    v-if="!items.length"
    :empty="t('education.noHomework')"
  />
  <ul
    v-else
    class="edu-list"
  >
    <li
      v-for="assignment in items"
      :key="assignment.id"
      class="edu-list__item"
    >
      <div>
        <strong>{{ assignment.title }}</strong>
        <p class="edu-subtle">
          {{ t('education.homeworkDue') }}:
          {{ assignment.dueAt ? when(assignment.dueAt) : t('education.homeworkNoDue') }}
        </p>
        <p
          v-if="!compact"
          class="edu-message"
        >
          {{ assignment.description }}
        </p>
        <p
          v-if="!compact"
          class="edu-message"
        >
          {{ assignment.instructions }}
        </p>
        <p
          v-if="assignment.submittedAt"
          class="edu-subtle"
        >
          {{ t('education.homeworkSubmitted') }}: {{ when(assignment.submittedAt) }}
        </p>
        <template v-if="assignment.review">
          <p>
            {{ t(`education.homework${assignment.review.decision.toUpperCase()}`) }}
            <span v-if="assignment.review.resultStatus">
              ·
              {{
                t(
                  `education.${assignment.review.resultStatus === 'needs_support' ? 'needsSupport' : assignment.review.resultStatus}`,
                )
              }}
            </span>
          </p>
          <p
            v-if="assignment.review.comment"
            class="edu-message"
          >
            {{ assignment.review.comment }}
          </p>
        </template>
      </div>
      <span class="edu-status">{{ t(`education.homework${assignment.submissionState}`) }}</span>
    </li>
  </ul>
</template>
