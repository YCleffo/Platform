<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import { useTheme } from '@/app/theme';
import { setLocale } from '@/shared/i18n';
import { useEducationI18n } from '../i18n';
import EducationState from '../EducationState.vue';
import { parentChildKey, useParentWorkspace } from './workspace';
import '../education.css';
import './parent.css';
const workspace = useParentWorkspace();
const auth = useAdminAuthStore();
const route = useRoute();
const router = useRouter();
const { t } = useEducationI18n();
const { isDark, toggleTheme } = useTheme();
const tabs = ['home', 'schedule', 'diary', 'homework', 'progress', 'notifications'] as const;
const choices = computed(
  () =>
    workspace.context?.children.map((child) => ({
      value: parentChildKey(child),
      label: `${child.name} · ${child.organizationName}`,
    })) ?? [],
);
watch(
  [() => workspace.context, () => route.query.child, () => route.query.organization],
  () => {
    const child = typeof route.query.child === 'string' ? route.query.child : '';
    const organization =
      typeof route.query.organization === 'string' ? route.query.organization : '';
    if (child && organization && workspace.context) {
      const key = `${organization}:${child}`;
      if (workspace.context.children.some(item => parentChildKey(item) === key)) workspace.chooseChild(key);
      else workspace.clearSelection();
    }
  },
  { immediate: true, flush: 'sync' },
);
function refreshContext(): void {
  const child = typeof route.query.child === 'string' ? route.query.child : '';
  const organization = typeof route.query.organization === 'string' ? route.query.organization : '';
  void workspace.loadContext(child && organization ? `${organization}:${child}` : undefined);
}
function chooseChild(value: string): void {
  const child = workspace.context?.children.find((item) => parentChildKey(item) === value);
  if (!child) return;
  workspace.chooseChild(value);
  void router.push({
    path: route.path,
    query: { ...route.query, child: child.id, organization: child.organizationId },
  });
}
watch(
  () => auth.isAuthenticated,
  (value) => {
    if (!value)
      void router.replace({ name: 'education-login', query: { redirect: route.fullPath } });
  },
);
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
  refreshContext();
  timer = setInterval(() => {
    if (!document.hidden) refreshContext();
  }, 60000);
});
onBeforeUnmount(() => {
  if (timer) clearInterval(timer);
  workspace.reset();
});
</script>
<template>
  <div class="education-shell education-parent">
    <a
      class="edu-skip"
      href="#parent-main"
    >
      {{ t('education.skipToContent') }}
    </a>
    <header class="edu-parent-topbar">
      <RouterLink
        to="/parent/home"
        class="edu-sidebar__brand"
      >
        {{ $t('app.name') }}
        <strong>Education</strong>
      </RouterLink>
      <div class="edu-form-actions">
        <RouterLink
          to="/education/contexts"
          class="edu-link"
        >
          {{ t('education.changeContext') }}
        </RouterLink>
        <button
          type="button"
          class="edu-link"
          @click="toggleTheme()"
        >
          {{ t(isDark ? 'education.lightTheme' : 'education.darkTheme') }}
        </button>
        <button
          type="button"
          class="edu-link"
          @click="setLocale('ru')"
        >
          RU
        </button>
        <button
          type="button"
          class="edu-link"
          @click="setLocale('en')"
        >
          EN
        </button>
        <button
          type="button"
          class="edu-button"
          @click="auth.logout()"
        >
          {{ t('education.signOut') }}
        </button>
      </div>
    </header>
    <main
      id="parent-main"
      class="edu-main"
      tabindex="-1"
    >
      <header class="edu-page-title">
        <div>
          <h1>{{ t('education.parentCabinet') }}</h1>
          <p class="edu-subtle">{{ workspace.context?.displayName }}</p>
        </div>
        <RouterLink
          to="/parent/activate"
          class="edu-link"
        >
          {{ t('education.activateParentInvitation') }}
        </RouterLink>
      </header>
      <EducationState
        :loading="workspace.loading"
        :error="workspace.error"
        @retry="refreshContext"
      />
      <template v-if="workspace.context">
        <aside
          v-if="workspace.context.notices?.length"
          class="edu-card"
          :aria-label="t('education.familyHistory')"
        >
          <details>
            <summary>{{ t('education.parentAccessNotices') }}</summary>
            <ul class="edu-list">
              <li
                v-for="notice in workspace.context.notices"
                :key="notice.id"
              >
                {{ t(`education.parentNotice_${notice.kind}`) }}
              </li>
            </ul>
          </details>
        </aside>
        <AppDropdown
          v-if="choices.length"
          id="education-parent-child"
          class="edu-parent-child"
          :model-value="workspace.selectedKey"
          :options="choices"
          :label="t('education.selectedChild')"
          @update:model-value="(value) => chooseChild(String(value))"
        />
        <template v-if="workspace.child">
          <p class="edu-subtle">
            {{ workspace.child.organizationName }}
            <span v-if="workspace.child.groups.length">
              · {{ workspace.child.groups.map((group) => group.name).join(', ') }}
            </span>
          </p>
          <nav
            class="edu-parent-nav"
            :aria-label="t('education.parentCabinet')"
          >
            <RouterLink
              v-for="tab in tabs"
              :key="tab"
              :to="{
                path: `/parent/${tab}`,
                query: { child: workspace.child.id, organization: workspace.child.organizationId },
              }"
              :aria-current="route.path === `/parent/${tab}` ? 'page' : undefined"
            >
              {{ t(`education.parent${tab}`) }}
            </RouterLink>
          </nav>
          <RouterView :key="`${workspace.revision}:${workspace.selectedKey}:${route.path}`" />
        </template>
        <EducationState v-else :empty="t(choices.length ? 'education.parentChildUnavailable' : 'education.noParentChildren')" />
      </template>
    </main>
  </div>
</template>
