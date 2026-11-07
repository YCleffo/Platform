<script setup lang="ts">
import { computed, watch } from 'vue';
import { useEducationI18n } from '../i18n';
import { useEducationResource } from '../resources';
import { educationApi } from '../api';
import { studentSession } from './session';
import EducationState from '../EducationState.vue';

const { t } = useEducationI18n();
const { data, loading, error, refresh } = useEducationResource(
  async (_org, signal) => educationApi.context(signal),
  [],
);
const studentOrganizations = computed(() => (data.value?.organizations ?? []).filter(org => org.student));
const current = computed(() => studentOrganizations.value[0] ?? null);
watch(
  [data, current],
  ([context, org]) => {
    if (context) studentSession.displayName = context.displayName;
    studentSession.organizationId = org?.id ?? '';
  },
  { immediate: true },
);
</script>
<template>
  <div class="edu-student">
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <template v-if="current">
      <header class="edu-page-title">
        <h1>{{ t('education.studentCabinet') }}</h1>
        <RouterLink
          to="/admin/profile"
          class="edu-link"
        >
          {{ t('education.profile') }}
        </RouterLink>
      </header>
      <p class="edu-subtle">{{ data?.displayName ?? '' }} · {{ current.name }}</p>
      <RouterView />
    </template>
    <template v-else-if="!loading && !error">
      <EducationState :empty="t('education.noStudentOrganization')" />
      <p>
        <RouterLink
          to="/education/student-activate"
          class="edu-button"
        >
          {{ t('education.activateStudentAccount') }}
        </RouterLink>
      </p>
    </template>
    <nav
      v-if="current"
      class="edu-bottom-nav"
    >
      <RouterLink to="/student/home">{{ t('education.studentHome') }}</RouterLink>
      <RouterLink to="/student/diary">{{ t('education.myDiary') }}</RouterLink>
      <RouterLink to="/student/activities">{{ t('education.activities') }}</RouterLink>
      <RouterLink to="/student/progress">{{ t('education.myProgress') }}</RouterLink>
      <RouterLink to="/student/documents">{{ t('education.documents') }}</RouterLink>
    </nav>
  </div>
</template>
