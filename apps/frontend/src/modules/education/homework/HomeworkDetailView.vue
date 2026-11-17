<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppConfirmDialog from '@/shared/ui/overlays/AppConfirmDialog.vue';
import { educationApi } from '../api';
import { useEducationWorkspace } from '../workspace';
import { useEducationI18n } from '../i18n';
import { useEducationError } from '../resources';
import EducationState from '../EducationState.vue';
import HomeworkForm from './HomeworkForm.vue';
import HomeworkFiles from './HomeworkFiles.vue';
import { homeworkApi } from './api';
import { useHomeworkMutation, useHomeworkResource } from './resources';
const workspace = useEducationWorkspace(),
  route = useRoute(),
  { t, locale } = useEducationI18n(),
  issue = useEducationError();
const id = computed(() => String(route.params.id ?? ''));
const offset = ref(0),
  historyOffset = ref(0),
  studentOffset = ref(0),
  editing = ref(false);
const selected = ref<string[]>([]),
  confirm = ref(false),
  action = ref<'publish' | 'close' | 'archive'>('publish');
const { data, loading, error, refresh } = useHomeworkResource(
  () => workspace.organizationId,
  async (org, signal) => {
    const [detail, submissions, history] = await Promise.all([
      homeworkApi.detail(org, id.value, signal),
      homeworkApi.submissions(org, id.value, offset.value, signal),
      homeworkApi.history(org, id.value, historyOffset.value, signal),
    ]);
    return { ...detail, submissions, history };
  },
  [id, offset, historyOffset],
);
const students = useHomeworkResource(
  () => workspace.organizationId,
  (org, signal) => educationApi.students(org, studentOffset.value, signal),
  [studentOffset, id],
);
const {
  busy,
  error: actionError,
  uncertain,
  run,
} = useHomeworkMutation(
  () => workspace.organizationId,
  () => id.value,
);
watch(id, () => {
  offset.value = 0;
  historyOffset.value = 0;
  editing.value = false;
  selected.value = [];
  confirm.value = false;
});
function date(value: string): string {
  return new Intl.DateTimeFormat(locale.value, {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: workspace.header?.timezone ?? 'UTC',
  }).format(new Date(value));
}
function propose(value: 'publish' | 'close' | 'archive'): void {
  action.value = value;
  confirm.value = true;
}
async function lifecycle(): Promise<void> {
  if (!data.value) return;
  const version = data.value.assignment.version;
  await run(
    () => homeworkApi.lifecycle(workspace.organizationId, id.value, action.value, version),
    async () => {
      confirm.value = false;
      await refresh();
      void workspace.refreshHeader();
    },
  );
}
async function addRecipients(): Promise<void> {
  if (!data.value || !selected.value.length) return;
  await run(
    () =>
      homeworkApi.recipients(
        workspace.organizationId,
        id.value,
        data.value!.assignment.version,
        selected.value,
      ),
    async () => {
      selected.value = [];
      await refresh();
    },
  );
}
async function reconcile(): Promise<void> {
  await refresh();
  if (!error.value && data.value) {
    uncertain.value = false;
    actionError.value = null;
  }
}
</script>
<template>
  <section>
    <header class="edu-page-title">
      <h1>{{ data?.assignment.title ?? t('education.homework') }}</h1>
      <RouterLink
        to="/homework"
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
    <p
      v-if="actionError"
      class="edu-error"
      role="alert"
    >
      {{ issue(actionError) }}
    </p>
    <p
      v-if="uncertain"
      role="alert"
    >
      {{ t('education.homeworkUncertain') }}
      <AppButton
        variant="secondary"
        @click="reconcile"
      >
        {{ t('education.homeworkReload') }}
      </AppButton>
    </p>
    <template v-if="data">
      <HomeworkForm
        v-if="editing"
        :assignment="data.assignment"
        @saved="
          editing = false;
          refresh();
        "
        @cancel="editing = false"
      />
      <template v-else>
        <article class="edu-card">
          <div class="edu-form-actions">
            <span class="edu-status">{{ t(`education.homework${data.assignment.status}`) }}</span>
            <span>{{ t('education.homeworkVersion') }} {{ data.assignment.version }}</span>
          </div>
          <p class="edu-homework-prose">{{ data.assignment.description }}</p>
          <h2>{{ t('education.homeworkInstructions') }}</h2>
          <p class="edu-homework-prose">{{ data.assignment.instructions }}</p>
          <p>
            {{ t('education.homeworkDue') }}:
            {{ data.assignment.dueAt ? date(data.assignment.dueAt) : t('education.homeworkNoDue') }}
            <span v-if="data.assignment.allowLate">· {{ t('education.homeworkLateAllowed') }}</span>
          </p>
          <p v-if="data.assignment.type === 'typing' || data.assignment.type === 'activity'">
            Platform Typing · {{ data.assignment.activityConfig.durationSeconds }}
            {{ t('education.seconds') }} ·
            {{ data.assignment.activityConfig.language.toUpperCase() }} ·
            {{ t('education.homeworkMaxAttempts') }}: {{ data.assignment.maxAttempts }} ·
            {{
              t(
                `education.homework${data.assignment.attemptPolicy[0]!.toUpperCase()}${data.assignment.attemptPolicy.slice(1)}`,
              )
            }}
          </p>
          <RouterLink
            v-if="data.assignment.lessonId"
            :to="`/lessons/${data.assignment.lessonId}`"
            class="edu-link"
          >
            {{ t('education.lesson') }}
          </RouterLink>
          <div class="edu-form-actions">
            <AppButton
              v-if="['DRAFT', 'PUBLISHED'].includes(data.assignment.status)"
              variant="secondary"
              :disabled="busy || uncertain"
              @click="editing = true"
            >
              {{ t('education.edit') }}
            </AppButton>
            <AppButton
              v-if="data.assignment.status === 'DRAFT'"
              :disabled="busy || uncertain"
              @click="propose('publish')"
            >
              {{ t('education.homeworkPublish') }}
            </AppButton>
            <AppButton
              v-if="data.assignment.status === 'PUBLISHED'"
              variant="secondary"
              :disabled="busy || uncertain"
              @click="propose('close')"
            >
              {{ t('education.homeworkClose') }}
            </AppButton>
            <AppButton
              v-if="data.assignment.status === 'CLOSED'"
              variant="secondary"
              :disabled="busy || uncertain"
              @click="propose('archive')"
            >
              {{ t('education.homeworkArchive') }}
            </AppButton>
          </div>
          <HomeworkFiles
            :organization-id="workspace.organizationId"
            :assignment-id="id"
            :files="data.assignment.files ?? []"
            :editable="data.assignment.status === 'DRAFT'"
            :title="t('education.homeworkMaterials')"
            @changed="refresh"
          />
        </article>
        <section class="edu-card">
          <h2>{{ t('education.homeworkRecipients') }}</h2>
          <div
            v-if="data.assignment.status === 'PUBLISHED' && students.data.value"
            class="edu-form"
          >
            <AppDropdown
              v-model="selected"
              multiple
              searchable
              :label="t('education.students')"
              :options="
                students.data.value.items.map((student) => ({
                  value: student.id,
                  label: student.name ?? student.id,
                }))
              "
            />
            <div class="edu-form-actions">
              <AppButton
                variant="secondary"
                :disabled="!selected.length || busy || uncertain"
                @click="addRecipients"
              >
                {{ t('education.homeworkAddRecipients') }}
              </AppButton>
              <AppButton
                v-if="studentOffset"
                variant="secondary"
                @click="studentOffset = Math.max(0, studentOffset - 50)"
              >
                {{ t('education.back') }}
              </AppButton>
              <AppButton
                v-if="students.data.value.nextOffset !== null"
                variant="secondary"
                @click="studentOffset = students.data.value!.nextOffset!"
              >
                {{ t('education.loadMore') }}
              </AppButton>
            </div>
          </div>
          <EducationState
            v-if="students.error.value"
            :error="students.error.value"
            @retry="students.refresh"
          />
          <div class="edu-homework-table-wrap">
            <table class="edu-homework-table">
              <thead>
                <tr>
                  <th>{{ t('education.student') }}</th>
                  <th>{{ t('education.homeworkState') }}</th>
                  <th>{{ t('education.homeworkSubmittedAt') }}</th>
                  <th>{{ t('education.results') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="row in data.submissions.items"
                  :key="row.recipientId"
                >
                  <td>
                    <RouterLink
                      v-if="row.submissionId"
                      :to="`/homework/submissions/${row.submissionId}`"
                      class="edu-link"
                    >
                      {{ row.name }}
                    </RouterLink>
                    <RouterLink
                      v-else
                      :to="`/students/${row.studentId}`"
                      class="edu-link"
                    >
                      {{ row.name }}
                    </RouterLink>
                  </td>
                  <td>{{ t(`education.homework${row.state}`) }}</td>
                  <td>
                    {{ row.submittedAt ? date(row.submittedAt) : '—' }}
                    <strong
                      v-if="row.late"
                      class="edu-error"
                    >
                      · {{ t('education.homeworkLate') }}
                    </strong>
                  </td>
                  <td>
                    {{
                      row.resultStatus
                        ? t(
                            `education.${row.resultStatus === 'needs_support' ? 'needsSupport' : row.resultStatus}`,
                          )
                        : '—'
                    }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p
            v-if="!data.submissions.items.length"
            class="edu-subtle"
          >
            {{ t('education.noHomework') }}
          </p>
          <div class="edu-form-actions">
            <AppButton
              v-if="offset"
              variant="secondary"
              @click="offset = Math.max(0, offset - 50)"
            >
              {{ t('education.back') }}
            </AppButton>
            <AppButton
              v-if="data.submissions.nextOffset !== null"
              variant="secondary"
              @click="offset = data!.submissions.nextOffset!"
            >
              {{ t('education.loadMore') }}
            </AppButton>
          </div>
        </section>
        <section class="edu-card">
          <h2>{{ t('education.homeworkHistory') }}</h2>
          <ul class="edu-list">
            <li
              v-for="revision in data.history.items"
              :key="revision.version"
              class="edu-list__item"
            >
              <span>
                {{ t('education.homeworkVersion') }} {{ revision.version }} ·
                {{ t(`education.homeworkEvent${revision.action}`) }}
              </span>
              <time>{{ date(revision.createdAt) }}</time>
            </li>
          </ul>
          <div class="edu-form-actions">
            <AppButton
              v-if="historyOffset"
              variant="secondary"
              @click="historyOffset = Math.max(0, historyOffset - 50)"
            >
              {{ t('education.back') }}
            </AppButton>
            <AppButton
              v-if="data.history.nextOffset !== null"
              variant="secondary"
              @click="historyOffset = data!.history.nextOffset!"
            >
              {{ t('education.loadMore') }}
            </AppButton>
          </div>
        </section>
      </template>
    </template>
    <AppConfirmDialog
      v-model="confirm"
      :title="t(`education.homework${action[0]!.toUpperCase()}${action.slice(1)}`)"
      :description="
        t(action === 'publish' ? 'education.homeworkPublishHint' : 'education.homeworkCloseHint')
      "
      :confirm-label="t(`education.homework${action[0]!.toUpperCase()}${action.slice(1)}`)"
      :busy="busy"
      @confirm="lifecycle"
    />
  </section>
</template>
