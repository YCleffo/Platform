<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import { calendarWeek, isoToZonedDateTime, zonedDateTimeToIso } from '@/shared/formatting/calendar';
import { educationApi } from '../api';
import { useEducationWorkspace } from '../workspace';
import { useEducationI18n } from '../i18n';
import { useEducationError } from '../resources';
import EducationState from '../EducationState.vue';
import ActivityConfigFields from '../ActivityConfigFields.vue';
import { defaultActivityConfig } from '../activityTypes';
import {
  homeworkApi,
  type HomeworkAssignment,
  type HomeworkDetail,
  type HomeworkInput,
} from './api';
import { useHomeworkMutation, useHomeworkResource } from './resources';
const props = defineProps<{ assignment?: HomeworkAssignment }>();
const emit = defineEmits<{ saved: [detail: HomeworkDetail]; cancel: [] }>();
const workspace = useEducationWorkspace(),
  route = useRoute();
const { t } = useEducationI18n(),
  issue = useEducationError();
const groupOffset = ref(0),
  studentOffset = ref(0);
const lessonDate = ref(workspace.header?.date ?? new Date().toISOString().slice(0, 10));
const queryLessonId =
  typeof route.query.lessonId === 'string'
    ? route.query.lessonId
    : typeof route.query.lesson === 'string'
      ? route.query.lesson
      : null;
const initial = (): HomeworkInput => ({
  title: '',
  description: '',
  instructions: '',
  type: 'text',
  groupId: typeof route.query.groupId === 'string' ? route.query.groupId : null,
  studentIds: typeof route.query.studentId === 'string' ? [route.query.studentId] : [],
  lessonId: queryLessonId,
  dueAt: null,
  allowLate: false,
  activityCode: null,
  activityConfig: { durationSeconds: 30, language: 'ru' },
  maxAttempts: 3,
  attemptPolicy: 'best',
});
const form = reactive<HomeworkInput>(initial());
if (queryLessonId && !props.assignment && workspace.organizationId) {
  void educationApi
    .lesson(workspace.organizationId, queryLessonId)
    .then((lesson) => {
      if (lesson) {
        if (!form.groupId) form.groupId = lesson.groupId;
        if (!form.title) form.title = lesson.title;
      }
    })
    .catch(() => {});
}
const dueLocal = ref('');
const localError = ref('');
function localDate(value: string | null): string {
  return value ? isoToZonedDateTime(value, workspace.header?.timezone ?? 'UTC') : '';
}
watch(
  () => props.assignment,
  (assignment) => {
    const values = initial();
    if (assignment)
      for (const key of Object.keys(values) as (keyof HomeworkInput)[])
        Object.assign(values, { [key]: assignment[key] ?? values[key] });
    Object.assign(form, {
      ...values,
      studentIds: [...values.studentIds],
      activityConfig: { ...values.activityConfig },
    });
    dueLocal.value = localDate(assignment?.dueAt ?? null);
  },
  { immediate: true },
);
const groupChoice = computed({
  get: () => form.groupId ?? '',
  set: (value: string | string[]) => {
    form.groupId = String(value) || null;
  },
});
const lessonChoice = computed({
  get: () => form.lessonId ?? '',
  set: (value: string | string[]) => {
    form.lessonId = String(value) || null;
  },
});
const gameChoice = computed({
  get: () => form.activityCode ?? 'platform-typing',
  set: (value: string | string[]) => {
    form.activityCode = String(value);
    form.activityConfig = defaultActivityConfig(String(value));
  },
});
watch(
  () => form.type,
  (value) => {
    if (value === 'typing') {
      form.activityCode = 'platform-typing';
      form.activityConfig = defaultActivityConfig('platform-typing');
    }
  },
);
const {
  data: choices,
  loading,
  error,
  refresh,
} = useHomeworkResource(
  () => workspace.organizationId,
  async (org, signal) => {
    const week = calendarWeek(lessonDate.value);
    const [groups, students, schedule] = await Promise.all([
      educationApi.groups(org, groupOffset.value, signal),
      form.groupId
        ? educationApi.groupStudents(org, form.groupId, studentOffset.value, signal)
        : educationApi.students(org, studentOffset.value, signal),
      educationApi.schedule(org, week[0]!, week[6]!, signal),
    ]);
    return {
      groups,
      students,
      lessons: schedule.lessons.filter(
        (lesson) => !form.groupId || lesson.groupId === form.groupId,
      ),
    };
  },
  [groupOffset, studentOffset, () => form.groupId, lessonDate],
);
watch(
  () => form.groupId,
  () => {
    studentOffset.value = 0;
  },
);
const {
  busy,
  error: actionError,
  uncertain,
  run,
} = useHomeworkMutation(
  () => workspace.organizationId,
  () => props.assignment?.id ?? 'new',
);
const activity = computed(() => form.type === 'typing' || form.type === 'activity');
async function save(): Promise<void> {
  localError.value = '';
  let deadline: string | null = null;
  try {
    deadline = dueLocal.value
      ? zonedDateTimeToIso(dueLocal.value, workspace.header?.timezone ?? 'UTC')
      : null;
  } catch {
    localError.value = t('education.homeworkInvalid');
    return;
  }
  if (
    !form.title.trim() ||
    !form.instructions.trim() ||
    (!form.groupId && !form.studentIds.length) ||
    !Number.isInteger(Number(form.maxAttempts)) ||
    form.maxAttempts < 1 ||
    form.maxAttempts > 20
  ) {
    localError.value = t('education.homeworkInvalid');
    return;
  }
  const values: HomeworkInput = {
    ...form,
    title: form.title.trim(),
    dueAt: deadline,
    maxAttempts: Number(form.maxAttempts),
    activityCode: activity.value
      ? form.type === 'typing'
        ? 'platform-typing'
        : (form.activityCode ?? 'platform-typing')
      : null,
    activityConfig: {
      ...form.activityConfig,
      ...(form.activityConfig.minAccuracy !== undefined
        ? { minAccuracy: Number(form.activityConfig.minAccuracy) }
        : {}),
      ...(form.activityConfig.targetWpm !== undefined
        ? { targetWpm: Number(form.activityConfig.targetWpm) }
        : {}),
      maxAttempts: Number(form.maxAttempts),
    },
  };
  const mutable = {
    title: values.title,
    description: values.description,
    instructions: values.instructions,
    dueAt: values.dueAt,
    allowLate: values.allowLate,
    maxAttempts: values.maxAttempts,
  };
  await run(
    () =>
      props.assignment
        ? homeworkApi.update(workspace.organizationId, props.assignment.id, {
            ...(props.assignment.status === 'DRAFT' ? values : mutable),
            version: props.assignment.version,
          })
        : homeworkApi.create(workspace.organizationId, values),
    (result) => emit('saved', result),
  );
}
</script>
<template>
  <form
    class="edu-form edu-card"
    @submit.prevent="save"
  >
    <AppInput
      id="homework-title"
      v-model="form.title"
      :label="t('education.homeworkTitle')"
      required
      maxlength="160"
      :disabled="busy"
    />
    <label class="edu-homework-field">
      <span>{{ t('education.description') }}</span>
      <textarea
        v-model="form.description"
        class="edu-input"
        rows="3"
        maxlength="8000"
        :disabled="busy"
      />
    </label>
    <label class="edu-homework-field">
      <span>{{ t('education.homeworkInstructions') }}</span>
      <textarea
        v-model="form.instructions"
        class="edu-input"
        rows="6"
        required
        maxlength="20000"
        :disabled="busy"
      />
    </label>
    <AppDropdown
      v-model="form.type"
      :label="t('education.homeworkType')"
      show-label
      :disabled="busy || (!!assignment && assignment.status !== 'DRAFT')"
      :options="
        (['text', 'files', 'typing', 'activity'] as const).map((value) => ({
          value,
          label: t(`education.homework${value[0]!.toUpperCase()}${value.slice(1)}`),
        }))
      "
    />
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <template v-if="choices">
      <p class="edu-subtle">{{ t('education.homeworkRecipientHint') }}</p>
      <AppDropdown
        v-model="groupChoice"
        :label="t('education.group')"
        show-label
        :disabled="busy || (!!assignment && assignment.status !== 'DRAFT')"
        :options="[
          { value: '', label: t('education.homeworkAll') },
          ...choices.groups.items.map((group) => ({ value: group.id, label: group.name })),
        ]"
        clearable
      />
      <div class="edu-form-actions">
        <AppButton
          v-if="groupOffset"
          variant="secondary"
          @click="groupOffset = Math.max(0, groupOffset - 50)"
        >
          {{ t('education.back') }}
        </AppButton>
        <AppButton
          v-if="choices.groups.nextOffset !== null"
          variant="secondary"
          @click="groupOffset = choices.groups.nextOffset!"
        >
          {{ t('education.loadMore') }} · {{ t('education.groups') }}
        </AppButton>
      </div>
      <AppDropdown
        v-model="form.studentIds"
        multiple
        searchable
        :label="t('education.students')"
        :disabled="busy || (!!assignment && assignment.status !== 'DRAFT')"
        :options="
          choices.students.items.map((student) => ({
            value: student.id,
            label: student.name ?? student.id,
          }))
        "
      />
      <p
        v-if="form.studentIds.length"
        class="edu-subtle"
      >
        {{ t('education.homeworkRecipients') }}: {{ form.studentIds.length }}
      </p>
      <div class="edu-form-actions">
        <AppButton
          v-if="studentOffset"
          variant="secondary"
          @click="studentOffset = Math.max(0, studentOffset - 50)"
        >
          {{ t('education.back') }}
        </AppButton>
        <AppButton
          v-if="choices.students.nextOffset !== null"
          variant="secondary"
          @click="studentOffset = choices.students.nextOffset!"
        >
          {{ t('education.loadMore') }} · {{ t('education.students') }}
        </AppButton>
      </div>
      <AppInput
        id="homework-lesson-date"
        v-model="lessonDate"
        type="date"
        :label="t('education.date')"
      />
      <AppDropdown
        v-model="lessonChoice"
        :label="t('education.lesson')"
        clearable
        :disabled="busy || (!!assignment && assignment.status !== 'DRAFT')"
        :options="[
          { value: '', label: t('education.noCurriculumLesson') },
          ...choices.lessons.map((lesson) => ({
            value: lesson.id,
            label: `${lesson.title} · ${lesson.startsAt.slice(0, 10)}`,
          })),
        ]"
      />
    </template>
    <AppInput
      id="homework-due"
      v-model="dueLocal"
      type="datetime-local"
      :label="t('education.homeworkDue')"
      :disabled="busy"
    />
    <label class="edu-check">
      <input
        v-model="form.allowLate"
        type="checkbox"
        :disabled="busy"
      />
      {{ t('education.homeworkLateAllowed') }}
    </label>
    <fieldset
      v-if="activity"
      class="edu-homework-settings"
    >
      <legend>{{ t('education.exerciseSettings') }}</legend>
      <AppDropdown
        v-if="form.type === 'activity'"
        v-model="gameChoice"
        :label="t('education.activity')"
        :options="[
          { value: 'platform-typing', label: 'Platform Typing' },
          { value: 'platform-logic', label: 'Platform Logic' },
          { value: 'platform-math-sprint', label: 'Platform Math Sprint' },
          { value: 'platform-quiz', label: 'Platform Quiz' },
        ]"
        :disabled="busy || (!!assignment && assignment.status !== 'DRAFT')"
      />
      <ActivityConfigFields
        v-model="form.activityConfig"
        :activity-code="form.activityCode ?? 'platform-typing'"
        :disabled="busy || (!!assignment && assignment.status !== 'DRAFT')"
      />
      <AppInput
        id="homework-min-accuracy"
        v-model="form.activityConfig.minAccuracy"
        type="number"
        min="0"
        max="100"
        :label="t('education.minAccuracy')"
        :disabled="busy || (!!assignment && assignment.status !== 'DRAFT')"
      />
      <AppInput
        v-if="gameChoice === 'platform-typing'"
        id="homework-target-wpm"
        v-model="form.activityConfig.targetWpm"
        type="number"
        min="0"
        max="500"
        :label="t('education.targetWpm')"
        :disabled="busy || (!!assignment && assignment.status !== 'DRAFT')"
      />
      <AppInput
        id="homework-attempts"
        v-model="form.maxAttempts"
        type="number"
        min="1"
        max="20"
        :label="t('education.homeworkMaxAttempts')"
        :disabled="busy"
      />
      <AppDropdown
        v-model="form.attemptPolicy"
        :label="t('education.homeworkAttemptPolicy')"
        :options="
          (['first', 'latest', 'best'] as const).map((value) => ({
            value,
            label: t(`education.homework${value[0]!.toUpperCase()}${value.slice(1)}`),
          }))
        "
        :disabled="busy || (!!assignment && assignment.status !== 'DRAFT')"
      />
    </fieldset>
    <p
      v-if="localError || actionError"
      class="edu-error"
      role="alert"
    >
      {{ localError || issue(actionError) }}
    </p>
    <p
      v-if="uncertain"
      class="edu-error"
      role="alert"
    >
      {{ t('education.homeworkUncertain') }}
    </p>
    <div class="edu-form-actions">
      <AppButton
        type="submit"
        :loading="busy"
        :disabled="uncertain"
      >
        {{ t('education.save') }}
      </AppButton>
      <AppButton
        variant="secondary"
        :disabled="busy"
        @click="emit('cancel')"
      >
        {{ t('education.back') }}
      </AppButton>
    </div>
  </form>
</template>
