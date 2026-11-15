<script setup lang="ts">
import { computed, onScopeDispose, ref, shallowRef, watch } from 'vue';
import { useEducationI18n } from '../i18n';
import { coreAdminSession, coreAdminSessionEpoch } from '@/core/admin/session';
import type { EducationContext } from '../api';
import { educationApi } from '../api';
import { studentSession } from './session';
import EducationState from '../EducationState.vue';
import AppBottomNav from '@/shared/ui/navigation/AppBottomNav.vue';
import { Home, Calendar, LayoutGrid, CircleCheck, Backpack } from '@/shared/ui/icons';

const { t } = useEducationI18n();
const navigation = computed(() => [
  { label: t('education.studentHome'), icon: Home, routeName: 'education-student-home' },
  { label: t('education.myDiary'), icon: Calendar, routeName: 'education-student-diary' },
  { label: t('education.homework'), icon: LayoutGrid, routeName: 'education-student-homework' },
  { label: t('education.myProgress'), icon: CircleCheck, routeName: 'education-student-progress' },
  { label: t('education.documents'), icon: Backpack, routeName: 'education-student-documents' },
]);
const data = shallowRef<EducationContext | null>(null),
  loading = ref(false),
  error = shallowRef<unknown>(null);
let controller: AbortController | null = null;
async function refresh(): Promise<void> {
  controller?.abort();
  const request = new AbortController();
  controller = request;
  const epoch = coreAdminSession.epoch();
  data.value = null;
  error.value = null;
  loading.value = !!coreAdminSession.accessToken();
  if (!coreAdminSession.accessToken()) return;
  try {
    const context = await educationApi.context(request.signal);
    if (!request.signal.aborted && epoch === coreAdminSession.epoch()) data.value = context;
  } catch (failure) {
    if (!request.signal.aborted && epoch === coreAdminSession.epoch()) error.value = failure;
  } finally {
    if (!request.signal.aborted && epoch === coreAdminSession.epoch()) loading.value = false;
  }
}
watch(
  coreAdminSessionEpoch,
  () => {
    studentSession.organizationId = '';
    studentSession.displayName = null;
    void refresh();
  },
  { immediate: true, flush: 'sync' },
);
onScopeDispose(() => {
  controller?.abort();
  studentSession.organizationId = '';
  studentSession.displayName = null;
});
const studentOrganizations = computed(() =>
  (data.value?.organizations ?? []).filter((org) => org.student),
);
const current = computed(() => studentOrganizations.value[0] ?? null);
watch(
  [data, current],
  ([context, org]) => {
    studentSession.displayName = context?.displayName ?? null;
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
          to="/student/notifications"
          class="edu-link"
        >
          {{ t('education.notifications') }}
        </RouterLink>
        <RouterLink
          to="/admin/profile"
          class="edu-link"
        >
          {{ t('education.profile') }}
        </RouterLink>
      </header>
      <p class="edu-subtle">{{ data?.displayName ?? '' }} · {{ current.name }}</p>
      <nav
        class="edu-student-shortcuts"
        :aria-label="t('education.activities')"
      >
        <RouterLink
          to="/student/activities"
          class="edu-button"
        >
          {{ t('education.activities') }}
        </RouterLink>
        <RouterLink
          to="/student/achievements"
          class="edu-button"
        >
          {{ t('education.achievements') }}
        </RouterLink>
      </nav>
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
      class="edu-student-tabs edu-pills"
    >
      <RouterLink to="/student/home">{{ t('education.studentHome') }}</RouterLink>
      <RouterLink to="/student/diary">{{ t('education.myDiary') }}</RouterLink>
      <RouterLink to="/student/homework">{{ t('education.myHomework') }}</RouterLink>
      <RouterLink to="/student/activities">{{ t('education.activities') }}</RouterLink>
      <RouterLink to="/student/progress">{{ t('education.myProgress') }}</RouterLink>
      <RouterLink to="/student/achievements">{{ t('education.achievements') }}</RouterLink>
      <RouterLink to="/student/documents">{{ t('education.documents') }}</RouterLink>
    </nav>
    <AppBottomNav
      v-if="current"
      :items="navigation"
    />
  </div>
</template>
<style scoped>
.edu-student-tabs {
  display: none;
}
.edu-student-shortcuts {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: 12px 0;
}
@media (min-width: 768px) {
  .edu-student-shortcuts {
    display: none;
  }
  .edu-student-tabs {
    display: flex;
    margin-top: 24px;
    width: fit-content;
  }
}
</style>
