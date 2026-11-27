<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { activityApi, type ActivityAttempt, type ActivityItem } from '@/modules/activity/activityApi';
import TypingArenaPlugin from '@/modules/activity/plugins/TypingArenaPlugin.vue';
import { defaultTypingArenaConfig, type TypingArenaConfig } from '@/modules/activity/typing/typingEngine';
import { invalidateEducationCache } from '../cache';
import { homeworkApi } from '../homework/api';
import { useHomeworkResource } from '../homework/resources';
import { useEducationI18n } from '../i18n';
import EducationState from '../EducationState.vue';
import StudentLearningAssignments from './StudentLearningAssignments.vue';
import { useLearningAssignments } from './learningAssignments';
import { studentSession } from './session';

const { t } = useEducationI18n();
const org = computed(() => studentSession.organizationId);
const route = useRoute();

const homeworkId = computed(() =>
  typeof route.query.homework === 'string' ? route.query.homework : '',
);
const learning = useLearningAssignments('platform-typing', () => homeworkId.value);
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
    assigned.data.value &&
    assigned.data.value.assignment.status === 'PUBLISHED' &&
    ['typing', 'activity'].includes(assigned.data.value.assignment.type) &&
    ['NOT_STARTED', 'IN_PROGRESS', 'RETURNED'].includes(assigned.data.value.submission.state),
);

const typingItem = ref<ActivityItem | null>(null);
const attempt = ref<ActivityAttempt | null>(null);
const history = ref<Array<Record<string, unknown>>>([]);
const arenaLoading = ref(false);
const arenaError = ref<unknown>(null);

const isAssignedSession = computed(
  () => Boolean(homeworkId.value || learning.id.value),
);

const isReadonly = computed(
  () => attempt.value !== null && !['CREATED', 'IN_PROGRESS'].includes(attempt.value.state),
);

async function loadHistory(): Promise<void> {
  if (!org.value) return;
  try {
    const res = await activityApi.analytics(org.value, { pluginCode: 'platform-typing' });
    history.value = res.recentAttempts.filter((r) =>
      ['EVALUATED', 'COMPLETED'].includes(String(r.state ?? '')),
    );
  } catch {
    // ignore non-fatal history load error
  }
}

async function startSession(configOverride?: TypingArenaConfig): Promise<void> {
  if (!org.value) return;
  if (homeworkId.value && !canRunAssigned.value) return;
  if (!homeworkId.value && !learning.allowed.value) return;

  arenaLoading.value = true;
  arenaError.value = null;
  try {
    if (!typingItem.value) {
      typingItem.value = await activityApi.bootstrapTyping(org.value);
    }

    const assignmentId = homeworkId.value
      ? (assigned.data.value?.recipient.activityAssignmentId ?? undefined)
      : (learning.selected.value?.id ?? undefined);

    const cfg = assignmentId
      ? undefined
      : (configOverride ??
          defaultTypingArenaConfig(
            (assigned.data.value?.assignment.activityConfig as Record<string, unknown> | undefined) ??
              (learning.selected.value?.effectiveConfig as Record<string, unknown> | undefined),
          ));

    attempt.value = await activityApi.startAttempt(org.value, typingItem.value.id, {
      assignmentId,
      resumeActive: Boolean(assignmentId),
      ...(cfg ? { config: cfg as unknown as Record<string, unknown> } : {}),
    });
  } catch (err) {
    arenaError.value = err;
  } finally {
    arenaLoading.value = false;
  }
}

async function onSubmit(
  submission: Record<string, unknown>,
  telemetry: Record<string, unknown>,
): Promise<void> {
  if (!org.value || !attempt.value || isReadonly.value) return;
  arenaError.value = null;
  try {
    const evaluated = await activityApi.submitAttempt(org.value, attempt.value.id, {
      submission,
      telemetry,
      revision: attempt.value.revision,
    });
    attempt.value = evaluated;

    if (homeworkId.value) {
      await homeworkApi.attachActivity(org.value, homeworkId.value, evaluated.id);
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
    await loadHistory();
  } catch (err) {
    arenaError.value = err;
  }
}

async function onRestart(nextConfig: TypingArenaConfig): Promise<void> {
  if (isAssignedSession.value) return;
  await startSession(nextConfig);
}

watch(
  [org, homeworkId, () => learning.id.value, canRunAssigned, () => learning.allowed.value],
  () => {
    if (!org.value) return;
    void loadHistory();
    if (homeworkId.value ? canRunAssigned.value : learning.allowed.value) {
      void startSession();
    } else {
      attempt.value = null;
    }
  },
  { immediate: true },
);

onMounted(() => {
  if (org.value) {
    void loadHistory();
  }
});
</script>

<template>
  <section class="edu-typing-page">
    <header class="edu-page-title">
      <h2>Platform Typing</h2>
      <RouterLink
        to="/student/activities"
        class="edu-link"
      >
        {{ t('education.activities') }}
      </RouterLink>
    </header>

    <template v-if="homeworkId">
      <RouterLink
        :to="`/student/homework/${homeworkId}`"
        class="edu-link"
      >
        {{ t('education.homeworkBack') }}
      </RouterLink>
      <p>{{ t('education.homeworkAssignedTyping') }}</p>
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
    </template>

    <EducationState
      :loading="arenaLoading"
      :error="arenaError"
      @retry="startSession()"
    />

    <p
      v-if="homeworkId && assigned.data.value && !canRunAssigned"
      class="edu-subtle"
    >
      {{ t('education.homeworkLocked') }}
    </p>

    <TypingArenaPlugin
      v-if="attempt"
      :exercise="attempt.exercise"
      :initial-answers="attempt.answers"
      :readonly="isReadonly"
      :config="attempt.config"
      :attempt="attempt"
      :assigned="isAssignedSession"
      :history="history"
      @submit="onSubmit"
      @restart="onRestart"
    />
  </section>
</template>

<style scoped>
.edu-typing-page {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
</style>
