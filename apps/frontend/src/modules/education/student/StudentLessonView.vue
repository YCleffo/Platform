<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { formatCalendarDate, formatZonedTime } from '@/shared/formatting/calendar';
import { studentPortalApi } from './api';
import { studentSession } from './session';
import { useEducationError, useEducationResource } from '../resources';
import { useEducationDownload } from '../downloads';
import { useEducationI18n } from '../i18n';
import EducationState from '../EducationState.vue';
const route = useRoute();
const { t, locale } = useEducationI18n();
const id = computed(() => String(route.params.id ?? ''));
const org = computed(() => studentSession.organizationId);
const issue = useEducationError();
const {
  busy: downloading,
  error: downloadError,
  download,
} = useEducationDownload(
  () => org.value,
  () => id.value,
);
const { data, loading, error, refresh } = useEducationResource(
  async (_org, signal) => studentPortalApi.lesson(org.value, id.value, signal),
  [org, id],
);
function time(value: string): string {
  return formatZonedTime(value, locale.value, 'UTC');
}
function date(value: string): string {
  return formatCalendarDate(value, locale.value, { dateStyle: 'medium' });
}
</script>
<template>
  <section>
    <header class="edu-page-title">
      <h1>{{ data?.title ?? t('education.lesson') }}</h1>
      <RouterLink
        to="/student/diary"
        class="edu-link"
      >
        {{ t('education.myDiary') }}
      </RouterLink>
    </header>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <template v-if="data">
      <p class="edu-subtle">
        {{ date(data.startsAt) }} · {{ time(data.startsAt) }} – {{ time(data.endsAt) }} ·
        {{ data.groupName }}
      </p>
      <article class="edu-card">
        <h2>{{ t('education.attendanceHistory') }}</h2>
        <p>
          {{ t(`education.${data.attendance === 'absent' ? 'absentStatus' : data.attendance}`) }}
        </p>
      </article>
      <article class="edu-card">
        <h2>{{ t('education.results') }}</h2>
        <p v-if="data.resultStatus !== 'pending'">
          {{
            t(
              `education.${data.resultStatus === 'needs_support' ? 'needsSupport' : data.resultStatus}`,
            )
          }}
        </p>
        <p
          v-if="data.resultComment"
          class="edu-subtle"
        >
          {{ data.resultComment }}
        </p>
        <EducationState
          v-if="data.resultStatus === 'pending' && !data.resultComment"
          :empty="t('education.noLessonResults')"
        />
      </article>
      <article class="edu-card">
        <h2>{{ t('education.materials') }}</h2>
        <p
          v-if="downloadError"
          class="edu-error"
          role="alert"
        >
          {{ issue(downloadError) }}
        </p>
        <EducationState
          v-if="!data.materials.length"
          :empty="t('education.noMaterials')"
        />
        <ul
          v-else
          class="edu-list"
        >
          <li
            v-for="material in data.materials"
            :key="material.id"
            class="edu-list__item"
          >
            <strong>{{ material.name }}</strong>
            <span>{{ material.sizeBytes }}</span>
            <button
              type="button"
              class="edu-button"
              :disabled="!!downloading"
              @click="download(material)"
            >
              {{ t(downloading === material.id ? 'education.loading' : 'education.download') }}
            </button>
          </li>
        </ul>
      </article>
    </template>
  </section>
</template>
