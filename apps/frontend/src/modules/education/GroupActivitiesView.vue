<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { coreAdminSessionEpoch } from '@/core/admin/session';
import AppConfirmDialog from '@/shared/ui/overlays/AppConfirmDialog.vue';
import { educationApi, type GroupActivityAttempt, type Student } from './api';
import { activityApi } from './activityApi';
import { defaultActivityConfig, type ActivityConfig } from './activityTypes';
import { useEducationWorkspace } from './workspace';
import { useHomeworkMutation, useHomeworkResource } from './homework/resources';
import { useEducationError } from './resources';
import { useEducationI18n } from './i18n';
import EducationState from './EducationState.vue';
import ActivityConfigFields from './ActivityConfigFields.vue';
import ActivityProgressPanel from './ActivityProgressPanel.vue';
const route = useRoute();
const workspace = useEducationWorkspace();
const { t } = useEducationI18n();
const issue = useEducationError();
const id = computed(() => String(route.params.id ?? ''));
const offset = ref(0);
const activity = ref('platform-typing');
const config = ref<ActivityConfig>({ ...defaultActivityConfig(), maxAttempts: 5 });
const studentId = ref('');
const from = ref(new Date(Date.now() - 29 * 86400000).toISOString().slice(0, 10));
const to = ref(new Date().toISOString().slice(0, 10));
const period = ref({ from: from.value, to: to.value });
const assigned = ref(false);
const periodValid = computed(() => !!from.value && !!to.value && from.value <= to.value);
watch(activity, (code) => {
  config.value = { ...defaultActivityConfig(code), maxAttempts: 5 };
  offset.value = 0;
  assigned.value = false;
});
watch(
  [id, () => workspace.organizationId, coreAdminSessionEpoch],
  () => {
    offset.value = 0;
    studentId.value = '';
    assigned.value = false;
  },
  { flush: 'sync' },
);
const organization = () => workspace.organizationId;
async function roster(org: string, signal: AbortSignal): Promise<Student[]> {
  const students: Student[] = [];
  let pageOffset = 0;
  for (;;) {
    const pageData = await educationApi.groupStudents(org, id.value, pageOffset, signal);
    students.push(...pageData.items);
    if (pageData.nextOffset === null) break;
    if (pageData.nextOffset <= pageOffset) throw new Error('Invalid roster pagination');
    pageOffset = pageData.nextOffset;
  }
  return students;
}
const details = useHomeworkResource(
  organization,
  async (org, signal) => {
    const [group, students, assignments, catalog] = await Promise.all([
      educationApi.group(org, id.value, signal),
      roster(org, signal),
      educationApi.activityAssignments(org, 0, signal),
      activityApi.catalog(org, signal),
    ]);
    return {
      group,
      students,
      catalog: catalog.items.filter(
        (a) => a.enabled && ['platform-typing', 'platform-logic'].includes(a.code),
      ),
      assignments: assignments.items.filter(
        (a) => a.groupId === id.value || students.some((s) => s.id === a.studentId),
      ),
    };
  },
  [id],
);
const results = useHomeworkResource(
  organization,
  (org, signal) =>
    educationApi.groupActivityResults(
      org,
      id.value,
      activity.value,
      offset.value,
      signal,
      period.value,
    ),
  [id, activity, offset, period],
);
const analytics = useHomeworkResource(
  organization,
  (org, signal) =>
    activityApi.groupProgress(
      org,
      id.value,
      period.value.from,
      period.value.to,
      activity.value,
      signal,
    ),
  [id, activity, period],
);
const displayedProgress = computed(
  () =>
    analytics.data.value?.items.filter(
      (s) => !studentId.value || studentId.value === s.studentId,
    ) ?? [],
);
const displayedResults = computed(
  () =>
    results.data.value?.items.filter((s) => !studentId.value || studentId.value === s.studentId) ??
    [],
);
const mutation = useHomeworkMutation(
  organization,
  () => `${id.value}:${activity.value}:${studentId.value}`,
);
const { busy, error: actionError, uncertain } = mutation;
const revocation = useHomeworkMutation(
  organization,
  () => `${id.value}:${activity.value}:${period.value.from}:${period.value.to}`,
);
const pendingRevoke = ref<GroupActivityAttempt | null>(null);
const revokeOpen = ref(false);
const revokeSuccess = ref(false);
watch(
  [id, activity, period, organization, coreAdminSessionEpoch],
  () => {
    pendingRevoke.value = null;
    revokeOpen.value = false;
    revokeSuccess.value = false;
  },
  { flush: 'sync' },
);
function askRevoke(attempt: GroupActivityAttempt): void {
  pendingRevoke.value = attempt;
  revokeSuccess.value = false;
  revocation.error.value = null;
  revokeOpen.value = true;
}
async function revoke(): Promise<void> {
  const attempt = pendingRevoke.value;
  if (!attempt) return;
  await revocation.run(
    () => educationApi.revokeActivityAttempt(organization(), attempt.id),
    async () => {
      revokeOpen.value = false;
      pendingRevoke.value = null;
      revokeSuccess.value = true;
      await Promise.all([results.refresh(), analytics.refresh()]);
    },
  );
}
async function reconcileRevoke(): Promise<void> {
  await Promise.all([results.refresh(), analytics.refresh()]);
  if (!results.error.value) {
    revocation.uncertain.value = false;
    revocation.error.value = null;
    revokeOpen.value = false;
  }
}
async function assign(): Promise<void> {
  assigned.value = false;
  await mutation.run(
    () =>
      educationApi.assignActivity(workspace.organizationId, activity.value, {
        ...(studentId.value ? { studentId: studentId.value } : { groupId: id.value }),
        config: { ...config.value },
      }),
    async () => {
      assigned.value = true;
      await details.refresh();
    },
  );
}
function applyPeriod(): void {
  if (periodValid.value) {
    period.value = { from: from.value, to: to.value };
    offset.value = 0;
  }
}
</script>
<template>
  <section>
    <header class="edu-page-title">
      <h1>
        {{ details.data.value?.group.name ?? t('education.activities') }} ·
        {{ t('education.activities') }}
      </h1>
      <RouterLink
        :to="`/groups/${id}`"
        class="edu-link"
      >
        {{ t('education.group') }}
      </RouterLink>
    </header>
    <EducationState
      :loading="details.loading.value"
      :error="details.error.value"
      @retry="details.refresh"
    />
    <template v-if="details.data.value">
      <form
        class="edu-form edu-card"
        @submit.prevent="assign"
      >
        <h2>{{ t('education.assignActivity') }}</h2>
        <div class="edu-form-actions">
          <label>
            {{ t('education.activity') }}
            <select
              v-model="activity"
              class="edu-input"
              :disabled="busy"
            >
              <option
                v-for="definition in details.data.value.catalog"
                :key="definition.id"
                :value="definition.code"
              >
                {{ definition.name }}
              </option>
            </select>
          </label>
          <label>
            {{ t('education.homeworkRecipients') }}
            <select
              v-model="studentId"
              class="edu-input"
              :disabled="busy"
            >
              <option value="">{{ t('education.wholeGroup') }}</option>
              <option
                v-for="student in details.data.value.students"
                :key="student.id"
                :value="student.id"
              >
                {{ student.name ?? student.id }}
              </option>
            </select>
          </label>
        </div>
        <p class="edu-subtle">
          {{
            t(
              activity === 'platform-logic'
                ? 'education.logicDescription'
                : 'education.typingDescription',
            )
          }}
        </p>
        <ActivityConfigFields
          v-model="config"
          :activity-code="activity"
          assignment
          :disabled="busy"
        />
        <button
          type="submit"
          class="edu-button edu-button--primary"
          :disabled="busy || uncertain"
        >
          {{ t('education.assignActivity') }}
        </button>
        <p
          v-if="actionError"
          class="edu-error"
          role="alert"
        >
          {{ issue(actionError) }}
        </p>
        <p
          v-if="assigned"
          role="status"
        >
          {{ t('education.activityAssigned') }}
        </p>
        <p
          v-if="uncertain"
          class="edu-subtle"
        >
          {{ t('education.assignmentUncertain') }}
        </p>
      </form>
      <details class="edu-card">
        <summary>{{ t('education.activityAssignments') }}</summary>
        <ul class="edu-list">
          <li
            v-for="assignment in details.data.value.assignments"
            :key="assignment.id"
            class="edu-list__item"
          >
            <strong>{{ assignment.activityName }}</strong>
            <span>
              {{
                assignment.studentId
                  ? (details.data.value.students.find((s) => s.id === assignment.studentId)?.name ??
                    assignment.studentId)
                  : t('education.wholeGroup')
              }}
            </span>
            <span>
              {{
                assignment.completion
                  ? t('education.completedAssignments')
                  : t('education.attempt_running')
              }}
            </span>
          </li>
        </ul>
      </details>
      <form
        class="edu-form-actions edu-card"
        @submit.prevent="applyPeriod"
      >
        <label>
          {{ t('education.from') }}
          <input
            v-model="from"
            type="date"
            class="edu-input"
            required
          />
        </label>
        <label>
          {{ t('education.to') }}
          <input
            v-model="to"
            type="date"
            class="edu-input"
            required
          />
        </label>
        <button
          type="submit"
          class="edu-button"
          :disabled="!periodValid"
        >
          {{ t('education.apply') }}
        </button>
      </form>
      <h2>{{ t('education.activityAnalytics') }}</h2>
      <p class="edu-caption">{{ period.from }} — {{ period.to }}</p>
      <EducationState
        :loading="analytics.loading.value"
        :error="analytics.error.value"
        @retry="analytics.refresh"
      />
      <EducationState
        v-if="analytics.data.value && !displayedProgress.length"
        :empty="t('education.noTrainingResults')"
      />
      <details
        v-for="student in displayedProgress"
        :key="student.studentId"
        class="edu-card"
      >
        <summary>
          {{ student.name }} · Typing: {{ student.typing.attempts }} · Logic:
          {{ student.logic.attempts }} · {{ t('education.achievements') }}:
          {{ student.achievements.filter((a) => a.unlockedAt).length }}
        </summary>
        <p>
          <RouterLink
            :to="`/students/${student.studentId}`"
            class="edu-link"
          >
            {{ t('education.student') }}
          </RouterLink>
        </p>
        <ActivityProgressPanel
          :progress="student"
          show-achievements
        />
      </details>
      <h2>{{ t('education.trainingHistory') }}</h2>
      <p
        v-if="revokeSuccess"
        role="status"
      >
        {{ t('education.activityAttemptRevoked') }}
      </p>
      <EducationState
        v-if="!revokeOpen"
        :error="revocation.error.value"
        @retry="reconcileRevoke"
      />
      <EducationState
        :loading="results.loading.value"
        :error="results.error.value"
        @retry="results.refresh"
      />
      <EducationState
        v-if="results.data.value && !displayedResults.length"
        :empty="t('education.noTrainingResults')"
      />
      <ul
        v-else
        class="edu-list"
      >
        <li
          v-for="attempt in displayedResults"
          :key="attempt.id"
          class="edu-list__item"
        >
          <RouterLink
            :to="`/students/${attempt.studentId}`"
            class="edu-list__main"
          >
            <strong>{{ attempt.name }}</strong>
            <p class="edu-subtle">{{ new Date(attempt.startedAt).toLocaleString() }}</p>
          </RouterLink>
          <span v-if="attempt.wpm !== null && attempt.wpm !== undefined">
            {{ attempt.wpm }} WPM · {{ attempt.accuracy }}%
          </span>
          <span v-else-if="attempt.score !== null && attempt.score !== undefined">
            {{ attempt.correctAnswers }} / {{ attempt.totalRounds }} · {{ attempt.accuracy }}% ·
            {{ attempt.score }}
          </span>
          <span v-else>{{ t(`education.attempt_${attempt.status}`) }}</span>
          <button
            type="button"
            class="edu-button"
            :disabled="revocation.busy.value || revocation.uncertain.value"
            @click="askRevoke(attempt)"
          >
            {{ t('education.revokeActivityAttempt') }}
          </button>
        </li>
      </ul>
      <div class="edu-form-actions">
        <button
          v-if="offset"
          type="button"
          class="edu-button"
          @click="offset = Math.max(0, offset - 50)"
        >
          {{ t('education.back') }}
        </button>
        <button
          v-if="results.data.value?.nextOffset !== null && results.data.value"
          type="button"
          class="edu-button"
          @click="offset = results.data.value.nextOffset ?? 0"
        >
          {{ t('education.loadMore') }}
        </button>
      </div>
    </template>
    <AppConfirmDialog
      v-model="revokeOpen"
      :title="t('education.revokeActivityAttempt')"
      :description="
        t('education.revokeActivityAttemptConfirm', { name: pendingRevoke?.name ?? '' })
      "
      :confirm-label="t('education.confirm')"
      :cancel-label="t('education.cancel')"
      :busy="revocation.busy.value"
      danger
      @confirm="revocation.uncertain.value ? reconcileRevoke() : revoke()"
    >
      <p
        v-if="revocation.error.value"
        class="edu-error"
        role="alert"
      >
        {{ revocation.uncertain.value ? t('education.uncertain') : issue(revocation.error.value) }}
      </p>
    </AppConfirmDialog>
  </section>
</template>
