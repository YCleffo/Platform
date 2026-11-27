<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { onBeforeRouteLeave, useRoute } from 'vue-router';
import AppConfirmDialog from '@/shared/ui/overlays/AppConfirmDialog.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import { formatCalendarDate, formatZonedTime } from '@/shared/formatting/calendar';
import { educationApi, type Lesson, type ResultStatus } from './api';
import { curriculumSnapshotLabel } from './curriculum';
import { useEducationWorkspace } from './workspace';
import { useEducationMutation, useEducationResource, useEducationError } from './resources';
import { useEducationI18n } from './i18n';
import { resultStatusKey } from './journal';
import { lessonDisplayStatus, lessonStarted } from './status';
import EducationState from './EducationState.vue';
import MaterialList from './MaterialList.vue';
const props = withDefaults(
  defineProps<{ mode?: 'detail' | 'participants' | 'materials' | 'results' | 'current' }>(),
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
      results:
        props.mode === 'results' && lesson
          ? await educationApi.lessonResults(org, lesson.id, signal)
          : null,
      materials:
        props.mode === 'materials' && lesson
          ? await educationApi.files(org, offset.value, lesson.id, signal)
          : null,
    };
  },
  [offset, () => route.params.id, () => props.mode],
);
const mutation = useEducationMutation();
const { busy, error: actionError, uncertain } = mutation;
const started = computed(
  () => !!data.value?.lesson && lessonStarted(data.value.lesson, workspace.header!.now),
);
const displayStatus = computed<Lesson['status'] | 'now'>(() =>
  data.value?.lesson ? lessonDisplayStatus(data.value.lesson, workspace.header!.now) : 'scheduled',
);
const programLesson = computed(() =>
  curriculumSnapshotLabel(data.value?.lesson?.curriculumSnapshot),
);
const canMarkAttendance = computed(
  () => !!data.value?.lesson && data.value.lesson.status !== 'cancelled' && started.value,
);
const confirmOpen = ref(false);
const pendingAction = ref<'complete' | 'cancel' | null>(null);
function statusLabel(value: Lesson['status'] | 'now'): string {
  return t(`education.${value === 'now' ? 'inProgress' : value}`);
}
function ask(action: 'complete' | 'cancel'): void {
  pendingAction.value = action;
  confirmOpen.value = true;
}
async function confirmStatus(): Promise<void> {
  const lesson = data.value?.lesson;
  const action = pendingAction.value;
  if (!lesson || !action) return;
  await mutation.run(
    () =>
      action === 'complete'
        ? educationApi.completeLesson(workspace.organizationId, lesson.id)
        : educationApi.cancelLesson(workspace.organizationId, lesson.id),
    async () => {
      confirmOpen.value = false;
      pendingAction.value = null;
      await refresh();
      await workspace.refreshHeader();
    },
  );
}
function time(value: string): string {
  return formatZonedTime(value, locale.value, workspace.header!.timezone);
}
async function attendance(id: string, value: unknown): Promise<void> {
  const lesson = data.value?.lesson;
  const participant = data.value?.participants?.items.find((item) => item.id === id);
  if (
    !lesson ||
    !participant ||
    busy.value ||
    uncertain.value ||
    !['pending', 'present', 'absent'].includes(String(value))
  )
    return;
  const previous = participant.status;
  const snapshot = data.value!;
  data.value = {
    ...snapshot,
    participants: {
      items: snapshot.participants!.items.map((item) =>
        item.id === id ? { ...item, status: String(value) as typeof previous } : item,
      ),
    },
  };
  await mutation.run(
    () => educationApi.attendance(workspace.organizationId, lesson.id, id, String(value)),
    async () => {
      await refresh();
      await nextTick();
      document.getElementById(`education-attendance-${id}`)?.focus();
    },
  );
  if (actionError.value && data.value?.lesson?.id === lesson.id && data.value.participants)
    data.value = {
      ...data.value,
      participants: {
        items: data.value.participants.items.map((item) =>
          item.id === id ? { ...item, status: previous } : item,
        ),
      },
    };
}
async function reconcile(): Promise<void> {
  await refresh();
  if (!error.value) {
    uncertain.value = false;
    actionError.value = null;
  }
}
const resultStatuses: ResultStatus[] = ['pending', 'mastered', 'developing', 'needs_support'];
const reportDraft = reactive({ topic: '', summary: '', teacherComment: '' });
const resultDrafts = reactive(new Map<string, { status: ResultStatus; comment: string }>());
const dirtyResults = reactive(new Set<string>());
const dirtyReport = ref(false);
const draftKey = (lessonId: string, studentId: string) => `${lessonId}:${studentId}`;

onBeforeRouteLeave(() => {
  if (dirtyReport.value || dirtyResults.size > 0) {
    return window.confirm(t('education.unsavedChangesConfirm'));
  }
  return true;
});

watch(
  () => [data.value?.lesson?.id, data.value?.results] as const,
  ([lessonId, value]) => {
    if (!lessonId || !value) return;
    if (!dirtyReport.value) {
      if (value.report) {
        reportDraft.topic = value.report.topic;
        reportDraft.summary = value.report.summary;
        reportDraft.teacherComment = value.report.teacherComment ?? '';
      } else {
        reportDraft.topic = '';
        reportDraft.summary = '';
        reportDraft.teacherComment = '';
      }
    }
    for (const item of value.items) {
      const key = draftKey(lessonId, item.id);
      if (!dirtyResults.has(key)) {
        resultDrafts.set(key, { status: item.status, comment: item.comment ?? '' });
      }
    }
  },
  { immediate: true },
);
async function saveReport(): Promise<void> {
  const lesson = data.value?.lesson;
  if (!lesson) return;
  await mutation.run(
    () =>
      educationApi.saveLessonReport(workspace.organizationId, lesson.id, {
        topic: reportDraft.topic,
        summary: reportDraft.summary,
        teacherComment:
          reportDraft.teacherComment.trim() === '' ? null : reportDraft.teacherComment,
      }),
    async () => {
      dirtyReport.value = false;
      await refresh();
    },
  );
}
async function saveResult(studentId: string): Promise<void> {
  const lesson = data.value?.lesson;
  const key = lesson ? draftKey(lesson.id, studentId) : '';
  const draft = key ? resultDrafts.get(key) : undefined;
  if (!lesson || !draft) return;
  await mutation.run(
    () =>
      educationApi.saveLessonResult(workspace.organizationId, lesson.id, studentId, {
        status: draft.status,
        comment: draft.comment.trim() === '' ? null : draft.comment,
      }),
    async () => {
      dirtyResults.delete(key);
      await refresh();
    },
  );
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
        <p>
          <span
            class="edu-status"
            :class="`edu-status--${displayStatus}`"
            data-lesson-status
          >
            {{ statusLabel(displayStatus) }}
          </span>
        </p>
        <p>
          <RouterLink
            :to="`/groups/${data.lesson.groupId}`"
            class="edu-link"
          >
            {{ data.lesson.groupName }}
          </RouterLink>
          · {{ students(data.lesson.studentCount) }}
        </p>
        <p v-if="programLesson">
          <strong>{{ t('education.curriculumLesson') }}</strong>
          · {{ programLesson }}
        </p>
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
          :to="`/lessons/${data.lesson.id}/results`"
          class="edu-button"
        >
          {{ t('education.results') }}
        </RouterLink>
        <RouterLink
          :to="`/lessons/${data.lesson.id}/materials`"
          class="edu-button"
        >
          {{ t('education.materials') }}
        </RouterLink>
        <RouterLink
          :to="`/homework/new?lessonId=${data.lesson.id}&groupId=${data.lesson.groupId}`"
          class="edu-button"
        >
          {{ t('education.homeworkCreateFromLesson') }}
        </RouterLink>
        <RouterLink
          v-if="data.lesson.status === 'scheduled'"
          :to="`/lessons/${data.lesson.id}/edit`"
          class="edu-button"
        >
          {{ t('education.editLesson') }}
        </RouterLink>
        <button
          v-if="data.lesson.status === 'scheduled'"
          type="button"
          class="edu-button edu-button--primary"
          :disabled="busy || uncertain"
          @click="ask('complete')"
        >
          {{ t('education.completeLesson') }}
        </button>
        <button
          v-if="data.lesson.status === 'scheduled'"
          type="button"
          class="edu-button"
          :disabled="busy || uncertain"
          @click="ask('cancel')"
        >
          {{ t('education.cancelLesson') }}
        </button>
      </nav>
      <p
        v-if="data.lesson.status !== 'scheduled'"
        class="edu-subtle"
      >
        {{ t('education.lessonLocked') }}
      </p>
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
              v-if="canMarkAttendance"
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
      <template v-if="data.results">
        <h2>{{ t('education.results') }}</h2>
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
        <p
          v-if="data.lesson.status === 'scheduled'"
          class="edu-subtle"
        >
          {{ t('education.resultsNotCompleted') }}
        </p>
        <p
          v-if="data.lesson.status === 'cancelled'"
          class="edu-subtle"
        >
          {{ t('education.resultsCancelled') }}
        </p>
        <template v-if="data.lesson.status === 'completed'">
          <form
            class="edu-form edu-card"
            @submit.prevent="saveReport"
          >
            <h3>{{ t('education.lessonReport') }}</h3>
            <AppInput
              id="education-report-topic"
              v-model="reportDraft.topic"
              :label="t('education.reportTopic')"
              :maxlength="240"
              required
              :disabled="busy || uncertain"
              @update:model-value="dirtyReport = true"
            />
            <label>
              {{ t('education.reportSummary') }}
              <textarea
                v-model="reportDraft.summary"
                :maxlength="4000"
                required
                :disabled="busy || uncertain"
                @input="dirtyReport = true"
              />
            </label>
            <label>
              {{ t('education.teacherComment') }}
              <textarea
                v-model="reportDraft.teacherComment"
                :maxlength="4000"
                :disabled="busy || uncertain"
                @input="dirtyReport = true"
              />
            </label>
            <div class="edu-form-actions">
              <button
                type="submit"
                class="edu-button edu-button--primary"
                :disabled="busy || uncertain"
              >
                {{ t('education.saveReport') }}
              </button>
            </div>
          </form>
          <h3>{{ t('education.students') }}</h3>
          <EducationState
            v-if="!data.results.items.length"
            :empty="t('education.noLessonResults')"
          />
          <ul
            v-else
            class="edu-list"
          >
            <li
              v-for="person in data.results.items"
              :key="person.id"
              class="edu-list__item"
              data-result-student
            >
              <div>
                <strong>{{ person.name }}</strong>
                <div class="edu-form-actions">
                  <AppDropdown
                    :id="`education-result-${person.id}`"
                    :label="`${t('education.results')}: ${person.name}`"
                    :model-value="
                      resultDrafts.get(draftKey(data.lesson.id, person.id))?.status ?? person.status
                    "
                    :options="
                      resultStatuses.map((value) => ({
                        value,
                        label: t(`education.${resultStatusKey(value)}`),
                      }))
                    "
                    :disabled="busy || uncertain"
                    @update:model-value="
                      (value) => {
                        const key = draftKey(data!.lesson!.id, person.id);
                        dirtyResults.add(key);
                        resultDrafts.set(key, {
                          status: String(value) as ResultStatus,
                          comment: resultDrafts.get(key)?.comment ?? person.comment ?? '',
                        });
                      }
                    "
                  />
                  <label class="edu-subtle">
                    {{ t('education.resultComment') }}
                    <textarea
                      :value="
                        resultDrafts.get(draftKey(data.lesson.id, person.id))?.comment ??
                        person.comment ??
                        ''
                      "
                      :maxlength="1000"
                      :disabled="busy || uncertain"
                      @input="
                        (event) => {
                          const key = draftKey(data!.lesson!.id, person.id);
                          dirtyResults.add(key);
                          const draft = resultDrafts.get(key) ?? {
                            status: person.status,
                            comment: '',
                          };
                          draft.comment = String((event.target as HTMLTextAreaElement).value);
                          resultDrafts.set(key, draft);
                        }
                      "
                    />
                  </label>
                  <button
                    type="button"
                    class="edu-button"
                    :disabled="busy || uncertain"
                    @click="saveResult(person.id)"
                  >
                    {{ t('education.saveResult') }}
                  </button>
                </div>
              </div>
            </li>
          </ul>
        </template>
        <ul
          v-if="data.lesson.status === 'cancelled' && data.results.items.length"
          class="edu-list"
        >
          <li
            v-for="person in data.results.items"
            :key="person.id"
            class="edu-list__item"
          >
            <strong>{{ person.name }}</strong>
            <span>
              {{
                t(
                  `education.${person.status === 'pending' ? 'notAssessed' : person.status === 'needs_support' ? 'needsSupport' : person.status}`,
                )
              }}
              <template v-if="person.comment">· {{ person.comment }}</template>
            </span>
          </li>
        </ul>
      </template>
      <template v-if="data.materials">
        <RouterLink
          v-if="data.lesson.status !== 'cancelled'"
          :to="`/materials/upload?lesson=${data.lesson.id}`"
          class="edu-button"
        >
          {{ t('education.uploadMaterials') }}
        </RouterLink>
        <p
          v-if="data.lesson.status === 'completed'"
          class="edu-subtle"
        >
          {{ t('education.materialLocked') }}
        </p>
        <MaterialList
          :page="data.materials"
          :deletable="data.lesson.status !== 'completed'"
          @more="(value) => (offset = value)"
          @refresh="refresh"
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
    <AppConfirmDialog
      v-model="confirmOpen"
      :title="
        pendingAction === 'cancel' ? t('education.cancelLesson') : t('education.completeLesson')
      "
      :description="
        pendingAction === 'cancel' ? t('education.cancelConfirm') : t('education.completeConfirm')
      "
      :confirm-label="
        pendingAction === 'cancel' ? t('education.cancelLesson') : t('education.completeLesson')
      "
      :cancel-label="t('education.cancel')"
      :busy="busy"
      :danger="pendingAction === 'cancel'"
      @confirm="confirmStatus"
    >
      <p
        v-if="actionError"
        class="edu-error"
        role="alert"
      >
        {{ uncertain ? t('education.uncertain') : issue(actionError) }}
      </p>
    </AppConfirmDialog>
  </section>
</template>
