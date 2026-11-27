<script setup lang="ts">
import { computed, getCurrentInstance, ref } from 'vue';
import type { RouteLocationRaw, Router } from 'vue-router';
import { Bell, ChevronLeft, Clock, Command, Layers, Search, Trash2 } from '@/shared/ui/icons';
import { i18n } from '@/shared/i18n';
import AppIconButton from '@/shared/ui/actions/AppIconButton.vue';
import { useOperationsCenter } from './useOperationsCenter';

const props = withDefaults(
  defineProps<{
    showSearchInput?: boolean;
    showSearchLink?: boolean;
    showNotifications?: boolean;
    showWorkspaceLinks?: boolean;
    showBack?: boolean;
    notificationsTo?: RouteLocationRaw;
    unreadCount?: number;
  }>(),
  {
    showSearchInput: false,
    showSearchLink: true,
    showNotifications: false,
    showWorkspaceLinks: false,
    showBack: false,
    notificationsTo: () => ({ name: 'universal-notifications' }),
    unreadCount: 0,
  },
);

const vm = getCurrentInstance();
const operations = useOperationsCenter();
const quickQuery = ref('');

function t(key: string): string {
  return String(i18n.global.t(key));
}

function getRouter(): Router | undefined {
  return vm?.appContext.config.globalProperties.$router as Router | undefined;
}

const canGoBack = computed(
  () => props.showBack && typeof window !== 'undefined' && window.history.length > 1,
);

function goBack(): void {
  const router = getRouter();
  if (router) {
    router.back();
  } else if (typeof window !== 'undefined') {
    window.history.back();
  }
}

function submitQuickSearch(): void {
  const q = quickQuery.value.trim();
  const router = getRouter();
  if (router) {
    void router.push(q ? { name: 'search', query: { q } } : { name: 'search' });
  }
  quickQuery.value = '';
}
</script>

<template>
  <div
    class="ws-shell-controls"
    data-testid="workspace-shell-controls"
  >
    <AppIconButton
      v-if="canGoBack"
      :label="t('nav.back')"
      data-testid="shell-back-btn"
      @click="goBack"
    >
      <ChevronLeft
        :size="18"
        aria-hidden="true"
      />
    </AppIconButton>

    <form
      v-if="showSearchInput"
      class="ws-shell-controls__search"
      role="search"
      @submit.prevent="submitQuickSearch"
    >
      <Search
        :size="15"
        class="ws-shell-controls__search-icon"
        aria-hidden="true"
      />
      <input
        v-model="quickQuery"
        type="search"
        class="ws-shell-controls__search-input"
        :placeholder="t('workspace.searchPlaceholder')"
        :aria-label="t('nav.search')"
        data-testid="shell-search-input"
      />
    </form>

    <RouterLink
      v-if="showSearchLink"
      :to="{ name: 'search' }"
      class="ws-shell-controls__icon-link"
      :aria-label="t('nav.search')"
      :title="t('nav.search')"
      data-testid="shell-search-link"
    >
      <Search
        :size="18"
        aria-hidden="true"
      />
    </RouterLink>

    <button
      type="button"
      class="ws-shell-controls__palette-btn"
      :aria-label="`${t('nav.commandPalette')} (${t('workspace.shortcutHint')})`"
      :title="`${t('nav.commandPalette')} (${t('workspace.shortcutHint')})`"
      data-testid="command-palette-trigger"
      @click="operations.commandPaletteOpen = true"
    >
      <Command
        :size="16"
        aria-hidden="true"
      />
      <span class="ws-shell-controls__kbd">{{ t('workspace.shortcutHint') }}</span>
    </button>

    <button
      type="button"
      class="ws-shell-controls__icon-btn"
      :aria-label="`${t('nav.operations')}: ${operations.activeBadgeCount}`"
      :title="t('nav.operations')"
      data-testid="operations-drawer-trigger"
      @click="operations.drawerOpen = true"
    >
      <Clock
        :size="18"
        aria-hidden="true"
      />
      <span
        v-if="operations.activeBadgeCount > 0"
        class="ws-shell-controls__badge"
        data-testid="operations-badge-count"
      >
        {{ Math.min(operations.activeBadgeCount, 99) }}
      </span>
    </button>

    <RouterLink
      v-if="showNotifications"
      :to="notificationsTo"
      class="ws-shell-controls__icon-link"
      :aria-label="`${t('nav.notifications')}: ${unreadCount}`"
      :title="t('nav.notifications')"
      data-testid="shell-notifications-link"
    >
      <Bell
        :size="18"
        aria-hidden="true"
      />
      <span
        v-if="unreadCount > 0"
        class="ws-shell-controls__badge"
      >
        {{ Math.min(unreadCount, 99) }}
      </span>
    </RouterLink>

    <RouterLink
      v-if="showWorkspaceLinks"
      :to="{ name: 'workspace' }"
      class="ws-shell-controls__icon-link ws-shell-controls__desktop-only"
      :aria-label="t('nav.workspace')"
      :title="t('nav.workspace')"
      data-testid="shell-workspace-link"
    >
      <Layers
        :size="18"
        aria-hidden="true"
      />
    </RouterLink>

    <RouterLink
      v-if="showWorkspaceLinks"
      :to="{ name: 'trash' }"
      class="ws-shell-controls__icon-link ws-shell-controls__desktop-only"
      :aria-label="t('nav.trash')"
      :title="t('nav.trash')"
      data-testid="shell-trash-link"
    >
      <Trash2
        :size="18"
        aria-hidden="true"
      />
    </RouterLink>
  </div>
</template>

<style scoped>
.ws-shell-controls {
  display: inline-flex;
  align-items: center;
  gap: var(--space-4);
  min-width: 0;
  flex: 0 0 auto;
}

.ws-shell-controls__search {
  display: none;
  align-items: center;
  gap: var(--space-6);
  padding: 0 var(--space-10);
  min-height: 36px;
  width: 210px;
  border-radius: var(--radius-control-m);
  border: 1px solid var(--border);
  background: var(--surface-2);
  transition: var(--theme-transition);
}

.ws-shell-controls__search:focus-within {
  border-color: var(--focus-border);
  background: var(--surface);
}

.ws-shell-controls__search-icon {
  color: var(--text-2);
  flex: 0 0 auto;
}

.ws-shell-controls__search-input {
  width: 100%;
  min-width: 0;
  border: none;
  background: transparent;
  color: var(--text-1);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
}

@media (min-width: 1024px) {
  .ws-shell-controls__search {
    display: inline-flex;
  }
}

.ws-shell-controls__icon-link,
.ws-shell-controls__icon-btn {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: var(--radius-control-m);
  color: var(--text-2);
  background: transparent;
  border: 1px solid transparent;
  cursor: pointer;
  flex: 0 0 auto;
  transition: var(--theme-transition);
}

.ws-shell-controls__icon-link:hover,
.ws-shell-controls__icon-btn:hover {
  color: var(--text-1);
  background: var(--surface-hover);
  border-color: var(--border);
}

.ws-shell-controls__palette-btn {
  display: inline-flex;
  align-items: center;
  gap: var(--space-6);
  min-height: 36px;
  padding: 0 var(--space-8);
  border-radius: var(--radius-control-m);
  border: 1px solid var(--border);
  background: var(--surface-2);
  color: var(--text-2);
  cursor: pointer;
  flex: 0 0 auto;
  transition: var(--theme-transition);
}

.ws-shell-controls__palette-btn:hover {
  color: var(--text-1);
  background: var(--surface-hover);
  border-color: var(--accent);
}

.ws-shell-controls__kbd {
  display: none;
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
}

@media (min-width: 768px) {
  .ws-shell-controls__kbd {
    display: inline;
  }
}

.ws-shell-controls__badge {
  position: absolute;
  top: 2px;
  right: 2px;
  min-width: 16px;
  height: 16px;
  padding: 0 var(--space-4);
  border-radius: var(--radius-badge-pill);
  background: var(--accent);
  color: var(--on-accent);
  font-size: var(--type-body-ultra-small-size);
  line-height: var(--type-body-ultra-small-line);
  font-weight: var(--type-body-ultra-small-weight);
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.ws-shell-controls__desktop-only {
  display: none;
}

@media (min-width: 768px) {
  .ws-shell-controls__desktop-only {
    display: inline-flex;
  }
}
</style>
