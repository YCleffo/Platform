<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import { Calendar, Home, People, Message, LayoutGrid, Book, Bell } from '@/shared/ui/icons';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import { useEducationWorkspace } from './workspace';
import { useEducationI18n } from './i18n';
import EducationState from './EducationState.vue';
import { educationApi } from './api';
import { calendarWeek } from '@/shared/formatting/calendar';
import './education.css';
const workspace = useEducationWorkspace();
const auth = useAdminAuthStore();
const route = useRoute();
const router = useRouter();
const { t } = useEducationI18n();
const navigation = [
  { path: '/today', key: 'today', icon: Home, routeName: 'education-today' },
  { path: '/calendar', key: 'calendar', icon: Calendar, routeName: 'education-calendar' },
  { path: '/people', key: 'people', icon: People, routeName: 'education-people' },
  { path: '/messages', key: 'messages', icon: Message, routeName: 'education-messages' },
  { path: '/more', key: 'more', icon: LayoutGrid, routeName: 'education-more' },
];
const currentOrganization = computed(() =>
  workspace.context?.organizations.find((item) => item.id === workspace.organizationId),
);
const workspaceOrganizations = computed(
  () => workspace.context?.organizations.filter((item) => item.teacher || item.student) ?? [],
);
const studentPortal = computed(
  () => route.path === '/student' || route.path.startsWith('/student/'),
);
watch(
  () => workspace.context,
  (context) => {
    if (
      !studentPortal.value &&
      context &&
      !context.organizations.some((item) => item.teacher || item.student) &&
      context.organizations.some((item) => item.parent)
    )
      void router.replace('/parent/home');
  },
);
const sectionLabel = computed(() =>
  route.path.startsWith('/programs')
    ? t('education.programs')
    : t(`education.${String(route.meta.educationTab ?? 'today')}`),
);
const mobileSection = computed(() =>
  route.meta.educationTab === 'homework' ? 'more' : route.meta.educationTab,
);
watch(
  () => auth.isAuthenticated,
  (value) => {
    if (!value)
      void router.replace({ name: 'education-login', query: { redirect: route.fullPath } });
  },
);
async function prefetch(path: string): Promise<void> {
  const org = workspace.organizationId;
  const date = workspace.header?.date;
  if (!org || !date) return;
  const week = calendarWeek(date);
  try {
    if (path === '/calendar') await educationApi.schedule(org, week[0]!, week[6]!);
    if (path === '/people')
      await Promise.all([educationApi.students(org), educationApi.groups(org)]);
    if (path === '/programs') await educationApi.programs(org);
  } catch {
    /* The destination displays and retries its own request errors. */
  }
}
let timer: ReturnType<typeof setInterval> | undefined;
watch(studentPortal, (student) => {
  if (student) workspace.cancelRequests();
  else void workspace.loadContext();
});
onMounted(() => {
  if (!studentPortal.value) void workspace.loadContext();
  timer = setInterval(() => {
    if (!studentPortal.value && !document.hidden) void workspace.refreshHeader();
  }, 60000);
});
onBeforeUnmount(() => {
  if (timer) clearInterval(timer);
  workspace.cancelRequests();
});
</script>
<template>
  <div
    class="education-shell"
    :class="{ 'education-shell--student': studentPortal }"
  >
    <a
      class="edu-skip"
      href="#education-main"
    >
      {{ t('education.skipToContent') }}
    </a>
    <aside
      v-if="!studentPortal"
      class="edu-sidebar"
    >
      <RouterLink
        to="/today"
        class="edu-sidebar__brand"
      >
        <Book
          :size="25"
          aria-hidden="true"
        />
        <span>
          {{ $t('app.name') }}
          <strong>Education</strong>
        </span>
      </RouterLink>
      <span class="edu-sidebar__caption">{{ t('education.workspace') }}</span>
      <nav
        :aria-label="t('education.workspace')"
        class="edu-sidebar__nav"
      >
        <RouterLink
          to="/homework"
          :aria-current="route.meta.educationTab === 'homework' ? 'page' : undefined"
        >
          <Book
            :size="21"
            aria-hidden="true"
          />
          <span>{{ t('education.homework') }}</span>
        </RouterLink>
        <RouterLink
          v-for="item in navigation.slice(0, 3)"
          :key="item.key"
          :to="item.path"
          :aria-current="route.meta.educationTab === item.key ? 'page' : undefined"
          @pointerenter="prefetch(item.path)"
          @focus="prefetch(item.path)"
        >
          <component
            :is="item.icon"
            :size="21"
            aria-hidden="true"
          />
          <span>{{ t(`education.${item.key}`) }}</span>
        </RouterLink>
        <RouterLink
          to="/programs"
          :aria-current="route.path.startsWith('/programs') ? 'page' : undefined"
          @pointerenter="prefetch('/programs')"
          @focus="prefetch('/programs')"
        >
          <Book
            :size="21"
            aria-hidden="true"
          />
          <span>{{ t('education.programs') }}</span>
        </RouterLink>
        <RouterLink
          v-for="item in navigation.slice(3)"
          :key="item.key"
          :to="item.path"
          :aria-current="
            route.meta.educationTab === item.key && !route.path.startsWith('/programs')
              ? 'page'
              : undefined
          "
        >
          <component
            :is="item.icon"
            :size="21"
            aria-hidden="true"
          />
          <span>{{ t(`education.${item.key}`) }}</span>
        </RouterLink>
      </nav>
      <div class="edu-sidebar__footer">
        <RouterLink
          to="/education/contexts"
          class="edu-link"
        >
          {{ t('education.changeContext') }}
        </RouterLink>
        <strong>{{ workspace.header?.displayName ?? workspace.context?.displayName }}</strong>
        <button
          type="button"
          class="edu-link"
          @click="auth.logout()"
        >
          {{ t('education.signOut') }}
        </button>
      </div>
    </aside>
    <div class="edu-workspace">
      <header class="edu-topbar">
        <div class="edu-topbar__context">
          <RouterLink
            to="/education/contexts"
            class="edu-link"
          >
            {{ t('education.changeContext') }}
          </RouterLink>
          <strong>{{ studentPortal ? t('education.studentCabinet') : sectionLabel }}</strong>
        </div>
        <AppDropdown
          v-if="!studentPortal && workspaceOrganizations.length > 1"
          id="education-organization"
          :label="t('education.organization')"
          :model-value="workspace.organizationId"
          :options="workspaceOrganizations.map((item) => ({ value: item.id, label: item.name }))"
          @update:model-value="(value) => workspace.chooseOrganization(String(value))"
        />
        <span
          v-else-if="!studentPortal"
          class="edu-topbar__organization"
        >
          {{ currentOrganization?.name }}
        </span>
        <RouterLink
          v-if="workspace.header && !studentPortal"
          to="/notifications"
          class="edu-icon-button"
          :aria-label="`${t('education.notifications')}: ${workspace.header.unreadCount}`"
        >
          <Bell
            :size="20"
            aria-hidden="true"
          />
          <span
            v-if="workspace.header.unreadCount"
            class="edu-dot"
          >
            {{ Math.min(workspace.header.unreadCount, 99) }}
          </span>
        </RouterLink>
        <button
          v-if="studentPortal"
          type="button"
          class="edu-button"
          @click="auth.logout()"
        >
          {{ t('education.signOut') }}
        </button>
      </header>
      <main
        id="education-main"
        class="edu-main"
        tabindex="-1"
      >
        <RouterView v-if="studentPortal" />
        <EducationState
          v-else-if="workspace.loading || workspace.error"
          :loading="workspace.loading"
          :error="workspace.error"
          @retry="workspace.loadContext"
        />
        <template v-else-if="workspace.context">
          <div
            v-if="!workspace.context.organizations.length"
            class="edu-state"
          >
            <h1>{{ t('education.noOrganizations') }}</h1>
            <p>{{ t('education.noOrganizationsHint') }}</p>
            <button
              type="button"
              class="edu-button"
              @click="auth.logout()"
            >
              {{ t('education.signOut') }}
            </button>
          </div>
          <EducationState
            v-else-if="!workspace.organizationId"
            :empty="t('education.chooseOrganization')"
          />
          <EducationState
            v-else-if="!workspace.header"
            :loading="!workspace.headerError"
            :error="workspace.headerError"
            @retry="workspace.refreshHeader()"
          />
          <template v-else>
            <p
              v-if="workspace.headerError"
              class="edu-error"
              role="status"
            >
              {{ t('education.refreshFailed') }}
              <button
                type="button"
                class="edu-link"
                @click="workspace.refreshHeader()"
              >
                {{ t('education.retry') }}
              </button>
            </p>
            <RouterView :key="`${workspace.revision}:${route.path}`" />
          </template>
        </template>
      </main>
    </div>
    <nav
      v-if="!studentPortal"
      class="edu-navigation"
      :aria-label="t('education.workspace')"
    >
      <RouterLink
        v-for="item in navigation"
        :key="item.key"
        :to="item.path"
        class="edu-navigation__link"
        :class="{ 'edu-navigation__link--active': mobileSection === item.key }"
        :aria-current="mobileSection === item.key ? 'page' : undefined"
      >
        <span class="edu-navigation__icon">
          <component
            :is="item.icon"
            :size="23"
            aria-hidden="true"
          />
          <span
            v-if="item.key === 'messages' && workspace.header?.unreadCount"
            class="edu-dot"
            aria-hidden="true"
          >
            {{ Math.min(workspace.header.unreadCount, 99) }}
          </span>
        </span>
        <span>{{ t(`education.${item.key}`) }}</span>
      </RouterLink>
    </nav>
  </div>
</template>
