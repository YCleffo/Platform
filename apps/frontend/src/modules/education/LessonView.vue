<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { useRoute } from 'vue-router';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import { formatCalendarDate, formatZonedTime } from '@/shared/formatting/calendar';
import { educationApi } from './api';
import { useEducationWorkspace } from './workspace';
import { useEducationMutation, useEducationResource, useEducationError } from './resources';
import { useEducationI18n } from './i18n';
import EducationState from './EducationState.vue';
import MaterialList from './MaterialList.vue';
const props = withDefaults(
  defineProps<{ mode?: 'detail' | 'participants' | 'materials' | 'current' }>(),
  { mode: 'detail' },
);
const route = useRoute();
const workspace = useEducationWorkspace();
const { t, locale, students } = useEducationI18n();
const offset = ref(0);
const issue = useEducationError();
const { data, loading, error, refresh } = useEducationResource(
  async (org, signal) => {
    const id = String(route.params.id ?? '');
    const lesson =
      props.mode === 'current'
        ? await educationApi.current(org, signal)
        : await educationApi.lesson(org, id, signal);
    return {
      lesson,
      participants:
        props.mode === 'participants' && lesson
          ? await educationApi.participants(org, lesson.id, signal)
          : null,
      materials:
        props.mode === 'materials' && lesson
          ? await educationApi.files(org, offset.value, lesson.id, signal)
          : null,
    };
  },
  [offset],
);
const mutation = useEducationMutation();
const { busy, error: actionError, uncertain } = mutation;
const started = computed(
  () =>
    !!data.value?.lesson &&
    Date.parse(data.value.lesson.startsAt) <= Date.parse(workspace.header!.now),
);
function time(value: string): string {
  return formatZonedTime(value, locale.value, workspace.header!.timezone);
}
async function attendance(id: string, value: unknown): Promise<void> {
  const lesson = data.value?.lesson;
  if (!lesson) return;
  await mutation.run(
    () => educationApi.attendance(workspace.organizationId, lesson.id, id, String(value)),
    async () => {
      await refresh();
      await nextTick();
      document.getElementById(`education-attendance-${id}`)?.focus();
    },
  );
}
async function reconcile(): Promise<void> {
  await refresh();
  if (!error.value) {
    uncertain.value = false;
    actionError.value = null;
  }
}
</script>
<template>
  <section>
    <header class="edu-page-title">
      <h1>{{ data?.lesson?.title ?? t('education.openLesson') }}</h1>
      <RouterLink
        to="/calendar"
        class="edu-link"
      >
        {{ t('education.calendar') }}
      </RouterLink>
    </header>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <div
      v-if="data && !data.lesson"
      class="edu-state"
    >
      <p>{{ t('education.noCurrent') }}</p>
      <RouterLink
        to="/lessons/new"
        class="edu-button"
      >
        {{ t('education.createLesson') }}
      </RouterLink>
    </div>
    <template v-if="data?.lesson">
      <article class="edu-card">
        <p>{{ data.lesson.groupName }} · {{ students(data.lesson.studentCount) }}</p>
        <p>
          {{ time(data.lesson.startsAt) }} – {{ time(data.lesson.endsAt) }} ·
          {{ workspace.header!.timezone }}
        </p>
        <p v-if="data.lesson.location">{{ data.lesson.location }}</p>
        <p class="edu-subtle">
          {{
            formatCalendarDate(
              new Intl.DateTimeFormat('en-CA', {
                timeZone: workspace.header!.timezone,
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
              }).format(new Date(data.lesson.startsAt)),
              locale,
              { dateStyle: 'long' },
            )
          }}
        </p>
      </article>
      <nav
        class="edu-toolbar"
        :aria-label="t('education.openLesson')"
      >
        <RouterLink
          :to="`/lessons/${data.lesson.id}`"
          class="edu-button"
        >
          {{ t('education.openLesson') }}
        </RouterLink>
        <RouterLink
          :to="`/lessons/${data.lesson.id}/participants`"
          class="edu-button"
        >
          {{ t('education.participants') }}
        </RouterLink>
        <RouterLink
          :to="`/lessons/${data.lesson.id}/materials`"
          class="edu-button"
        >
          {{ t('education.materials') }}
        </RouterLink>
      </nav>
      <template v-if="data.participants">
        <h2>{{ t('education.attendance') }}</h2>
        <EducationState
          v-if="!data.participants.items.length"
          :empty="t('education.noParticipants')"
        />
        <p
          v-if="actionError"
          class="edu-error"
          role="alert"
        >
          {{ uncertain ? t('education.uncertain') : issue(actionError) }}
        </p>
        <button
          v-if="uncertain"
          type="button"
          class="edu-button"
          @click="reconcile"
        >
          {{ t('education.checkList') }}
        </button>
        <ul class="edu-list">
          <li
            v-for="person in data.participants.items"
            :key="person.id"
            class="edu-list__item"
          >
            <strong>{{ person.name }}</strong>
            <AppDropdown
              v-if="started"
              :id="`education-attendance-${person.id}`"
              :label="`${t('education.attendance')}: ${person.name}`"
              :model-value="person.status"
              :options="
                ['pending', 'present', 'absent'].map((value) => ({
                  value,
                  label: t(`education.${value === 'absent' ? 'absentStatus' : value}`),
                }))
              "
              :disabled="busy || uncertain"
              @update:model-value="(value) => attendance(person.id, value)"
            />
            <span v-else>
              {{ t(`education.${person.status === 'absent' ? 'absentStatus' : person.status}`) }}
            </span>
          </li>
        </ul>
      </template>
      <template v-if="data.materials">
        <RouterLink
          :to="`/materials/upload?lesson=${data.lesson.id}`"
          class="edu-button"
        >
          {{ t('education.uploadMaterials') }}
        </RouterLink>
        <MaterialList
          :page="data.materials"
          @more="(value) => (offset = value)"
        />
        <button
          v-if="offset"
          type="button"
          class="edu-button"
          @click="offset = Math.max(0, offset - 50)"
        >
          {{ t('education.back') }}
        </button>
      </template>
    </template>
  </section>
</template>
