<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import { formatCalendarDate, formatZonedTime } from '@/shared/formatting/calendar';
import { educationApi } from './api';
import { useEducationWorkspace } from './workspace';
import { useEducationResource } from './resources';
import { useEducationI18n } from './i18n';
import EducationState from './EducationState.vue';
import HomeworkSummary from './homework/HomeworkSummary.vue';
const route = useRoute();
const workspace = useEducationWorkspace();
const { t, locale } = useEducationI18n();
const id = computed(() => String(route.params.id ?? ''));
const offset = ref(0);
const from = ref('');
const to = ref('');
const studentId = ref('');
const periodInvalid = computed(() => !!from.value && !!to.value && from.value > to.value);
const filters = computed(() => ({
  offset: offset.value,
  ...(from.value && to.value ? { from: from.value, to: to.value } : {}),
  ...(studentId.value ? { studentId: studentId.value } : {}),
}));
const { data, loading, error, refresh } = useEducationResource(
  async (org, signal) => {
    const [group, roster, journal] = await Promise.all([
      educationApi.group(org, id.value, signal),
      educationApi.groupStudents(org, id.value, 0, signal),
      educationApi.journal(org, id.value, filters.value, signal),
    ]);
    return { group, roster, journal };
  },
  [id, filters],
);
watch(
  () => [from.value, to.value, studentId.value],
  () => {
    offset.value = 0;
  },
);
function time(value: string): string {
  return formatZonedTime(value, locale.value, workspace.header!.timezone);
}
function date(value: string): string {
  return formatCalendarDate(
    new Intl.DateTimeFormat('en-CA', {
      timeZone: workspace.header!.timezone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).format(new Date(value)),
    locale.value,
    { dateStyle: 'long' },
  );
}
function statusLabel(status: string): string {
  return t(
    `education.${status === 'pending' ? 'notAssessed' : status === 'needs_support' ? 'needsSupport' : status}`,
  );
}
</script>
<template>
  <section>
    <HomeworkSummary
      :group-id="id"
      :student-id="studentId || undefined"
    />
    <header class="edu-page-title">
      <h1>{{ data?.group.name ?? t('education.journal') }} · {{ t('education.journal') }}</h1>
      <RouterLink
        :to="`/groups/${id}`"
        class="edu-link"
      >
        {{ t('education.group') }}
      </RouterLink>
    </header>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <form
      v-if="data"
      class="edu-form edu-card"
      @submit.prevent="refresh"
    >
      <div class="edu-form-actions">
        <AppInput
          id="education-journal-from"
          v-model="from"
          type="date"
          :label="t('education.periodFrom')"
          :error="periodInvalid ? t('education.invalidTime') : undefined"
        />
        <AppInput
          id="education-journal-to"
          v-model="to"
          type="date"
          :label="t('education.periodTo')"
          :error="periodInvalid ? t('education.invalidTime') : undefined"
        />
        <AppDropdown
          id="education-journal-student"
          v-model="studentId"
          :label="t('education.students')"
          :options="[
            { value: '', label: t('education.allStudents') },
            ...data.roster.items.map((student) => ({
              value: student.id,
              label: student.name ?? student.id,
            })),
          ]"
        />
        <button
          type="submit"
          class="edu-button"
          :disabled="periodInvalid"
        >
          {{ t('education.refresh') }}
        </button>
      </div>
    </form>
    <template v-if="data">
      <EducationState
        v-if="!data.journal.items.length"
        :empty="from || to ? t('education.noJournalPeriod') : t('education.noJournal')"
      />
      <ul
        v-else
        class="edu-list"
      >
        <li
          v-for="entry in data.journal.items"
          :key="entry.id"
          class="edu-list__item edu-journal__entry"
        >
          <div class="edu-list__main">
            <strong>{{ date(entry.startsAt) }} · {{ entry.title }}</strong>
            <p class="edu-subtle">
              {{ time(entry.startsAt) }} – {{ time(entry.endsAt) }}
              <template v-if="entry.report">· {{ entry.report.topic }}</template>
            </p>
            <p v-if="entry.report">
              {{ entry.report.summary }}
            </p>
            <p
              v-if="entry.report?.teacherComment"
              class="edu-subtle"
            >
              {{ t('education.teacherComment') }}: {{ entry.report.teacherComment }}
            </p>
            <ul class="edu-journal__results">
              <li
                v-for="result in entry.results"
                :key="result.studentId"
              >
                <span>{{ result.name }}:</span>
                <span
                  class="edu-status"
                  :class="`edu-status--${result.status === 'needs_support' ? 'cancelled' : result.status}`"
                >
                  {{ statusLabel(result.status) }}
                </span>
                <span
                  v-if="result.comment"
                  class="edu-subtle"
                >
                  {{ result.comment }}
                </span>
              </li>
            </ul>
          </div>
          <RouterLink
            :to="`/lessons/${entry.id}/results`"
            class="edu-button"
          >
            {{ t('education.openLesson') }}
          </RouterLink>
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
          v-if="data.journal.nextOffset !== null"
          type="button"
          class="edu-button"
          @click="offset = data.journal.nextOffset ?? 0"
        >
          {{ t('education.loadMore') }}
        </button>
      </div>
    </template>
  </section>
</template>
