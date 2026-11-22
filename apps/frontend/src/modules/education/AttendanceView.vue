<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import { calendarWeek, formatCalendarDate, isoToZonedDateTime } from '@/shared/formatting/calendar';
import { educationApi, type Participant } from './api';
import { useEducationWorkspace } from './workspace';
import { useEducationResource, useEducationMutation, useEducationError } from './resources';
import { useEducationI18n } from './i18n';
import { lessonStarted } from './status';
import EducationState from './EducationState.vue';
const route = useRoute();
const workspace = useEducationWorkspace();
const { t, locale } = useEducationI18n();
const issue = useEducationError();
const id = computed(() => String(route.params.id));
const selected = ref(workspace.header!.date);
const week = computed(() => calendarWeek(selected.value));
const { data, loading, error, refresh } = useEducationResource(
  async (org, signal) => {
    const [group, schedule] = await Promise.all([
      educationApi.group(org, id.value, signal),
      educationApi.schedule(org, week.value[0]!, week.value[6]!, signal),
    ]);
    const lessons = await Promise.all(
      schedule.lessons
        .filter((lesson) => lesson.groupId === id.value)
        .map(async (lesson) => ({
          lesson,
          participants: (await educationApi.participants(org, lesson.id, signal)).items,
        })),
    );
    return {
      group,
      lessons: lessons.sort((a, b) => a.lesson.startsAt.localeCompare(b.lesson.startsAt)),
    };
  },
  [id, week],
);
const roster = computed(() =>
  [
    ...new Map(
      (data.value?.lessons ?? []).flatMap((column) =>
        column.participants.map((p) => [p.id, { id: p.id, name: p.name }] as const),
      ),
    ).values(),
  ].sort((a, b) => a.name.localeCompare(b.name)),
);
const mutation = useEducationMutation();
const { busy, error: actionError, uncertain } = mutation;
function date(instant: string): string {
  return formatCalendarDate(
    isoToZonedDateTime(instant, workspace.header!.timezone).slice(0, 10),
    locale.value,
    { day: 'numeric', month: 'short' },
  );
}
function status(lessonId: string, studentId: string): Participant['status'] | undefined {
  return data.value?.lessons
    .find((c) => c.lesson.id === lessonId)
    ?.participants.find((p) => p.id === studentId)?.status;
}
async function save(lessonId: string, studentId: string, value: string): Promise<void> {
  if (busy.value || uncertain.value || !data.value) return;
  const previous = status(lessonId, studentId);
  if (!previous) return;
  const snapshot = data.value;
  const patch = (next: Participant['status']) => {
    if (data.value)
      data.value = {
        ...data.value,
        lessons: data.value.lessons.map((c) =>
          c.lesson.id === lessonId
            ? {
                ...c,
                participants: c.participants.map((p) =>
                  p.id === studentId ? { ...p, status: next } : p,
                ),
              }
            : c,
        ),
      };
  };
  patch(value as Participant['status']);
  await mutation.run(
    () => educationApi.attendance(workspace.organizationId, lessonId, studentId, value),
    () => refresh(),
  );
  if (actionError.value && data.value?.group.id === snapshot.group.id) patch(previous);
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
      <h1>{{ data?.group.name }} · {{ t('education.attendance') }}</h1>
      <RouterLink
        :to="`/groups/${id}`"
        class="edu-link"
      >
        {{ t('education.group') }}
      </RouterLink>
    </header>
    <div class="edu-toolbar">
      <AppInput
        id="attendance-week"
        v-model="selected"
        type="date"
        :label="t('education.week')"
      />
      <RouterLink
        :to="`/groups/${id}/journal`"
        class="edu-button"
      >
        {{ t('education.openJournal') }}
      </RouterLink>
    </div>
    <EducationState
      :loading="loading && !data"
      :error="error"
      :empty="data && !data.lessons.length ? t('education.noJournalPeriod') : undefined"
      @retry="refresh"
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
    <div
      v-if="data?.lessons.length"
      class="edu-attendance-scroll"
      tabindex="0"
      :aria-label="t('education.attendance')"
    >
      <table class="edu-attendance-table">
        <caption>{{ t('education.attendance') }} · {{ week[0] }} · {{ week[6] }}</caption>
        <thead>
          <tr>
            <th scope="col">{{ t('education.students') }}</th>
            <th
              v-for="column in data.lessons"
              :key="column.lesson.id"
              scope="col"
            >
              <RouterLink
                :to="`/lessons/${column.lesson.id}/participants`"
                class="edu-link"
              >
                {{ date(column.lesson.startsAt) }}
              </RouterLink>
              <p>{{ column.lesson.title }}</p>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="student in roster"
            :key="student.id"
          >
            <th scope="row">
              <RouterLink
                :to="`/students/${student.id}`"
                class="edu-link"
              >
                {{ student.name }}
              </RouterLink>
            </th>
            <td
              v-for="column in data.lessons"
              :key="column.lesson.id"
            >
              <select
                v-if="status(column.lesson.id, student.id)"
                :aria-label="`${student.name} · ${date(column.lesson.startsAt)} · ${column.lesson.title}`"
                :value="status(column.lesson.id, student.id)"
                :disabled="
                  busy ||
                  uncertain ||
                  column.lesson.status === 'cancelled' ||
                  !lessonStarted(column.lesson, workspace.header!.now)
                "
                @change="
                  save(column.lesson.id, student.id, ($event.target as HTMLSelectElement).value)
                "
              >
                <option value="pending">{{ t('education.pending') }}</option>
                <option value="present">{{ t('education.present') }}</option>
                <option value="absent">{{ t('education.absentStatus') }}</option>
              </select>
              <template v-else>
                <span aria-hidden="true">—</span>
                <span class="visually-hidden">{{ t('education.noParticipation') }}</span>
              </template>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
<style scoped>
.edu-attendance-scroll {
  overflow: auto;
  max-width: 100%;
  border: 1px solid var(--border);
  border-radius: var(--radius-card-m);
}
.edu-attendance-table {
  width: 100%;
  border-collapse: collapse;
  background: var(--surface);
}
.edu-attendance-table caption {
  text-align: left;
  padding: 14px;
  color: var(--text-2);
}
.edu-attendance-table :is(th, td) {
  min-width: 155px;
  border: 1px solid var(--border);
  padding: 12px;
  text-align: left;
}
.edu-attendance-table thead th {
  background: var(--surface-2);
  vertical-align: top;
}
.edu-attendance-table tbody th {
  position: sticky;
  left: 0;
  background: var(--surface);
  min-width: 180px;
  max-width: 260px;
}
.edu-attendance-table select {
  min-height: 44px;
  max-width: 100%;
  padding: 8px;
  color: var(--text-1);
  background: var(--surface);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-control-m);
  font: inherit;
}
</style>
