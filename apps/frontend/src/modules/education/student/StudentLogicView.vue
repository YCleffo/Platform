<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { studentSession } from './session';
import { studentPortalApi } from './api';
import { homeworkApi } from '../homework/api';
import { useHomeworkResource } from '../homework/resources';
import { useEducationI18n } from '../i18n';
import EducationState from '../EducationState.vue';
import LogicTrainer from './LogicTrainer.vue';
const { t } = useEducationI18n();
const route = useRoute(),
  router = useRouter();
const org = computed(() => studentSession.organizationId);
const homeworkId = computed(() =>
  typeof route.query.homework === 'string' ? route.query.homework : '',
);
const attemptId = computed(() =>
  typeof route.query.attempt === 'string' ? route.query.attempt : '',
);
const assigned = useHomeworkResource(
  () => org.value,
  (organization, signal) =>
    homeworkId.value
      ? homeworkApi.studentDetail(organization, homeworkId.value, signal)
      : Promise.resolve(null),
  [homeworkId],
);
const canRunAssigned = computed(
  () =>
    assigned.data.value?.assignment.status === 'PUBLISHED' &&
    assigned.data.value.assignment.activityCode === 'platform-logic' &&
    ['NOT_STARTED', 'IN_PROGRESS', 'RETURNED'].includes(assigned.data.value.submission.state),
);
const pending = useHomeworkResource(() => org.value, studentPortalApi.home);
const resumable = computed(() =>
  (pending.data.value?.pendingAttempts ?? []).filter(
    (a) => a.activityCode === 'platform-logic' && a.status === 'running',
  ),
);
function started(id: string): void {
  void router.replace({ query: { ...route.query, attempt: id } });
}
</script>
<template>
  <section>
    <header class="edu-page-title">
      <h2>Platform Logic</h2>
      <RouterLink
        :to="homeworkId ? `/student/homework/${homeworkId}` : '/student/activities'"
        class="edu-link"
      >
        {{ t('education.back') }}
      </RouterLink>
    </header>
    <template v-if="homeworkId">
      <EducationState
        :loading="assigned.loading.value"
        :error="assigned.error.value"
        @retry="assigned.refresh"
      />
      <p v-if="assigned.data.value">
        {{ assigned.data.value.assignment.title }} · {{ t('education.homeworkMaxAttempts') }}:
        {{ assigned.data.value.assignment.maxAttempts }}
      </p>
    </template>
    <div
      v-if="!attemptId && !homeworkId && resumable.length"
      class="edu-card"
    >
      <h3>{{ t('education.unfinishedAttempts') }}</h3>
      <RouterLink
        v-for="attempt in resumable"
        :key="attempt.id"
        :to="{ query: { attempt: attempt.id } }"
        class="edu-button"
      >
        {{ t('education.restoreAttempt') }} · {{ new Date(attempt.startedAt).toLocaleString() }}
      </RouterLink>
    </div>
    <LogicTrainer
      v-if="org && (!homeworkId || canRunAssigned)"
      :key="`${org}:${homeworkId}`"
      :organization-id="org"
      :attempt-id="attemptId || undefined"
      :homework-id="homeworkId || undefined"
      :assigned-config="assigned.data.value?.assignment.activityConfig"
      @started="started"
      @finished="pending.refresh"
    />
    <p
      v-if="homeworkId && assigned.data.value && !canRunAssigned"
      class="edu-subtle"
    >
      {{ t('education.homeworkLocked') }}
    </p>
  </section>
</template>
