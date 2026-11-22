<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppConfirmDialog from '@/shared/ui/overlays/AppConfirmDialog.vue';
import { useEducationI18n } from '../i18n';
import { useEducationError } from '../resources';
import { studentSession } from '../student/session';
import EducationState from '../EducationState.vue';
import HomeworkFiles from './HomeworkFiles.vue';
import HomeworkAnswerHistory from './HomeworkAnswerHistory.vue';
import { homeworkApi } from './api';
import { useHomeworkMutation, useHomeworkResource } from './resources';
const route = useRoute(),
  { t, locale } = useEducationI18n(),
  issue = useEducationError();
const id = computed(() => String(route.params.id ?? '')),
  org = () => studentSession.organizationId;
const { data, loading, error, refresh } = useHomeworkResource(
  org,
  (organization, signal) => homeworkApi.studentDetail(organization, id.value, signal),
  [id],
);
const { busy, error: actionError, uncertain, run } = useHomeworkMutation(org, () => id.value);
const text = ref(''),
  saved = ref(false),
  confirm = ref(false);
const submitKeys = new Map<string, string>();
watch(data, (value) => {
  if (value) text.value = value.submission.text;
});
watch(
  [id, org],
  () => {
    text.value = '';
    saved.value = false;
    confirm.value = false;
    submitKeys.clear();
  },
  { flush: 'sync' },
);
const activity = computed(
  () => data.value && ['typing', 'activity'].includes(data.value.assignment.type),
);
const editable = computed(
  () =>
    !!data.value &&
    data.value.assignment.status === 'PUBLISHED' &&
    ['NOT_STARTED', 'IN_PROGRESS', 'RETURNED'].includes(data.value.submission.state),
);
const dirty = computed(() => text.value !== (data.value?.submission.text ?? ''));
const overdue = computed(
  () =>
    !!data.value?.assignment.dueAt &&
    Date.parse(data.value.assignment.dueAt) < Date.parse(data.value.now),
);
const canSubmit = computed(
  () =>
    editable.value &&
    !dirty.value &&
    !!data.value?.submission.id &&
    (!overdue.value || data.value.assignment.allowLate) &&
    (activity.value
      ? !!data.value.submission.activityResult &&
        data.value.submission.activityResult.passed !== false
      : data.value.assignment.type === 'files'
        ? data.value.submission.files.length > 0
        : !!text.value.trim() || data.value.submission.files.length > 0),
);
function date(value: string): string {
  return new Intl.DateTimeFormat(locale.value, {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: studentSession.timezone,
  }).format(new Date(value));
}
async function save(): Promise<void> {
  if (!data.value || !editable.value) return;
  const version = data.value.submission.version;
  await run(
    () => homeworkApi.draft(org(), id.value, version, text.value),
    (result) => {
      data.value = result;
      saved.value = true;
    },
  );
}
async function submit(): Promise<void> {
  if (!data.value || !canSubmit.value) return;
  const version = data.value.submission.version,
    key = `${id.value}:${version}`;
  if (!submitKeys.has(key)) submitKeys.set(key, crypto.randomUUID());
  await run(
    () => homeworkApi.submit(org(), id.value, version, submitKeys.get(key)!),
    (result) => {
      data.value = result;
      confirm.value = false;
      saved.value = false;
    },
  );
}
async function reconcile(): Promise<void> {
  await refresh();
  if (data.value && !error.value) {
    uncertain.value = false;
    actionError.value = null;
  }
}
</script>
<template>
  <section>
    <header class="edu-page-title">
      <h1>{{ data?.assignment.title ?? t('education.myHomework') }}</h1>
      <RouterLink
        to="/student/homework"
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
      <article class="edu-card">
        <div class="edu-form-actions">
          <span class="edu-status">{{ t(`education.homework${data.submission.state}`) }}</span>
          <span>{{ t(`education.homework${data.assignment.status}`) }}</span>
        </div>
        <p class="edu-homework-prose">{{ data.assignment.description }}</p>
        <h2>{{ t('education.homeworkInstructions') }}</h2>
        <p class="edu-homework-prose">{{ data.assignment.instructions }}</p>
        <p>
          {{ t('education.homeworkDue') }}:
          {{ data.assignment.dueAt ? date(data.assignment.dueAt) : t('education.homeworkNoDue') }}
          <strong
            v-if="overdue"
            class="edu-error"
          >
            · {{ t('education.homeworkOverdue') }}
          </strong>
          <span v-if="data.assignment.allowLate">· {{ t('education.homeworkLateAllowed') }}</span>
        </p>
        <RouterLink
          v-if="data.assignment.lessonId"
          :to="`/student/diary/${data.assignment.lessonId}`"
          class="edu-link"
        >
          {{ t('education.lesson') }}
        </RouterLink>
        <HomeworkFiles
          :organization-id="org()"
          :assignment-id="id"
          :files="data.assignment.files ?? []"
          :title="t('education.homeworkMaterials')"
        />
      </article>
      <article class="edu-card">
        <h2>{{ t('education.homeworkAnswer') }}</h2>
        <template v-if="activity">
          <p>
            {{
              data.assignment.activityCode === 'platform-logic'
                ? 'Platform Logic'
                : 'Platform Typing'
            }}
          </p>
          <p>
            {{
              data.assignment.activityConfig.durationSeconds
                ? `${data.assignment.activityConfig.durationSeconds} ${t('education.seconds')}`
                : `${data.assignment.activityConfig.roundCount ?? 5} ${t('education.logicRounds')}`
            }}
            · {{ data.assignment.activityConfig.language.toUpperCase() }} ·
            {{ t('education.homeworkMaxAttempts') }}: {{ data.assignment.maxAttempts }} ·
            {{
              t(
                `education.homework${data.assignment.attemptPolicy[0]!.toUpperCase()}${data.assignment.attemptPolicy.slice(1)}`,
              )
            }}
          </p>
          <RouterLink
            v-if="editable"
            :to="{
              path:
                data.assignment.activityCode === 'platform-logic'
                  ? '/student/logic/platform-logic'
                  : '/student/typing/platform-typing',
              query: { homework: id },
            }"
            class="edu-button edu-button--primary"
          >
            {{ t('education.homeworkRunActivity') }}
          </RouterLink>
          <p v-if="data.submission.activityResult">
            <strong>
              {{ t('education.homeworkActivityResult') }}:
              {{
                data.submission.activityResult.wpm !== undefined
                  ? `${data.submission.activityResult.wpm} ${t('education.wpm')}`
                  : `${data.submission.activityResult.score} ${t('education.score')}`
              }}
            </strong>
            · {{ data.submission.activityResult.accuracy }}% ·
            {{ data.submission.activityResult.errors }} {{ t('education.errors') }}
          </p>
          <p
            v-if="data.submission.activityResult?.passed === false"
            class="edu-error"
            role="status"
          >
            {{ t('education.activityTargetNotReached') }}
          </p>
          <p
            v-else-if="!data.submission.activityResult"
            class="edu-subtle"
          >
            {{ t('education.homeworkActivityMissing') }}
          </p>
        </template>
        <template v-else>
          <label class="edu-homework-field">
            <span>{{ t('education.homeworkText') }}</span>
            <textarea
              v-model="text"
              class="edu-input"
              rows="9"
              maxlength="20000"
              :disabled="!editable || busy || uncertain"
              @input="saved = false"
            />
          </label>
          <HomeworkFiles
            :organization-id="org()"
            :assignment-id="id"
            :files="data.submission.files"
            :editable="editable"
            :disabled="dirty || busy || uncertain"
            student
            :title="t('education.homeworkAttachments')"
            @changed="refresh"
          />
          <p
            v-if="dirty"
            class="edu-subtle"
            role="status"
          >
            {{ t('education.homeworkUnsaved') }}
          </p>
        </template>
        <p
          v-if="saved"
          role="status"
        >
          {{ t('education.homeworkSaved') }}
        </p>
        <p
          v-if="!editable"
          class="edu-subtle"
        >
          {{ t('education.homeworkLocked') }}
        </p>
        <p
          v-if="editable"
          class="edu-subtle"
        >
          {{ t('education.homeworkSubmitHint') }}
        </p>
        <div
          v-if="editable"
          class="edu-form-actions"
        >
          <AppButton
            v-if="!activity"
            variant="secondary"
            :loading="busy"
            :disabled="uncertain"
            @click="save"
          >
            {{ t('education.homeworkSaveDraft') }}
          </AppButton>
          <AppButton
            :disabled="!canSubmit || busy || uncertain"
            @click="confirm = true"
          >
            {{ t('education.homeworkSubmit') }}
          </AppButton>
        </div>
      </article>
      <HomeworkAnswerHistory
        :submission="data.submission"
        :organization-id="org()"
        :assignment-id="id"
      />
    </template>
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
    </p>
    <AppButton
      v-if="actionError || uncertain"
      variant="secondary"
      @click="reconcile"
    >
      {{ t('education.homeworkReload') }}
    </AppButton>
    <AppConfirmDialog
      v-model="confirm"
      :title="t('education.homeworkSubmit')"
      :description="t('education.homeworkSubmitConfirm')"
      :confirm-label="t('education.homeworkSubmit')"
      :busy="busy"
      @confirm="submit"
    />
  </section>
</template>
