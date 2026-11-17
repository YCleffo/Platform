<script setup lang="ts">
import { computed } from 'vue';
import { useEducationWorkspace } from '../workspace';
import { studentSession } from '../student/session';
import { useEducationI18n } from '../i18n';
import EducationState from '../EducationState.vue';
import { homeworkApi, studentAssignment, studentState } from './api';
import { useHomeworkResource } from './resources';
const props = defineProps<{
  student?: boolean;
  studentId?: string;
  groupId?: string;
  compact?: boolean;
}>();
const workspace = useEducationWorkspace();
const { t, locale } = useEducationI18n();
const org = () => (props.student ? studentSession.organizationId : workspace.organizationId);
const filters = computed(() => ({
  ...(props.studentId ? { studentId: props.studentId } : {}),
  ...(props.groupId ? { groupId: props.groupId } : {}),
}));
const { data, loading, error, refresh } = useHomeworkResource(
  org,
  async (organization, signal) => {
    const [progress, tasks, recent] = await Promise.all([
      homeworkApi.progress(organization, filters.value, props.student, signal),
      props.student
        ? homeworkApi.studentList(organization, { sort: 'dueAt', offset: 0 }, signal)
        : homeworkApi.list(organization, { ...filters.value, sort: 'dueAt', offset: 0 }, signal),
      props.student
        ? homeworkApi.studentList(
            organization,
            { state: 'REVIEWED', sort: 'reviewedAt', offset: 0 },
            signal,
          )
        : null,
    ]);
    return { progress, tasks, recent };
  },
  [filters, () => props.student],
);
const link = computed(() => ({
  path: props.student ? '/student/homework' : '/homework',
  query: props.student ? {} : filters.value,
}));
function date(value: string): string {
  return new Intl.DateTimeFormat(locale.value, {
    dateStyle: 'medium',
    timeZone: workspace.header?.timezone ?? 'UTC',
  }).format(new Date(value));
}
</script>
<template>
  <section class="edu-card edu-homework-summary">
    <header class="edu-section-title">
      <h2>{{ t('education.homeworkProgress') }}</h2>
      <RouterLink
        :to="link"
        class="edu-link"
      >
        {{ t('education.homeworkAll') }}
      </RouterLink>
    </header>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <template v-if="data">
      <dl class="edu-homework-counts">
        <div
          v-for="key in ['assigned', 'completed', 'pendingReview', 'returned', 'overdue'] as const"
          :key="key"
        >
          <dt>{{ t(`education.homework${key[0]!.toUpperCase()}${key.slice(1)}`) }}</dt>
          <dd>{{ data.progress[key] }}</dd>
        </div>
      </dl>
      <nav
        v-if="student"
        class="edu-form-actions"
      >
        <RouterLink
          v-for="state in ['NOT_STARTED', 'IN_PROGRESS', 'SUBMITTED', 'RETURNED'] as const"
          :key="state"
          :to="{ path: '/student/homework', query: { state } }"
          class="edu-link"
        >
          {{ t(`education.homework${state}`) }}
        </RouterLink>
      </nav>
      <ul
        v-if="!compact"
        class="edu-list"
      >
        <li
          v-for="row in data.tasks.items.slice(0, 5)"
          :key="studentAssignment(row).id"
          class="edu-list__item"
        >
          <RouterLink
            :to="`${student ? '/student' : ''}/homework/${studentAssignment(row).id}`"
            class="edu-link"
          >
            {{ studentAssignment(row).title }}
          </RouterLink>
          <span>
            {{
              studentAssignment(row).dueAt
                ? date(studentAssignment(row).dueAt!)
                : t('education.homeworkNoDue')
            }}
            ·
            {{
              t(`education.homework${student ? studentState(row) : studentAssignment(row).status}`)
            }}
          </span>
        </li>
      </ul>
      <p
        v-if="!data.tasks.items.length"
        class="edu-subtle"
      >
        {{ t('education.noHomework') }}
      </p>
      <template v-if="student && data.recent?.items.length && !compact">
        <h3>{{ t('education.homeworkRecentResults') }}</h3>
        <ul class="edu-list">
          <li
            v-for="row in data.recent.items.slice(0, 3)"
            :key="studentAssignment(row).id"
            class="edu-list__item"
          >
            <RouterLink
              :to="`/student/homework/${studentAssignment(row).id}`"
              class="edu-link"
            >
              {{ studentAssignment(row).title }}
            </RouterLink>
            <span>
              {{
                studentAssignment(row).resultStatus
                  ? t(
                      `education.${studentAssignment(row).resultStatus === 'needs_support' ? 'needsSupport' : studentAssignment(row).resultStatus}`,
                    )
                  : t('education.homeworkAccepted')
              }}
            </span>
          </li>
        </ul>
      </template>
    </template>
  </section>
</template>
