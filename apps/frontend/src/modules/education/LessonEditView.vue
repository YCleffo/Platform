<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import { isoToZonedDateTime, zonedDateTimeToIso } from '@/shared/formatting/calendar';
import { educationApi, type Group } from './api';
import { curriculumLessonOptions, curriculumSnapshotLabel } from './curriculum';
import { useEducationWorkspace } from './workspace';
import { useEducationError, useEducationMutation, useEducationResource } from './resources';
import { useHomeworkResource } from './homework/resources';
import { useEducationI18n } from './i18n';
import EducationState from './EducationState.vue';
const route = useRoute();
const router = useRouter();
const workspace = useEducationWorkspace();
const { t } = useEducationI18n();
const issue = useEducationError();
const id = computed(() => String(route.params.id ?? ''));
const { data, loading, error, refresh } = useEducationResource(async (org, signal) => {
  const lesson = await educationApi.lesson(org, id.value, signal);
  const groups = await educationApi.groups(org, 0, signal);
  const selectedGroup = groups.items.some((item) => item.id === lesson.groupId)
    ? null
    : await educationApi.group(org, lesson.groupId, signal).catch(() => null);
  return { lesson, groups, selectedGroup };
});
const title = ref('');
const groupId = ref('');
const curriculumLessonId = ref('');
const startsAt = ref('');
const endsAt = ref('');
const location = ref('');
const localError = ref('');
let loaded = false;
watch(
  groupId,
  (group) => {
    const lesson = data.value?.lesson;
    curriculumLessonId.value = lesson?.groupId === group ? (lesson.curriculumLessonId ?? '') : '';
  },
  { flush: 'sync' },
);
watch(
  data,
  (value) => {
    if (!value || loaded) return;
    loaded = true;
    title.value = value.lesson.title;
    groupId.value = value.lesson.groupId;
    curriculumLessonId.value = value.lesson.curriculumLessonId ?? '';
    startsAt.value = isoToZonedDateTime(value.lesson.startsAt, workspace.header!.timezone);
    endsAt.value = isoToZonedDateTime(value.lesson.endsAt, workspace.header!.timezone);
    location.value = value.lesson.location;
  },
  { immediate: true },
);
const curriculum = useHomeworkResource(
  () => workspace.organizationId,
  async (org, signal) => {
    const group = groupId.value;
    if (!group) return null;
    const programs = await educationApi.groupPrograms(org, group, signal);
    return curriculumLessonOptions(programs.items);
  },
  [groupId],
);
const curriculumOptions = computed(() => {
  const current = data.value?.lesson;
  const options = [...(curriculum.data.value ?? [])];
  const linkedId = current?.curriculumLessonId;
  if (
    current?.groupId === groupId.value &&
    linkedId &&
    !options.some((option) => option.value === linkedId)
  ) {
    options.unshift({
      value: linkedId,
      label: curriculumSnapshotLabel(current.curriculumSnapshot) || t('education.curriculumLesson'),
    });
  }
  return [{ value: '', label: t('education.noCurriculumLesson') }, ...options];
});
const groupOptions = computed<Group[]>(() => {
  const selected = data.value?.selectedGroup;
  const items = data.value?.groups.items ?? [];
  return selected && !items.some((item) => item.id === selected.id) ? [selected, ...items] : items;
});
const locked = computed(() => !!data.value && data.value.lesson.status !== 'scheduled');
const mutation = useEducationMutation();
const { busy, error: actionError, uncertain } = mutation;
async function submit(): Promise<void> {
  const lesson = data.value?.lesson;
  if (!lesson || locked.value) return;
  localError.value = '';
  let start = '';
  let end = '';
  try {
    start = zonedDateTimeToIso(startsAt.value, workspace.header!.timezone);
    end = zonedDateTimeToIso(endsAt.value, workspace.header!.timezone);
    if (Date.parse(end) <= Date.parse(start)) throw new RangeError();
  } catch {
    localError.value = t('education.invalidTime');
    return;
  }
  await mutation.run(
    () =>
      educationApi.updateLesson(workspace.organizationId, id.value, {
        title: title.value,
        groupId: groupId.value,
        startsAt: start,
        endsAt: end,
        location: location.value,
        ...(groupId.value !== lesson.groupId ||
        curriculumLessonId.value !== (lesson.curriculumLessonId ?? '')
          ? { curriculumLessonId: curriculumLessonId.value || null }
          : {}),
      }),
    async () => {
      await workspace.refreshHeader();
      await router.push(`/lessons/${id.value}`);
    },
  );
}
</script>
<template>
  <section>
    <header class="edu-page-title">
      <h1>{{ t('education.editLesson') }}</h1>
      <RouterLink
        :to="`/lessons/${id}`"
        class="edu-link"
      >
        {{ t('education.back') }}
      </RouterLink>
    </header>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <template v-if="data && !loading && !error">
      <p
        v-if="locked"
        class="edu-state"
      >
        {{ t('education.lessonLocked') }}
      </p>
      <form
        v-else
        class="edu-form edu-card"
        @submit.prevent="submit"
      >
        <AppInput
          id="education-edit-title"
          v-model="title"
          :label="t('education.name')"
          :maxlength="160"
          required
          :disabled="busy || uncertain"
        />
        <AppDropdown
          id="education-edit-group"
          v-model="groupId"
          :label="t('education.group')"
          :options="
            groupOptions
              .filter((item) => !item.archivedAt)
              .map((item) => ({ value: item.id, label: item.name }))
          "
          :disabled="busy || uncertain"
        />
        <AppDropdown
          v-if="groupId"
          id="education-edit-curriculum-lesson"
          v-model="curriculumLessonId"
          :label="t('education.curriculumLesson')"
          :options="curriculumOptions"
          :disabled="busy || uncertain || curriculum.loading.value"
        />
        <EducationState
          v-if="curriculum.error.value"
          :error="curriculum.error.value"
          @retry="curriculum.refresh"
        />
        <p class="edu-subtle">{{ t('education.timezone') }}: {{ workspace.header!.timezone }}</p>
        <AppInput
          id="education-edit-starts-at"
          v-model="startsAt"
          type="datetime-local"
          :label="t('education.startsAt')"
          required
          :disabled="busy || uncertain"
        />
        <AppInput
          id="education-edit-ends-at"
          v-model="endsAt"
          type="datetime-local"
          :label="t('education.endsAt')"
          required
          :disabled="busy || uncertain"
        />
        <AppInput
          id="education-edit-location"
          v-model="location"
          :label="t('education.location')"
          :maxlength="240"
          :disabled="busy || uncertain"
        />
        <p
          v-if="localError || actionError"
          class="edu-error"
          role="alert"
        >
          {{ localError || (uncertain ? t('education.uncertain') : issue(actionError)) }}
        </p>
        <div class="edu-form-actions">
          <button
            type="submit"
            class="edu-button edu-button--primary"
            :disabled="busy || uncertain"
          >
            {{ t(busy ? 'education.loading' : 'education.save') }}
          </button>
          <RouterLink
            v-if="uncertain"
            :to="`/lessons/${id}`"
            class="edu-button"
          >
            {{ t('education.checkList') }}
          </RouterLink>
        </div>
      </form>
    </template>
  </section>
</template>
