<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { activityApi, type ActivityItem } from '@/modules/activity/activityApi';
import ActivityRunnerView from '@/modules/activity/ActivityRunnerView.vue';
import { invalidateEducationCache } from '../cache';
import { homeworkApi } from '../homework/api';
import { useHomeworkResource } from '../homework/resources';
import { useEducationI18n } from '../i18n';
import EducationState from '../EducationState.vue';
import StudentLearningAssignments from './StudentLearningAssignments.vue';
import { useLearningAssignments } from './learningAssignments';
import { studentPortalApi } from './api';
import { studentSession } from './session';

const { t } = useEducationI18n();
const route = useRoute();
const router = useRouter();
const org = computed(() => studentSession.organizationId);

const homeworkId = computed(() =>
  typeof route.query.homework === 'string' ? route.query.homework : '',
);
const learning = useLearningAssignments('platform-logic', () => homeworkId.value);
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
    (a) =>
      a.activityCode === 'platform-logic' &&
      a.status === 'running' &&
      (a.assignmentId ?? '') === (learning.selected.value?.id ?? ''),
  ),
);

const logicItem = ref<ActivityItem | null>(null);
const bootstrapLoading = ref(false);
const bootstrapError = ref<unknown>(null);
const practiceLanguage = ref<'ru' | 'en'>('ru');
const practiceDifficulty = ref<number>(1);
const practiceRounds = ref<number>(5);
const restartNonce = ref(0);

const resolvedAssignmentId = computed(() =>
  homeworkId.value
    ? (assigned.data.value?.recipient.activityAssignmentId ?? undefined)
    : (learning.selected.value?.id ?? undefined),
);

const openPracticeConfig = computed<Record<string, unknown> | undefined>(() =>
  resolvedAssignmentId.value || attemptId.value
    ? undefined
    : {
        language: practiceLanguage.value,
        difficulty: practiceDifficulty.value,
        rounds: practiceRounds.value,
      },
);

const canMountRunner = computed(
  () =>
    Boolean(org.value && logicItem.value) &&
    (homeworkId.value
      ? Boolean(canRunAssigned.value && resolvedAssignmentId.value)
      : Boolean(learning.allowed.value)),
);

async function bootstrapLogic(): Promise<void> {
  if (!org.value) {
    logicItem.value = null;
    return;
  }
  bootstrapLoading.value = true;
  bootstrapError.value = null;
  try {
    logicItem.value = await activityApi.bootstrapPlugin(org.value, 'platform-logic');
  } catch (err) {
    bootstrapError.value = err;
  } finally {
    bootstrapLoading.value = false;
  }
}

watch(org, () => {
  void bootstrapLogic();
});

onMounted(() => {
  void bootstrapLogic();
});

function started(id: string): void {
  if (route.query.attempt !== id) {
    void router.replace({ query: { ...route.query, attempt: id } });
  }
}

function restartPractice(): void {
  restartNonce.value += 1;
  if (route.query.attempt) {
    const nextQuery = { ...route.query };
    delete nextQuery.attempt;
    void router.replace({ query: nextQuery });
  }
}

async function onFinished(result: { attemptId: string }): Promise<void> {
  if (homeworkId.value && org.value && result.attemptId) {
    try {
      await homeworkApi.attachActivity(org.value, homeworkId.value, result.attemptId);
    } catch {
      // Attachment may already have been created server-side by ActivityResultListener
    }
    invalidateEducationCache([
      'homework',
      'student',
      'students',
      'groups',
      'lessons',
      'messages',
      'header',
    ]);
    await assigned.refresh();
  }
  await pending.refresh();
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
    <template v-else>
      <StudentLearningAssignments
        :items="learning.items.value"
        :selected-id="learning.id.value"
      />
      <EducationState
        :loading="learning.loading.value"
        :error="learning.error.value"
        @retry="learning.refresh"
      />
      <p
        v-if="!learning.allowed.value && !learning.loading.value && !learning.error.value"
        class="edu-subtle"
      >
        {{ t('education.learningAssignmentUnavailable') }}
      </p>
      <div
        v-if="!attemptId && learning.allowed.value && !learning.selected.value"
        class="edu-card edu-logic-practice-bar"
      >
        <label class="edu-field">
          <span>Language</span>
          <select v-model="practiceLanguage">
            <option value="ru">RU</option>
            <option value="en">EN</option>
          </select>
        </label>
        <label class="edu-field">
          <span>Difficulty</span>
          <select v-model.number="practiceDifficulty">
            <option :value="1">1</option>
            <option :value="2">2</option>
            <option :value="3">3</option>
          </select>
        </label>
        <label class="edu-field">
          <span>Rounds</span>
          <select v-model.number="practiceRounds">
            <option :value="5">5</option>
            <option :value="8">8</option>
            <option :value="10">10</option>
          </select>
        </label>
        <button
          type="button"
          class="edu-button"
          @click="restartPractice"
        >
          {{ t('education.newAttempt') ?? 'New attempt' }}
        </button>
      </div>
    </template>

    <div
      v-if="!attemptId && !homeworkId && learning.allowed.value && resumable.length"
      class="edu-card"
    >
      <h3>{{ t('education.unfinishedAttempts') }}</h3>
      <RouterLink
        v-for="attempt in resumable"
        :key="attempt.id"
        :to="{ query: { ...route.query, attempt: attempt.id } }"
        class="edu-button"
      >
        {{ t('education.restoreAttempt') }} · {{ new Date(attempt.startedAt).toLocaleString() }}
      </RouterLink>
    </div>

    <EducationState
      :loading="bootstrapLoading"
      :error="bootstrapError"
      @retry="bootstrapLogic"
    />

    <ActivityRunnerView
      v-if="canMountRunner && logicItem"
      :key="`${org}:${homeworkId}:${learning.id.value}:${resolvedAssignmentId ?? 'open'}:${restartNonce}`"
      :organization-id="org"
      :activity-id="logicItem.id"
      :assignment-id="resolvedAssignmentId"
      :attempt-id="attemptId || undefined"
      :initial-config="openPracticeConfig"
      embedded
      @started="started"
      @finished="onFinished"
    />

    <p
      v-if="homeworkId && assigned.data.value && !canRunAssigned"
      class="edu-subtle"
    >
      {{ t('education.homeworkLocked') }}
    </p>
  </section>
</template>

<style scoped>
.edu-logic-practice-bar {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: flex-end;
  margin-bottom: 12px;
}
.edu-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
</style>
