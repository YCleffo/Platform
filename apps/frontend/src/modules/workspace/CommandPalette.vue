<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { Command, Search, X } from '@/shared/ui/icons';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import { useAuthStore } from '@/core/auth/authStore';
import { useTheme } from '@/app/theme';
import { setLocale } from '@/shared/i18n';
import { useFocusTrap } from '@/shared/composables/useFocusTrap';
import { lockScroll, unlockScroll } from '@/shared/composables/useScrollLock';
import { useToast } from '@/shared/composables/useToast';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import {
  isConfirmationRequiredError,
  workspaceApi,
  type WorkspaceSearchItem,
} from '@/shared/api/workspaceApi';
import AppIconButton from '@/shared/ui/actions/AppIconButton.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppConfirmDialog from '@/shared/ui/overlays/AppConfirmDialog.vue';
import { useOperationsCenter } from './useOperationsCenter';
import { buildStaticCommands, filterCommands, type CommandEntry } from './commandRegistry';

const router = useRouter();
const { t, locale } = useI18n();
const adminAuth = useAdminAuthStore();
const commercialAuth = useAuthStore();
const { toggleTheme } = useTheme();
const operations = useOperationsCenter();
const toast = useToast();

const dialogEl = ref<HTMLElement | null>(null);
const inputEl = ref<HTMLInputElement | null>(null);
const query = ref('');
const selectedIndex = ref(0);
const searchLoading = ref(false);
const entityResults = ref<WorkspaceSearchItem[]>([]);

const confirmOpen = ref(false);
const confirmBusy = ref(false);
const pendingCommand = ref<CommandEntry | null>(null);

const { activate, deactivate, isActive } = useFocusTrap(dialogEl);
let overlayLocked = false;
let searchTimer: ReturnType<typeof setTimeout> | null = null;
let searchController: AbortController | null = null;
let previousFocus: HTMLElement | null = null;

const open = computed({
  get: () => operations.commandPaletteOpen,
  set: (value: boolean) => {
    operations.commandPaletteOpen = value;
  },
});

const staticCommands = computed(() =>
  buildStaticCommands({
    isAdminAuthenticated: adminAuth.isAuthenticated,
    isCommercialAuthenticated: commercialAuth.isAuthenticated,
    can: (perm) => adminAuth.can(perm),
    educationEnabled: import.meta.env.VITE_EDUCATION_ENABLED === '1',
    t: (k) => t(k),
    toggleTheme,
    toggleLocale: () => setLocale(locale.value === 'ru' ? 'en' : 'ru'),
    openOperations: () => {
      operations.drawerOpen = true;
    },
  }),
);

const filteredStatic = computed(() => filterCommands(staticCommands.value, query.value));

const entityCommands = computed<CommandEntry[]>(() =>
  entityResults.value.map((item) => ({
    id: `entity-${item.entityType}-${item.entityId}`,
    title: item.title,
    subtitle: item.subtitle ?? item.snippet ?? undefined,
    category: 'entity',
    route: item.route,
    keywords: [item.category, item.entityType],
  })),
);

const combinedCommands = computed<CommandEntry[]>(() => [
  ...filteredStatic.value,
  ...entityCommands.value,
]);

watch(combinedCommands, (items) => {
  if (selectedIndex.value >= items.length) {
    selectedIndex.value = Math.max(0, items.length - 1);
  }
});

watch(query, (val) => {
  selectedIndex.value = 0;
  if (searchTimer) {
    clearTimeout(searchTimer);
    searchTimer = null;
  }
  searchController?.abort();
  searchController = null;

  const trimmed = val.trim();
  if (!trimmed || !adminAuth.isAuthenticated) {
    entityResults.value = [];
    searchLoading.value = false;
    return;
  }

  searchLoading.value = true;
  searchTimer = setTimeout(async () => {
    const controller = new AbortController();
    searchController = controller;
    try {
      const res = await workspaceApi.search(
        {
          q: trimmed,
          limit: 8,
          organizationId: operations.organizationId,
        },
        controller.signal,
      );
      if (!controller.signal.aborted) {
        entityResults.value = res.items ?? [];
      }
    } catch {
      if (!controller.signal.aborted) {
        entityResults.value = [];
      }
    } finally {
      if (searchController === controller) {
        searchLoading.value = false;
      }
    }
  }, 200);
});

function closePalette(): void {
  open.value = false;
}

async function runCommand(cmd: CommandEntry, confirmed = false): Promise<void> {
  if (cmd.dangerous && !confirmed) {
    pendingCommand.value = cmd;
    confirmOpen.value = true;
    return;
  }

  if (cmd.actionCode) {
    try {
      await operations.executeAction({
        actionCode: cmd.actionCode,
        confirmed,
      });
      toast.success(t('workspace.cmdExecuted'));
      closePalette();
    } catch (caught) {
      if (!confirmed && isConfirmationRequiredError(caught)) {
        pendingCommand.value = cmd;
        confirmOpen.value = true;
        return;
      }
      toast.error(resolveErrorMessage(caught));
    }
    return;
  }

  closePalette();
  if (cmd.run) {
    await cmd.run();
    return;
  }
  if (cmd.route) {
    await router.push(cmd.route);
  }
}

async function confirmPendingCommand(): Promise<void> {
  if (!pendingCommand.value) return;
  confirmBusy.value = true;
  try {
    await runCommand(pendingCommand.value, true);
    confirmOpen.value = false;
    pendingCommand.value = null;
  } finally {
    confirmBusy.value = false;
  }
}

function onInputKeydown(event: KeyboardEvent): void {
  const len = combinedCommands.value.length;
  if (event.key === 'ArrowDown') {
    event.preventDefault();
    if (len > 0) selectedIndex.value = (selectedIndex.value + 1) % len;
  } else if (event.key === 'ArrowUp') {
    event.preventDefault();
    if (len > 0) selectedIndex.value = (selectedIndex.value - 1 + len) % len;
  } else if (event.key === 'Enter') {
    const active = combinedCommands.value[selectedIndex.value];
    if (active) {
      event.preventDefault();
      void runCommand(active);
    }
  } else if (event.key === 'Escape') {
    event.preventDefault();
    closePalette();
  }
}

function onGlobalKeydown(event: KeyboardEvent): void {
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
    event.preventDefault();
    open.value = !open.value;
    return;
  }
  if (event.key === 'Escape' && open.value && !confirmOpen.value && isActive()) {
    event.preventDefault();
    closePalette();
  }
}

watch(
  open,
  async (isOpen) => {
    if (isOpen) {
      previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
      query.value = '';
      entityResults.value = [];
      selectedIndex.value = 0;
      if (!overlayLocked) {
        overlayLocked = true;
        lockScroll();
      }
      await nextTick();
      activate();
      inputEl.value?.focus();
    } else {
      searchController?.abort();
      if (searchTimer) clearTimeout(searchTimer);
      deactivate();
      if (overlayLocked) {
        overlayLocked = false;
        unlockScroll();
      }
      if (previousFocus && typeof previousFocus.focus === 'function') {
        previousFocus.focus();
        previousFocus = null;
      }
    }
  },
  { flush: 'post' },
);

onMounted(() => {
  document.addEventListener('keydown', onGlobalKeydown);
});

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onGlobalKeydown);
  searchController?.abort();
  if (searchTimer) clearTimeout(searchTimer);
  if (overlayLocked) {
    overlayLocked = false;
    unlockScroll();
  }
  deactivate();
});
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="cmd-palette__backdrop"
      @mousedown.self="closePalette"
    >
      <div
        ref="dialogEl"
        class="cmd-palette"
        role="dialog"
        aria-modal="true"
        :aria-label="$t('workspace.paletteTitle')"
        data-testid="command-palette-dialog"
      >
        <div class="cmd-palette__header">
          <Search
            :size="18"
            class="cmd-palette__search-icon"
            aria-hidden="true"
          />
          <input
            ref="inputEl"
            v-model="query"
            type="search"
            class="cmd-palette__input"
            :placeholder="$t('workspace.palettePlaceholder')"
            :aria-label="$t('workspace.palettePlaceholder')"
            aria-autocomplete="list"
            data-testid="command-palette-input"
            @keydown="onInputKeydown"
          />
          <AppIconButton
            :label="$t('common.close')"
            @click="closePalette"
          >
            <X
              :size="18"
              aria-hidden="true"
            />
          </AppIconButton>
        </div>

        <div
          class="cmd-palette__body"
          role="listbox"
          :aria-label="$t('workspace.paletteTitle')"
        >
          <p
            v-if="searchLoading"
            class="cmd-palette__status"
          >
            {{ $t('workspace.paletteLoading') }}
          </p>

          <p
            v-if="!searchLoading && combinedCommands.length === 0"
            class="cmd-palette__empty"
            data-testid="command-palette-empty"
          >
            {{ $t('workspace.paletteEmpty') }}
          </p>

          <template v-if="filteredStatic.length > 0">
            <p class="cmd-palette__group-label">{{ $t('workspace.paletteStaticGroup') }}</p>
            <button
              v-for="(cmd, idx) in filteredStatic"
              :key="cmd.id"
              type="button"
              role="option"
              class="cmd-palette__item"
              :class="{ 'cmd-palette__item--active': selectedIndex === idx }"
              :aria-selected="selectedIndex === idx"
              data-testid="command-palette-item"
              @mouseenter="selectedIndex = idx"
              @click="runCommand(cmd)"
            >
              <Command
                :size="16"
                class="cmd-palette__item-icon"
                aria-hidden="true"
              />
              <span class="cmd-palette__item-copy">
                <span class="cmd-palette__item-title">{{ cmd.title }}</span>
                <span
                  v-if="cmd.subtitle"
                  class="cmd-palette__item-sub"
                >
                  {{ cmd.subtitle }}
                </span>
              </span>
              <AppBadge
                v-if="cmd.dangerous"
                variant="danger"
              >
                {{ $t('common.confirm') }}
              </AppBadge>
            </button>
          </template>

          <template v-if="entityCommands.length > 0">
            <p class="cmd-palette__group-label">{{ $t('workspace.paletteEntityGroup') }}</p>
            <button
              v-for="(cmd, eIdx) in entityCommands"
              :key="cmd.id"
              type="button"
              role="option"
              class="cmd-palette__item"
              :class="{
                'cmd-palette__item--active': selectedIndex === filteredStatic.length + eIdx,
              }"
              :aria-selected="selectedIndex === filteredStatic.length + eIdx"
              data-testid="command-palette-item"
              @mouseenter="selectedIndex = filteredStatic.length + eIdx"
              @click="runCommand(cmd)"
            >
              <Search
                :size="16"
                class="cmd-palette__item-icon"
                aria-hidden="true"
              />
              <span class="cmd-palette__item-copy">
                <span class="cmd-palette__item-title">{{ cmd.title }}</span>
                <span
                  v-if="cmd.subtitle"
                  class="cmd-palette__item-sub"
                >
                  {{ cmd.subtitle }}
                </span>
              </span>
              <AppBadge variant="neutral">{{ cmd.keywords?.[0] }}</AppBadge>
            </button>
          </template>
        </div>

        <footer class="cmd-palette__footer">
          <span>{{ $t('workspace.paletteKeyboardHint') }}</span>
        </footer>
      </div>
    </div>

    <AppConfirmDialog
      v-model="confirmOpen"
      :title="pendingCommand?.confirmTitle ?? $t('workspace.cmdConfirmTitle')"
      :description="pendingCommand?.confirmDescription ?? $t('workspace.cmdConfirmDesc')"
      :confirm-label="$t('common.confirm')"
      :busy="confirmBusy"
      :danger="Boolean(pendingCommand?.dangerous)"
      @confirm="confirmPendingCommand"
    />
  </Teleport>
</template>

<style scoped>
.cmd-palette__backdrop {
  position: fixed;
  inset: 0;
  z-index: 950;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: clamp(24px, 8vh, 88px) var(--space-16) var(--space-16);
  background: var(--backdrop);
}

.cmd-palette {
  width: min(100%, 640px);
  max-height: min(76vh, 580px);
  display: flex;
  flex-direction: column;
  background: var(--overlay-surface);
  color: var(--text-1);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-card-l);
  box-shadow: var(--shadow-modal);
  overflow: hidden;
  transition: var(--theme-transition);
}

.cmd-palette__header {
  display: flex;
  align-items: center;
  gap: var(--space-8);
  padding: var(--space-12) var(--space-16);
  border-bottom: 1px solid var(--border);
}

.cmd-palette__search-icon {
  color: var(--text-2);
  flex: 0 0 auto;
}

.cmd-palette__input {
  flex: 1;
  min-width: 0;
  border: none;
  background: transparent;
  color: var(--text-1);
  font-size: var(--type-body-large-size);
  line-height: var(--type-body-large-line);
  font-weight: var(--type-body-large-weight);
  padding: var(--space-4) 0;
}

.cmd-palette__body {
  flex: 1;
  overflow-y: auto;
  padding: var(--space-8);
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.cmd-palette__group-label {
  padding: var(--space-6) var(--space-10);
  color: var(--text-3);
  font-size: var(--type-overline-label-size);
  line-height: var(--type-overline-label-line);
  font-weight: var(--type-overline-label-weight);
  text-transform: uppercase;
}

.cmd-palette__item {
  width: 100%;
  display: flex;
  align-items: center;
  gap: var(--space-10);
  padding: var(--space-10) var(--space-12);
  border-radius: var(--radius-control-m);
  text-align: left;
  color: var(--text-1);
  background: transparent;
  border: 1px solid transparent;
  transition: var(--theme-transition);
}

.cmd-palette__item:hover,
.cmd-palette__item--active {
  background: var(--selected-surface);
  border-color: var(--selected-accent);
}

.cmd-palette__item-icon {
  color: var(--accent-text);
  flex: 0 0 auto;
}

.cmd-palette__item-copy {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.cmd-palette__item-title {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cmd-palette__item-sub {
  color: var(--text-2);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cmd-palette__status,
.cmd-palette__empty {
  padding: var(--space-16);
  text-align: center;
  color: var(--text-2);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
}

.cmd-palette__footer {
  padding: var(--space-8) var(--space-16);
  border-top: 1px solid var(--border);
  background: var(--surface-2);
  color: var(--text-2);
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
}
</style>
