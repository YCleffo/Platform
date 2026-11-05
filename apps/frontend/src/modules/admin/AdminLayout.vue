<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch, type Component } from 'vue';
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import AppBreadcrumbs from '@/shared/ui/navigation/AppBreadcrumbs.vue';
import { breadcrumbs } from '@/shared/navigation/breadcrumbs';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import { coreAdminSessionEpoch } from '@/core/admin/session';
import { useAdminOperation } from './useAdminOperation';
import { useTheme } from '@/app/theme';
import { setAccountInterfacePolicy } from '@/app/appearance';
import { useCoreAccountSettings } from '@/core/admin/accountSettingsStore';
import { usePresenceHeartbeat } from '@/core/admin/usePresenceHeartbeat';
import { setLocale } from '@/shared/i18n';
import {
  Building2,
  ChevronLeft,
  ChevronRight,
  Eye,
  Layers,
  LayoutGrid,
  LogOut,
  Moon,
  Settings,
  ShieldCheck,
  Sun,
  Tag,
  X,
} from '@/shared/ui/icons';
import { lockScroll, unlockScroll } from '@/shared/composables/useScrollLock';
import { cachedAdminGet } from './adminCache';
import AppIconButton from '@/shared/ui/actions/AppIconButton.vue';
import AppTooltip from '@/shared/ui/overlays/AppTooltip.vue';

const auth = useAdminAuthStore();
usePresenceHeartbeat();
const accountSettings = useCoreAccountSettings();
watch(
  () => accountSettings.value,
  (value) => setAccountInterfacePolicy(value?.settings.interface ?? null),
  { immediate: true, flush: 'sync' },
);
onMounted(() => {
  void accountSettings.load().catch(() => undefined);
});
onBeforeUnmount(() => setAccountInterfacePolicy(null));
const prefetchGuard = useAdminOperation();
const route = useRoute();
const router = useRouter();
const { t, locale } = useI18n();
const { isDark, toggleTheme } = useTheme();
const menuOpen = ref(false);
const SIDEBAR_STORAGE_KEY = 'platform.admin.sidebar.collapsed';
const SIDEBAR_WIDTH_KEY = 'platform.admin.sidebar.width';
const collapsed = ref(false);
const resizing = ref(false);
const sidebarWidth = ref(260);
try {
  collapsed.value = localStorage.getItem(SIDEBAR_STORAGE_KEY) === '1';
  const storedWidth = localStorage.getItem(SIDEBAR_WIDTH_KEY);
  if (storedWidth !== null && Number.isFinite(Number(storedWidth))) {
    sidebarWidth.value = Math.min(340, Math.max(200, Number(storedWidth)));
  }
} catch {
  collapsed.value = false;
}
const sidebarStyle = computed(() => ({
  '--admin-sidebar-width': `${collapsed.value ? 68 : sidebarWidth.value}px`,
}));

interface AdminNavItem {
  label: string;
  name: string;
  icon: Component;
  permission?: string;
}

const sections = computed<Array<{ title: string; items: AdminNavItem[] }>>(() => [
  {
    title: t('admin.overview'),
    items: [{ label: t('admin.dashboard'), name: 'admin-dashboard', icon: LayoutGrid }],
  },
  {
    title: t('admin.platform'),
    items: [
      {
        label: t('admin.organizations'),
        name: 'admin-organizations',
        icon: Building2,
        permission: 'core.organizations.read',
      },
      {
        label: t('admin.persons'),
        name: 'admin-persons',
        icon: Layers,
        permission: 'core.persons.read',
      },
      {
        label: t('admin.accounts'),
        name: 'admin-accounts',
        icon: Tag,
        permission: 'core.accounts.read',
      },
      {
        label: t('admin.rbac'),
        name: 'admin-rbac',
        icon: ShieldCheck,
        permission: 'core.rbac.read',
      },
      {
        label: t('admin.modules'),
        name: 'admin-modules',
        icon: Settings,
        permission: 'core.modules.read',
      },
      {
        label: t('admin.settings'),
        name: 'admin-settings',
        icon: Settings,
        permission: 'core.settings.read',
      },
    ],
  },
  {
    title: t('admin.operations'),
    items: [
      {
        label: t('admin.privacy'),
        name: 'admin-privacy',
        icon: Eye,
        permission: 'core.privacy.requests.read',
      },
      { label: t('admin.audit'), name: 'admin-audit', icon: Eye, permission: 'core.superadmin' },
      { label: t('admin.security'), name: 'admin-security', icon: ShieldCheck },
      { label: t('coreProfile.title'), name: 'admin-profile', icon: Settings },
      { label: t('admin.finance'), name: 'admin-finance', icon: Tag },
    ],
  },
]);
const visibleSections = computed(() =>
  sections.value
    .map((section) => ({
      ...section,
      items: section.items.filter(
        (item) =>
          item.permission === undefined ||
          auth.can(item.permission) ||
          (item.name === 'admin-settings' && auth.can('core.feature_flags.read')),
      ),
    }))
    .filter((section) => section.items.length > 0),
);
const activeRoute = computed(() =>
  String(route.name ?? '').startsWith('admin-security')
    ? 'admin-security'
    : String(route.name ?? '').startsWith('admin-profile')
      ? 'admin-profile'
      : String(route.name ?? '').startsWith('admin-privacy')
        ? 'admin-privacy'
        : String(route.name ?? ''),
);
const currentLabel = computed(
  () =>
    sections.value
      .flatMap((section) => section.items)
      .find((item) => item.name === activeRoute.value)?.label ?? t('admin.title'),
);
const headerCrumbs = computed(() =>
  breadcrumbs(route.name, route.params, (key) => t(key), currentLabel.value),
);
const localeLabel = computed(() => (locale.value === 'ru' ? 'RU' : 'EN'));

function toggleSidebar(): void {
  collapsed.value = !collapsed.value;
  try {
    localStorage.setItem(SIDEBAR_STORAGE_KEY, collapsed.value ? '1' : '0');
  } catch {
    // The preference is optional when storage is unavailable.
  }
}

let stopResize: (() => void) | null = null;

function startResize(event: PointerEvent): void {
  if (window.innerWidth <= 960) return;
  event.preventDefault();
  const handle = event.currentTarget as HTMLElement;
  const pointerId = event.pointerId;
  const startWidth = sidebarWidth.value;
  const startCollapsed = collapsed.value;
  resizing.value = true;
  try {
    handle.setPointerCapture(pointerId);
  } catch {
    // Pointer capture is best-effort.
  }
  const move = (moveEvent: PointerEvent): void => {
    const width = Math.min(340, Math.max(68, moveEvent.clientX));
    if (width < 180) {
      collapsed.value = true;
    } else {
      collapsed.value = false;
      sidebarWidth.value = width;
    }
  };
  const finish = (commit: boolean): void => {
    if (!resizing.value) return;
    window.removeEventListener('pointermove', move);
    window.removeEventListener('pointerup', onUp);
    window.removeEventListener('blur', cancel);
    handle.removeEventListener('pointercancel', cancel);
    handle.removeEventListener('lostpointercapture', cancel);
    try {
      handle.releasePointerCapture(pointerId);
    } catch {
      // Capture may already be released.
    }
    resizing.value = false;
    stopResize = null;
    if (!commit) {
      sidebarWidth.value = startWidth;
      collapsed.value = startCollapsed;
      return;
    }
    if (collapsed.value || sidebarWidth.value < 200) {
      collapsed.value = true;
      sidebarWidth.value = Math.max(200, startWidth);
    } else {
      collapsed.value = false;
    }
    try {
      localStorage.setItem(SIDEBAR_WIDTH_KEY, String(sidebarWidth.value));
      localStorage.setItem(SIDEBAR_STORAGE_KEY, collapsed.value ? '1' : '0');
    } catch {
      // Optional local preference.
    }
  };
  const cancel = (): void => finish(false);
  const onUp = (): void => finish(true);
  stopResize = cancel;
  window.addEventListener('pointermove', move);
  window.addEventListener('pointerup', onUp);
  window.addEventListener('blur', cancel);
  handle.addEventListener('pointercancel', cancel);
  handle.addEventListener('lostpointercapture', cancel);
}

function resizeBy(delta: number): void {
  if (collapsed.value) {
    if (delta > 0) {
      collapsed.value = false;
      sidebarWidth.value = Math.max(240, sidebarWidth.value);
    }
  } else {
    sidebarWidth.value = Math.min(340, Math.max(200, sidebarWidth.value + delta));
    if (sidebarWidth.value < 200) {
      collapsed.value = true;
      sidebarWidth.value = 260;
    }
  }
  try {
    localStorage.setItem(SIDEBAR_WIDTH_KEY, String(sidebarWidth.value));
    localStorage.setItem(SIDEBAR_STORAGE_KEY, collapsed.value ? '1' : '0');
  } catch {
    // Optional local preference.
  }
}

function closeMenu(): void {
  menuOpen.value = false;
}

function toggleLocale(): void {
  setLocale(locale.value === 'ru' ? 'en' : 'ru');
}

async function logout(): Promise<void> {
  await auth.logout();
  await router.replace({ name: 'admin-login' });
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    closeMenu();
  }
}

watch(menuOpen, (value) => {
  if (value) {
    document.addEventListener('keydown', onKeydown);
    lockScroll();
  } else {
    document.removeEventListener('keydown', onKeydown);
    unlockScroll();
  }
});

watch(() => route.fullPath, closeMenu);

watch(
  () => auth.isAuthenticated,
  (authenticated) => {
    if (!authenticated && route.name !== 'admin-login') {
      void router.replace({ name: 'admin-login', query: { redirect: route.fullPath } });
    }
  },
);

onMounted(() => {
  const guard = prefetchGuard.begin();
  const paths: Array<[string, string]> = [
    ['core.organizations.read', '/core/organizations'],
    ['core.persons.read', '/core/persons?limit=25&offset=0'],
    ['core.persons.read', '/core/persons?limit=100&offset=0'],
    ['core.accounts.read', '/core/accounts?limit=25&offset=0'],
    ['core.rbac.read', '/core/roles?limit=25&offset=0'],
    ['core.rbac.read', '/core/permissions?limit=25&offset=0'],
    ['core.modules.read', '/core/modules'],
    ['core.settings.read', '/core/settings/system?limit=25&offset=0'],
    ['core.settings.read', '/core/feature-flags'],
    ['core.privacy.requests.read', '/core/privacy/requests?limit=25&offset=0'],
    ['core.privacy.legal_holds.read', '/core/privacy/legal-holds'],
    ['core.superadmin', '/core/audit-events?limit=25&offset=0'],
  ];
  void (async () => {
    for (const [permission, requestPath] of paths) {
      if (!prefetchGuard.current(guard)) return;
      if (!auth.can(permission)) continue;
      try {
        await cachedAdminGet(requestPath);
      } catch {
        // Per-resource errors stay visible on the page that uses the resource.
      }
    }
  })();
});

onBeforeUnmount(() => {
  stopResize?.();
  document.removeEventListener('keydown', onKeydown);
  unlockScroll();
});
</script>

<template>
  <div
    class="admin"
    :class="{ 'admin--collapsed': collapsed, 'admin--resizing': resizing }"
    :style="sidebarStyle"
  >
    <aside
      class="admin__sidebar"
      :aria-label="$t('admin.navigation')"
    >
      <div class="admin__sidebar-head">
        <RouterLink
          class="admin__brand"
          :to="{ name: 'admin-dashboard' }"
          :title="collapsed ? $t('admin.title') : undefined"
        >
          <span
            class="admin__mark"
            aria-hidden="true"
          >
            <ShieldCheck :size="20" />
          </span>
          <span
            class="admin__brand-copy"
            :aria-hidden="collapsed"
          >
            <strong>{{ $t('admin.title') }}</strong>
            <small>{{ $t('admin.brandSubtitle') }}</small>
          </span>
        </RouterLink>
        <button
          type="button"
          class="admin__collapse"
          :aria-label="collapsed ? $t('admin.expandSidebar') : $t('admin.collapseSidebar')"
          :aria-expanded="!collapsed"
          :title="collapsed ? $t('admin.expandSidebar') : $t('admin.collapseSidebar')"
          @click="toggleSidebar"
        >
          <ChevronRight
            v-if="collapsed"
            :size="18"
            aria-hidden="true"
          />
          <ChevronLeft
            v-else
            :size="18"
            aria-hidden="true"
          />
        </button>
      </div>

      <div
        class="admin__resize"
        role="separator"
        aria-orientation="vertical"
        :aria-label="$t('admin.resizeSidebar')"
        :aria-valuemin="68"
        :aria-valuemax="340"
        :aria-valuenow="collapsed ? 68 : sidebarWidth"
        tabindex="0"
        @pointerdown="startResize"
        @keydown.left.prevent="resizeBy(-10)"
        @keydown.right.prevent="resizeBy(10)"
      />

      <nav class="admin__nav">
        <div
          v-for="section in visibleSections"
          :key="section.title"
          class="admin__section"
        >
          <p
            v-if="!collapsed"
            class="admin__section-title"
          >
            {{ section.title }}
          </p>
          <AppTooltip
            v-for="item in section.items"
            :key="item.name"
            :content="item.label"
            :disabled="!collapsed"
          >
            <RouterLink
              class="admin__link"
              :class="{ 'admin__link--active': activeRoute === item.name }"
              :to="{ name: item.name }"
              :aria-current="activeRoute === item.name ? 'page' : undefined"
              :aria-label="collapsed ? item.label : undefined"
            >
              <component
                :is="item.icon"
                :size="18"
                aria-hidden="true"
              />
              <span class="admin__link-label">{{ item.label }}</span>
            </RouterLink>
          </AppTooltip>
        </div>
      </nav>

      <div class="admin__sidebar-footer">
        <AppTooltip
          :content="$t('admin.customerSite')"
          :disabled="!collapsed"
        >
          <RouterLink
            :to="{ name: 'catalog' }"
            class="admin__link"
            :aria-label="collapsed ? $t('admin.customerSite') : undefined"
          >
            <LayoutGrid
              :size="18"
              aria-hidden="true"
            />
            <span class="admin__link-label">{{ $t('admin.customerSite') }}</span>
          </RouterLink>
        </AppTooltip>
      </div>
    </aside>

    <div class="admin__body">
      <header class="admin__topbar">
        <div class="admin__topbar-start">
          <AppIconButton
            class="admin__menu-button"
            :label="$t('nav.menu')"
            @click="menuOpen = true"
          >
            <span
              class="admin__hamburger"
              aria-hidden="true"
            >
              <span />
              <span />
              <span />
            </span>
          </AppIconButton>
          <h1 class="visually-hidden">{{ headerCrumbs.at(-1)?.label || currentLabel }}</h1>
          <AppBreadcrumbs
            class="admin__breadcrumbs"
            :items="headerCrumbs"
          />
        </div>
        <div class="admin__topbar-actions">
          <span
            class="admin__identity"
            :title="auth.identity?.account.id"
          >
            {{ auth.displayName || auth.identity?.roles.join(' · ') || $t('admin.operator') }}
          </span>
          <AppIconButton
            :label="isDark ? $t('theme.light') : $t('theme.dark')"
            @click="toggleTheme"
          >
            <Sun
              v-if="isDark"
              :size="20"
              aria-hidden="true"
            />
            <Moon
              v-else
              :size="20"
              aria-hidden="true"
            />
          </AppIconButton>
          <AppIconButton
            :label="`${localeLabel}: ${$t('theme.locale')}`"
            @click="toggleLocale"
          >
            <span class="admin__locale">{{ localeLabel }}</span>
          </AppIconButton>
          <AppIconButton
            :label="$t('nav.logout')"
            @click="logout"
          >
            <LogOut
              :size="20"
              aria-hidden="true"
            />
          </AppIconButton>
        </div>
      </header>

      <main class="admin__main">
        <RouterView
          v-if="auth.isAuthenticated"
          :key="`${coreAdminSessionEpoch}:${route.path}`"
        />
      </main>
    </div>

    <div
      v-if="menuOpen"
      class="admin__drawer"
      role="dialog"
      aria-modal="true"
      :aria-label="$t('admin.navigation')"
    >
      <div class="admin__drawer-head">
        <span class="admin__brand">
          <span
            class="admin__mark"
            aria-hidden="true"
          >
            <ShieldCheck :size="20" />
          </span>
          <strong>{{ $t('admin.title') }}</strong>
        </span>
        <AppIconButton
          :label="$t('common.close')"
          @click="closeMenu"
        >
          <X
            :size="22"
            aria-hidden="true"
          />
        </AppIconButton>
      </div>
      <nav class="admin__drawer-nav">
        <div
          v-for="section in visibleSections"
          :key="section.title"
        >
          <p class="admin__section-title">{{ section.title }}</p>
          <RouterLink
            v-for="item in section.items"
            :key="item.name"
            class="admin__link"
            :class="{ 'admin__link--active': activeRoute === item.name }"
            :to="{ name: item.name }"
          >
            <component
              :is="item.icon"
              :size="18"
              aria-hidden="true"
            />
            <span>{{ item.label }}</span>
          </RouterLink>
        </div>
      </nav>
    </div>
  </div>
</template>

<style scoped>
.admin {
  --admin-sidebar-width: 260px;
  --admin-topbar-height: 68px;
  --admin-sidebar-gap: var(--space-20);
  --admin-nav-gap: var(--space-16);
  --admin-section-gap: 2px;
  display: grid;
  grid-template-columns: var(--admin-sidebar-width) minmax(0, 1fr);
  min-height: 100dvh;
  background: var(--bg);
  color: var(--text-1);
  transition:
    grid-template-columns var(--motion-control-duration) var(--motion-ease),
    var(--theme-transition);
}
.admin--collapsed {
  --admin-sidebar-width: var(--admin-topbar-height);
  --admin-sidebar-gap: var(--space-4);
  --admin-nav-gap: 0px;
  --admin-section-gap: 0px;
}
.admin--resizing {
  transition: none;
}
.admin--resizing .admin__brand-copy,
.admin--resizing .admin__link,
.admin--resizing .admin__link-label {
  transition: none;
}
.admin__sidebar {
  position: sticky;
  top: 0;
  z-index: 12;
  display: flex;
  flex-direction: column;
  gap: var(--admin-sidebar-gap);
  height: 100dvh;
  padding: 0 var(--space-12) var(--space-20);
  border-right: 1px solid var(--border);
  background: var(--surface);
  overflow: visible;
}
.admin__sidebar-head {
  display: flex;
  align-items: center;
  gap: var(--space-8);
  min-width: 0;
  min-height: var(--admin-topbar-height);
}
.admin__brand {
  display: flex;
  align-items: center;
  gap: var(--space-8);
  min-width: 0;
  padding: 0 var(--space-8);
  color: var(--text-1);
}
.admin__brand-copy {
  display: grid;
  gap: 2px;
  min-width: 0;
  max-width: 150px;
  overflow: hidden;
  opacity: 1;
  transition:
    max-width var(--motion-control-duration) var(--motion-ease),
    opacity var(--motion-fast-duration) var(--motion-ease),
    var(--theme-transition);
}
.admin__brand-copy strong,
.admin__brand-copy small {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.admin__resize {
  position: absolute;
  top: 0;
  right: -3px;
  z-index: 2;
  width: 6px;
  height: 100%;
  cursor: col-resize;
  outline: none;
}
.admin__resize::after {
  position: absolute;
  top: 0;
  bottom: 0;
  left: 2px;
  width: 1px;
  background: var(--border);
  content: '';
  transition:
    opacity var(--motion-fast-duration) var(--motion-ease),
    var(--theme-transition);
}
.admin__resize:hover::after,
.admin__resize:focus-visible::after,
.admin--resizing .admin__resize::after {
  background: var(--accent);
  opacity: 0.55;
}
.admin__collapse {
  position: absolute;
  top: var(--space-20);
  right: -14px;
  z-index: 3;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  flex: 0 0 auto;
  border: 1px solid var(--border);
  border-radius: 50%;
  background: var(--surface);
  color: var(--text-2);
  box-shadow: var(--shadow-soft);
  transition: var(--theme-transition);
}
.admin__collapse:hover {
  border-color: var(--accent);
  color: var(--text-1);
}
.admin--collapsed .admin__brand-copy {
  max-width: 0;
  opacity: 0;
}
.admin--collapsed .admin__sidebar-footer {
  padding-top: var(--space-4);
}
.admin__brand strong {
  font-size: var(--type-body-medium-emphasis-size);
  font-weight: var(--type-body-medium-emphasis-weight);
  letter-spacing: var(--type-body-medium-emphasis-tracking);
  line-height: var(--type-body-medium-emphasis-line);
}
.admin__brand small {
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  color: var(--text-3);
}
.admin__mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  border-radius: 10px;
  background: var(--accent);
  color: var(--color-white);
  flex: 0 0 auto;
}
.admin__nav {
  display: grid;
  gap: var(--admin-nav-gap);
  overflow-y: auto;
  overflow-x: hidden;
}
.admin__section {
  display: grid;
  gap: var(--admin-section-gap);
}
.admin__section-title {
  padding: var(--space-4) var(--space-8);
  font-size: var(--type-body-small-emphasis-size);
  line-height: var(--type-body-small-emphasis-line);
  font-weight: var(--type-body-small-emphasis-weight);
  letter-spacing: 0.04em;
  text-transform: uppercase;
  white-space: nowrap;
  overflow: hidden;
  color: var(--text-3);
}
.admin__link {
  display: flex;
  min-width: 0;
  align-items: center;
  gap: var(--space-8);
  min-height: 38px;
  padding: var(--space-6) var(--space-8);
  border-radius: var(--radius-control-s);
  color: var(--text-2);
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  text-decoration: none;
  overflow: hidden;
  transition:
    gap var(--motion-control-duration) var(--motion-ease),
    var(--theme-transition);
}
.admin__link-label {
  flex: 1;
  min-width: 0;
  max-width: none;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  opacity: 1;
  transition:
    max-width var(--motion-control-duration) var(--motion-ease),
    opacity var(--motion-fast-duration) var(--motion-ease),
    var(--theme-transition);
}
.admin--collapsed .admin__link {
  justify-content: center;
  gap: 0;
  padding-left: 0;
  padding-right: 0;
}
.admin__link svg {
  flex: 0 0 auto;
}
.admin--collapsed .admin__link-label {
  max-width: 0;
  opacity: 0;
}
.admin__link:hover {
  background: var(--surface-hover);
  color: var(--text-1);
}
.admin__link--active {
  background: var(--accent-soft);
  color: var(--accent-text);
  font-weight: var(--type-body-medium-emphasis-weight);
}
.admin__sidebar-footer {
  margin-top: auto;
  border-top: 1px solid var(--border);
  padding-top: var(--space-8);
}
.admin__body {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.admin__topbar {
  position: sticky;
  top: 0;
  z-index: 10;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-12);
  min-height: 68px;
  padding: var(--space-8) var(--space-20);
  border-bottom: 1px solid var(--border);
  background: var(--bg);
}
.admin__topbar-start {
  display: flex;
  align-items: center;
  gap: var(--space-12);
  min-width: 0;
  flex: 1;
}
.admin__topbar-actions {
  flex: none;
  display: flex;
  align-items: center;
  gap: var(--space-6);
}
.admin__identity {
  max-width: 240px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  color: var(--text-2);
}
.admin__locale {
  font-size: var(--type-body-small-emphasis-size);
  letter-spacing: var(--type-body-small-emphasis-tracking);
  line-height: var(--type-body-small-emphasis-line);
  font-weight: var(--type-body-small-emphasis-weight);
}
.admin__main {
  min-width: 0;
  width: 100%;
  max-width: 1440px;
  margin: 0 auto;
  padding: var(--space-20);
}
.admin__menu-button {
  flex: none;
  display: inline-flex;
}
.admin__hamburger {
  display: inline-flex;
  flex-direction: column;
  gap: 4px;
  width: 18px;
}
.admin__hamburger span {
  display: block;
  height: 2px;
  border-radius: 2px;
  background: currentColor;
}
.admin__drawer {
  position: fixed;
  inset: 0;
  z-index: 800;
  display: none;
  flex-direction: column;
  padding: max(var(--space-12), env(safe-area-inset-top)) var(--page-pad-x)
    max(var(--space-16), env(safe-area-inset-bottom));
  background: var(--bg);
  overflow-y: auto;
}
.admin__drawer-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-bottom: var(--space-12);
  border-bottom: 1px solid var(--border);
}
.admin__drawer-nav {
  display: grid;
  gap: var(--space-16);
  padding-top: var(--space-16);
}
@media (max-width: 960px) {
  .admin {
    grid-template-columns: minmax(0, 1fr);
  }
  .admin__resize {
    display: none;
  }

  .admin__sidebar {
    display: none;
  }
  .admin__drawer {
    display: flex;
  }
  .admin__main {
    padding: var(--space-16);
  }
  .admin__identity {
    display: none;
  }
}
@media (min-width: 961px) {
  .admin__menu-button {
    display: none;
  }
}
@media (prefers-reduced-motion: reduce) {
  .admin,
  .admin__sidebar,
  .admin__brand-copy,
  .admin__link,
  .admin__link-label,
  .admin__collapse {
    transition: none;
  }
}
</style>
