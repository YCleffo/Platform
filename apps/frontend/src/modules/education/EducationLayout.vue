<script setup lang="ts">
import { onBeforeUnmount, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import { Calendar, Home, People, Message, LayoutGrid } from '@/shared/ui/icons';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import { useEducationWorkspace } from './workspace';
import { useEducationI18n } from './i18n';
import EducationState from './EducationState.vue';
import './education.css';
const workspace = useEducationWorkspace();
const auth = useAdminAuthStore();
const route = useRoute();
const router = useRouter();
const { t } = useEducationI18n();
const navigation = [
  { path: '/today', key: 'today', icon: Home },
  { path: '/calendar', key: 'calendar', icon: Calendar },
  { path: '/people', key: 'people', icon: People },
  { path: '/messages', key: 'messages', icon: Message },
  { path: '/more', key: 'more', icon: LayoutGrid },
];
watch(
  () => auth.isAuthenticated,
  (value) => {
    if (!value)
      void router.replace({ name: 'education-login', query: { redirect: route.fullPath } });
  },
);
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
  void workspace.loadContext();
  timer = setInterval(() => {
    if (!document.hidden) void workspace.refreshHeader();
  }, 60000);
});
onBeforeUnmount(() => {
  if (timer) clearInterval(timer);
});
</script>
<template>
  <div class="education-shell">
    <a
      class="edu-skip"
      href="#education-main"
    >
      {{ t('education.today') }}
    </a>
    <main
      id="education-main"
      class="edu-main"
    >
      <EducationState
        v-if="workspace.loading || workspace.error"
        :loading="workspace.loading"
        :error="workspace.error"
        @retry="workspace.loadContext"
      />
      <template v-else-if="workspace.context">
        <div
          v-if="workspace.context.organizations.length > 1"
          class="edu-organization"
        >
          <AppDropdown
            id="education-organization"
            :label="t('education.organization')"
            :model-value="workspace.organizationId"
            :options="
              workspace.context.organizations.map((item) => ({ value: item.id, label: item.name }))
            "
            @update:model-value="(value) => workspace.chooseOrganization(String(value))"
          />
        </div>
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
          @retry="workspace.refreshHeader"
        />
        <RouterView
          v-else
          :key="`${workspace.revision}:${route.path}`"
        />
      </template>
    </main>
    <nav
      class="edu-navigation"
      :aria-label="t('education.today')"
    >
      <RouterLink
        v-for="item in navigation"
        :key="item.key"
        :to="item.path"
        class="edu-navigation__link"
        :class="{ 'edu-navigation__link--active': route.meta.educationTab === item.key }"
        :aria-current="route.meta.educationTab === item.key ? 'page' : undefined"
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
