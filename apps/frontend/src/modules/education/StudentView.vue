<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import { formatCalendarDate, formatZonedTime } from '@/shared/formatting/calendar';
import { educationApi } from './api';
import { useEducationWorkspace } from './workspace';
import { useEducationResource } from './resources';
import { useEducationI18n } from './i18n';
import EducationState from './EducationState.vue';
const route = useRoute();
const workspace = useEducationWorkspace();
const { t, locale } = useEducationI18n();
const id = computed(() => String(route.params.id ?? ''));
const attendanceOffset = ref(0);
const resultsOffset = ref(0);
const from = ref('');
const to = ref('');
const periodInvalid = computed(
  () => !!from.value && !!to.value && from.value > to.value,
);
const period = computed(() => ({ from: from.value, to: to.value }));
const { data, loading, error, refresh } = useEducationResource(
  async (org, signal) => {
    const card = await educationApi.student(org, id.value, signal);
    const [results, attendance, progress] = await Promise.all([
      educationApi.studentResults(org, id.value, resultsOffset.value, signal),
      from.value && to.value && !periodInvalid.value
        ? educationApi.studentAttendance(org, id.value, from.value, to.value, attendanceOffset.value, signal)
        : null,
      from.value && to.value && !periodInvalid.value
        ? educationApi.studentProgress(org, id.value, from.value, to.value, signal)
        : null,
    ]);
    return { card, results, attendance, progress };
  },
  [id, attendanceOffset, resultsOffset, period],
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
    { dateStyle: 'medium' },
  );
}
function statusLabel(status: string): string {
  return t(
    `education.${status === 'pending' ? 'notAssessed' : status === 'needs_support' ? 'needsSupport' : status}`,
  );
}
const busy = ref(false);
const inviteCode = ref('');
async function invite(): Promise<void> {
  busy.value = true;
  try {
    const result = await educationApi.inviteStudent(workspace.organizationId, id.value);
    inviteCode.value = result.code;
  } finally {
    busy.value = false;
  }
}
async function revoke(): Promise<void> {
  busy.value = true;
  try {
    await educationApi.revokeStudentAccount(workspace.organizationId, id.value);
    await refresh();
  } finally {
    busy.value = false;
  }
}
</script>
<template>
  <section>
    <header class="edu-page-title">
      <h1>{{ data?.card.name ?? t('education.studentCard') }}</h1>
      <RouterLink
        to="/people"
        class="edu-link"
      >
        {{ t('education.students') }}
      </RouterLink>
    </header>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <template v-if="data">
      <article class="edu-card">
        <h2>{{ t('education.groups') }}</h2>
        <ul class="edu-list">
          <li
            v-for="group in data.card.groups"
            :key="group.id"
            class="edu-list__item"
          >
            <RouterLink
              :to="`/groups/${group.id}`"
              class="edu-list__main"
            >
              <strong>{{ group.name }}</strong>
              <p
                v-if="group.archivedAt"
                class="edu-subtle"
              >
                {{ t('education.archived') }}
              </p>
            </RouterLink>
            <RouterLink
              :to="`/groups/${group.id}/journal`"
              class="edu-button"
            >
              {{ t('education.journal') }}
            </RouterLink>
          </li>
        </ul>
      </article>
      <article class="edu-card">
        <h2>{{ t('education.studentCabinet') }}</h2>
        <p v-if="data.card.account && !data.card.account.revokedAt">{{ t('education.accountActive') }}</p>
        <p
          v-else
          class="edu-subtle"
        >{{ t('education.accountPending') }}</p>
        <div class="edu-form-actions">
          <button
            type="button"
            class="edu-button"
            :disabled="busy"
            @click="invite"
          >
            {{ t('education.inviteStudent') }}
          </button>
          <button
            v-if="data.card.account && !data.card.account.revokedAt"
            type="button"
            class="edu-button"
            :disabled="busy"
            @click="revoke"
          >
            {{ t('education.revokeAccount') }}
          </button>
        </div>
        <p
          v-if="inviteCode"
          class="edu-subtle"
        >
          {{ t('education.generatedInvite') }}: <strong>{{ inviteCode }}</strong>
        </p>
      </article>
      <form
        class="edu-form edu-card"
        @submit.prevent="refresh"
      >
        <div class="edu-form-actions">
          <AppInput
            id="education-student-from"
            v-model="from"
            type="date"
            :label="t('education.periodFrom')"
            :error="periodInvalid ? t('education.invalidTime') : undefined"
          />
          <AppInput
            id="education-student-to"
            v-model="to"
            type="date"
            :label="t('education.periodTo')"
            :error="periodInvalid ? t('education.invalidTime') : undefined"
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
      <article
        v-if="data.progress"
        class="edu-card"
      >
        <h2>{{ t('education.progress') }}</h2>
        <p class="edu-subtle">{{ date(`${data.progress.from}T00:00:00Z`) }} – {{ date(`${data.progress.to}T00:00:00Z`) }}</p>
        <p>{{ t('education.masteredCount', { count: data.progress.results.mastered }) }}</p>
        <p>{{ t('education.developingCount', { count: data.progress.results.developing }) }}</p>
        <p>{{ t('education.needsSupportCount', { count: data.progress.results.needs_support }) }}</p>
        <p>{{ t('education.presentCount', { count: data.progress.attendance.present }) }}</p>
        <p>{{ t('education.absentCount', { count: data.progress.attendance.absent }) }}</p>
      </article>
      <div class="edu-section-title">
        <h2>{{ t('education.attendanceHistory') }}</h2>
      </div>
      <EducationState
        v-if="!data.attendance"
        :empty="t('education.noAttendance')"
      />
      <template v-else-if="data.attendance.items.length">
        <ul class="edu-list">
          <li
            v-for="entry in data.attendance.items"
            :key="entry.lessonId"
            class="edu-list__item"
          >
            <div class="edu-list__main">
              <strong>{{ entry.title }}</strong>
              <p class="edu-subtle">{{ date(entry.startsAt) }} · {{ time(entry.startsAt) }}</p>
            </div>
            <span>{{ t(`education.${entry.attendance === 'absent' ? 'absentStatus' : entry.attendance}`) }}</span>
          </li>
        </ul>
        <div class="edu-form-actions">
          <button
            v-if="attendanceOffset"
            type="button"
            class="edu-button"
            @click="attendanceOffset = Math.max(0, attendanceOffset - 50)"
          >
            {{ t('education.back') }}
          </button>
          <button
            v-if="data.attendance.nextOffset !== null"
            type="button"
            class="edu-button"
            @click="attendanceOffset = data.attendance.nextOffset ?? 0"
          >
            {{ t('education.loadMore') }}
          </button>
        </div>
      </template>
      <EducationState
        v-else
        :empty="t('education.noAttendance')"
      />
      <div class="edu-section-title">
        <h2>{{ t('education.resultsHistory') }}</h2>
      </div>
      <EducationState
        v-if="!data.results.items.length"
        :empty="t('education.noStudentResults')"
      />
      <ul
        v-else
        class="edu-list"
      >
        <li
          v-for="entry in data.results.items"
          :key="entry.id"
          class="edu-list__item"
        >
          <div class="edu-list__main">
            <strong>{{ entry.lessonTitle }}</strong>
            <p class="edu-subtle">{{ date(entry.startsAt) }} · {{ time(entry.startsAt) }}</p>
            <p v-if="entry.comment">{{ entry.comment }}</p>
          </div>
          <span
            class="edu-status"
            :class="`edu-status--${entry.status === 'needs_support' ? 'cancelled' : entry.status}`"
          >
            {{ statusLabel(entry.status) }}
          </span>
        </li>
      </ul>
      <div class="edu-form-actions">
        <button
          v-if="resultsOffset"
          type="button"
          class="edu-button"
          @click="resultsOffset = Math.max(0, resultsOffset - 50)"
        >
          {{ t('education.back') }}
        </button>
        <button
          v-if="data.results.nextOffset !== null"
          type="button"
          class="edu-button"
          @click="resultsOffset = data.results.nextOffset ?? 0"
        >
          {{ t('education.loadMore') }}
        </button>
      </div>
      <div class="edu-section-title">
        <h2>{{ t('education.teacherComments') }}</h2>
      </div>
      <EducationState
        v-if="!data.results.items.some((entry) => entry.comment)"
        :empty="t('education.noComments')"
      />
      <ul
        v-else
        class="edu-list"
      >
        <li
          v-for="entry in data.results.items.filter((item) => item.comment)"
          :key="entry.id"
          class="edu-list__item"
        >
          <div class="edu-list__main">
            <strong>{{ entry.lessonTitle }}</strong>
            <p class="edu-subtle">{{ date(entry.startsAt) }}</p>
            <p>{{ entry.comment }}</p>
          </div>
        </li>
      </ul>
    </template>
  </section>
</template>
