<script setup lang="ts">
import { computed, onScopeDispose, ref, shallowRef, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useEducationI18n } from '../i18n';
import { coreAdminSession, coreAdminSessionEpoch } from '@/core/admin/session';
import { applyContextLocale } from '@/shared/i18n';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import type { EducationContext } from '../api';
import { educationApi } from '../api';
import { scopeEducationCache } from '../cache';
import { studentSession } from './session';
import EducationState from '../EducationState.vue';
import AppBottomNav from '@/shared/ui/navigation/AppBottomNav.vue';
import { Home, Calendar, LayoutGrid, CircleCheck, Backpack, Message } from '@/shared/ui/icons';
import { activeNavigationRoute } from '@/shared/navigation/activeRoute';

const { t } = useEducationI18n();
const route = useRoute();
const activeSection = computed(() => activeNavigationRoute(route, 'studentNavigation'));
const navigation = computed(() => [
  { label: t('education.studentHome'), icon: Home, routeName: 'education-student-home' },
  { label: t('education.myDiary'), icon: Calendar, routeName: 'education-student-diary' },
  { label: t('education.myHomework'), icon: LayoutGrid, routeName: 'education-student-homework' },
  { label: t('education.messages'), icon: Message, routeName: 'education-student-messages' },
  { label: t('education.myProgress'), icon: CircleCheck, routeName: 'education-student-progress' },
  { label: t('education.documents'), icon: Backpack, routeName: 'education-student-documents' },
]);
const shortcuts = computed(() => [
  { label: t('education.parentschedule'), routeName: 'education-student-schedule' },
  { label: t('education.activities'), routeName: 'education-student-activities' },
  { label: t('education.achievements'), routeName: 'education-student-achievements' },
]);
const data = shallowRef<EducationContext | null>(null),
  loading = ref(false),
  error = shallowRef<unknown>(null);
const selectedOrganization = ref('');
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
    if (!request.signal.aborted && epoch === coreAdminSession.epoch()) {
      data.value = context;
      applyContextLocale(context.locale);
    }
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
    studentSession.timezone = 'UTC';
    studentSession.personId = '';
    selectedOrganization.value = '';
    void refresh();
  },
  { immediate: true, flush: 'sync' },
);
onScopeDispose(() => {
  controller?.abort();
  studentSession.organizationId = '';
  studentSession.displayName = null;
  studentSession.timezone = 'UTC';
  studentSession.personId = '';
});
const studentOrganizations = computed(() =>
  (data.value?.organizations ?? []).filter((org) => org.student),
);
const current = computed(
  () =>
    studentOrganizations.value.find((org) => org.id === selectedOrganization.value) ??
    studentOrganizations.value[0] ??
    null,
);
watch(
  [data, current],
  ([context, org]) => {
    studentSession.displayName = context?.displayName ?? null;
    studentSession.timezone = context?.timezone ?? 'UTC';
    studentSession.personId = context?.personId ?? '';
    if (org) scopeEducationCache(org.id);
    studentSession.organizationId = org?.id ?? '';
  },
  { immediate: true, flush: 'sync' },
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
          :aria-current="activeSection === 'education-student-notifications' ? 'page' : undefined"
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
      <p class="edu-subtle">
        {{ data?.displayName ?? '' }}
        <template v-if="studentOrganizations.length === 1">· {{ current.name }}</template>
      </p>
      <AppDropdown
        v-if="studentOrganizations.length > 1"
        id="education-student-organization"
        :model-value="current.id"
        :options="studentOrganizations.map((org) => ({ value: org.id, label: org.name }))"
        :label="t('education.organization')"
        @update:model-value="(value) => (selectedOrganization = String(value))"
      />
      <nav
        class="edu-student-shortcuts"
        :aria-label="t('education.studentCabinet')"
      >
        <RouterLink
          v-for="item in shortcuts"
          :key="item.routeName"
          :to="{ name: item.routeName }"
          class="edu-button"
          :aria-current="activeSection === item.routeName ? 'page' : undefined"
        >
          {{ item.label }}
        </RouterLink>
      </nav>
      <nav
        class="edu-student-tabs edu-pills"
        :aria-label="t('education.studentCabinet')"
      >
        <RouterLink
          v-for="item in [...navigation, ...shortcuts]"
          :key="item.routeName"
          :to="{ name: item.routeName }"
          :aria-current="activeSection === item.routeName ? 'page' : undefined"
        >
          {{ item.label }}
        </RouterLink>
      </nav>
      <RouterView :key="current.id" />
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
    <AppBottomNav
      v-if="current"
      :items="navigation"
      :active-route-name="activeSection"
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
  gap: var(--gap-s);
  margin: var(--space-12) 0;
}
.edu-student-shortcuts [aria-current='page'] {
  color: var(--accent-text);
  background: var(--accent-soft);
}
@media (min-width: 768px) {
  .edu-student-shortcuts {
    display: none;
  }
  .edu-student-tabs {
    display: flex;
    margin-block: var(--space-16);
    width: fit-content;
    max-width: 100%;
  }
}
</style>
