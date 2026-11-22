<script setup lang="ts">
import { useEducationI18n } from '../i18n';
import { isLearningAssignmentOpen } from './learningAssignments';
import { studentSession } from './session';
import type { StudentAssignment } from './api';

defineProps<{ items: readonly StudentAssignment[]; selectedId: string }>();
const { t, locale } = useEducationI18n();
function when(value: string): string {
  return new Intl.DateTimeFormat(locale.value, {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: studentSession.timezone,
  }).format(new Date(value));
}
</script>

<template>
  <section
    v-if="items.length || selectedId"
    class="edu-card"
  >
    <div class="edu-section-title">
      <h3>{{ t('education.activityAssignments') }}</h3>
      <RouterLink
        v-if="selectedId"
        :to="{ query: {} }"
        class="edu-link"
      >
        {{ t('education.practiceActivity') }}
      </RouterLink>
    </div>
    <ul
      v-if="items.length"
      class="edu-list"
    >
      <li
        v-for="assignment in items"
        :key="assignment.id"
        class="edu-list__item"
      >
        <div>
          <strong>{{ assignment.groupName ?? t('education.individualActivityAssignment') }}</strong>
          <p
            v-if="assignment.createdAt"
            class="edu-subtle"
          >
            {{ when(assignment.createdAt) }}
          </p>
        </div>
        <strong v-if="assignment.id === selectedId">
          {{ t('education.selectedActivityAssignment') }}
        </strong>
        <RouterLink
          v-else-if="isLearningAssignmentOpen(assignment)"
          :to="{ query: { assignment: assignment.id } }"
          class="edu-button"
        >
          {{ t('education.openActivity') }}
        </RouterLink>
        <span v-else>{{ t('education.learningAssignmentUnavailable') }}</span>
      </li>
    </ul>
  </section>
</template>
