<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import {
  Building2,
  Inbox,
  Layers,
  LayoutGrid,
  LogIn,
  LogOut,
  Moon,
  Settings,
  Sun,
} from '@/shared/ui/icons';
import { useAuthStore } from '@/core/auth/authStore';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import WorkspaceShellControls from '@/modules/workspace/WorkspaceShellControls.vue';
import { useTheme } from '@/app/theme';
import { setLocale } from '@/shared/i18n';
import AppHeader from '@/shared/ui/navigation/AppHeader.vue';
import AppBreadcrumbs from '@/shared/ui/navigation/AppBreadcrumbs.vue';
import { breadcrumbs } from '@/shared/navigation/breadcrumbs';
import { activeNavigationRoute } from '@/shared/navigation/activeRoute';
import { startsOnPageSurface } from '@/shared/navigation/pageSwipe';
import AppBottomNav from '@/shared/ui/navigation/AppBottomNav.vue';
import type { BottomNavItem } from '@/shared/ui/navigation/AppBottomNav.vue';
import AppMobileMenu from '@/shared/ui/navigation/AppMobileMenu.vue';
import AppIconButton from '@/shared/ui/actions/AppIconButton.vue';
import SiteContextMenu from './SiteContextMenu.vue';

const auth = useAuthStore();
const adminAuth = useAdminAuthStore();
const router = useRouter();
const route = useRoute();
const { t, locale } = useI18n();
const { isDark, toggleTheme } = useTheme();
const menuOpen = ref(false);
const headerCrumbs = computed(() => breadcrumbs(route.name, route.params, (key) => t(key)));

const primaryNavigation = computed(() => activeNavigationRoute(route));

const bottomItems = computed<BottomNavItem[]>(() => [
  { label: t('nav.catalog'), icon: LayoutGrid, routeName: 'catalog' },
  { label: t('nav.cabinet'), icon: Building2, routeName: 'cabinet' },
  { label: t('nav.drive'), icon: Inbox, routeName: 'drive' },
  { label: t('nav.settings'), icon: Settings, routeName: 'settings' },
]);

const primaryOrder: Record<string, number> = {
  catalog: 0,
  cabinet: 1,
  drive: 2,
  settings: 3,
};

const pageTransition = ref<'page-slide-left' | 'page-slide-right'>('page-slide-left');

watch(primaryNavigation, (to: string, from: string) => {
  const toIdx = primaryOrder[to];
  const fromIdx = primaryOrder[from];
  if (toIdx !== undefined && fromIdx !== undefined && toIdx !== fromIdx) {
    pageTransition.value = toIdx > fromIdx ? 'page-slide-left' : 'page-slide-right';
  }
});

let swipeStart: { id: number; x: number; y: number; time: number; route: string } | undefined;
let suppressClickUntil = 0;

function onTouchStart(event: TouchEvent): void {
  swipeStart = undefined;
  suppressClickUntil = 0;
  const page = event.currentTarget;
  const touch = event.touches[0];
  if (
    window.innerWidth >= 768 ||
    menuOpen.value ||
    event.touches.length !== 1 ||
    !touch ||
    touch.clientX < 24 ||
    touch.clientX > window.innerWidth - 24 ||
    !(page instanceof Element) ||
    !startsOnPageSurface(event.target, page)
  )
    return;
  swipeStart = {
    id: touch.identifier,
    x: touch.clientX,
    y: touch.clientY,
    time: performance.now(),
    route: route.fullPath,
  };
}

function cancelSwipe(): void {
  swipeStart = undefined;
}

function onTouchMove(event: TouchEvent): void {
  const touch = event.touches[0];
  if (!swipeStart || event.touches.length !== 1 || touch?.identifier !== swipeStart.id) {
    cancelSwipe();
    return;
  }
  const dx = Math.abs(touch.clientX - swipeStart.x);
  const dy = Math.abs(touch.clientY - swipeStart.y);
  if (dy > 10 && dy >= dx) cancelSwipe();
}

function onTouchEnd(event: TouchEvent): void {
  const start = swipeStart;
  cancelSwipe();
  const touch = event.changedTouches[0];
  if (!start || touch?.identifier !== start.id || route.fullPath !== start.route || menuOpen.value)
    return;
  const dx = touch.clientX - start.x;
  const dy = touch.clientY - start.y;
  if (
    Math.abs(dx) < 45 ||
    Math.abs(dx) <= Math.abs(dy) * 1.4 ||
    performance.now() - start.time >= 500
  )
    return;
  const current = bottomItems.value.findIndex((item) => item.routeName === primaryNavigation.value);
  const target = current < 0 ? undefined : bottomItems.value[current + (dx < 0 ? 1 : -1)];
  if (!target) return;
  suppressClickUntil = performance.now() + 300;
  void router.push({ name: target.routeName });
}

function onPageClick(event: MouseEvent): void {
  if (event.detail > 0 && performance.now() < suppressClickUntil) {
    suppressClickUntil = 0;
    event.preventDefault();
    event.stopPropagation();
  }
}

const localeLabel = computed(() => (locale.value === 'ru' ? 'RU' : 'EN'));

function toggleLocale(): void {
  setLocale(locale.value === 'ru' ? 'en' : 'ru');
}

async function logout(): Promise<void> {
  await auth.logout();
  await router.push({ name: 'login' });
}
</script>

<template>
  <div class="app-layout">
    <AppHeader>
      <template #leading>
        <RouterLink
          class="brand"
          :to="{ name: 'catalog' }"
          :aria-label="$t('app.name')"
        >
          <span
            class="brand__mark"
            aria-hidden="true"
          >
            <Layers :size="22" />
          </span>
          <span class="brand__name">{{ $t('app.name') }}</span>
        </RouterLink>
      </template>

      <template #center>
        <AppBreadcrumbs
          class="app-layout__mobile-title"
          :items="headerCrumbs"
        />
        <nav
          class="app-layout__nav"
          :aria-label="$t('nav.primary')"
        >
          <RouterLink
            v-for="item in bottomItems"
            :key="item.routeName"
            class="app-layout__nav-link"
            :class="{ 'app-layout__nav-link--active': primaryNavigation === item.routeName }"
            active-class=""
            exact-active-class=""
            :to="{ name: item.routeName }"
            :aria-current="primaryNavigation === item.routeName ? 'page' : undefined"
          >
            {{ item.label }}
          </RouterLink>
        </nav>
      </template>

      <template #actions>
        <WorkspaceShellControls
          :show-search-input="adminAuth.isAuthenticated"
          :show-search-link="adminAuth.isAuthenticated"
          :show-notifications="adminAuth.isAuthenticated"
          :show-workspace-links="adminAuth.isAuthenticated"
          :show-back="true"
        />
        <AppIconButton
          class="app-layout__desktop-action"
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
          class="app-layout__desktop-action"
          :label="`${localeLabel}: ${$t('theme.locale')}`"
          @click="toggleLocale"
        >
          <span class="app-layout__locale">{{ localeLabel }}</span>
        </AppIconButton>
        <AppIconButton
          v-if="auth.isAuthenticated"
          class="app-layout__desktop-action"
          :label="$t('nav.logout')"
          @click="logout"
        >
          <LogOut
            :size="20"
            aria-hidden="true"
          />
        </AppIconButton>
        <AppIconButton
          v-else
          class="app-layout__desktop-action"
          :label="$t('nav.login')"
          @click="router.push({ name: 'login' })"
        >
          <LogIn
            :size="20"
            aria-hidden="true"
          />
        </AppIconButton>
        <AppIconButton
          class="app-layout__menu-button"
          :class="{ 'app-layout__menu-button--open': menuOpen }"
          :label="menuOpen ? $t('common.close') : $t('nav.menu')"
          aria-haspopup="dialog"
          :aria-expanded="menuOpen"
          @click="menuOpen = !menuOpen"
        >
          <span
            class="hamburger"
            :class="{ 'hamburger--open': menuOpen }"
            aria-hidden="true"
          >
            <span />
            <span />
            <span />
          </span>
        </AppIconButton>
      </template>
    </AppHeader>

    <SiteContextMenu>
      <main
        class="app-layout__main"
        @touchstart.passive="onTouchStart"
        @touchmove.passive="onTouchMove"
        @touchend.passive="onTouchEnd"
        @touchcancel.passive="cancelSwipe"
        @click.capture="onPageClick"
      >
        <RouterView v-slot="{ Component }">
          <Transition
            :name="pageTransition"
            mode="out-in"
          >
            <component
              :is="Component"
              :key="primaryNavigation"
            />
          </Transition>
        </RouterView>
      </main>
    </SiteContextMenu>

    <AppBottomNav
      :items="bottomItems"
      :active-route-name="primaryNavigation"
    />
    <AppMobileMenu v-model="menuOpen" />
  </div>
</template>

<style scoped>
.app-layout {
  min-height: 100dvh;
  display: flex;
  flex-direction: column;
}

.brand {
  display: inline-flex;
  align-items: center;
  gap: var(--gap-s);
  color: var(--text-1);
  transition: var(--theme-transition);
}

.brand__mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 38px;
  height: 38px;
  border-radius: var(--radius-control-m);
  background: var(--accent);
  color: var(--on-accent);
  transition: var(--theme-transition);
}

.brand__name {
  font-size: var(--type-h3-size);
  letter-spacing: var(--type-h3-tracking);
  line-height: var(--type-h3-line);
  font-weight: var(--type-h3-weight);
}

.app-layout__mobile-title {
  display: block;
  max-width: 100%;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  font-size: var(--type-body-small-size);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  color: var(--text-1);
  transition: var(--theme-transition);
}

.app-layout__nav {
  display: none;
}

.app-layout__locale {
  font-size: var(--type-body-small-emphasis-size);
  line-height: var(--type-body-small-emphasis-line);
  font-weight: var(--type-body-small-emphasis-weight);
  letter-spacing: 0.02em;
}

.app-layout__desktop-action {
  display: none;
}

.hamburger {
  position: relative;
  display: inline-flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;
  gap: 4px;
  width: 16px;
  height: 16px;
}

.hamburger span {
  display: block;
  width: 100%;
  height: 1.5px;
  border-radius: 2px;
  background: currentColor;
  transform-origin: center;
  transition:
    var(--theme-transition),
    transform var(--motion-control-duration) var(--motion-ease),
    opacity var(--motion-fast-duration) var(--motion-ease);
}

.hamburger--open span:nth-child(1) {
  transform: translateY(5.5px) rotate(45deg);
}

.hamburger--open span:nth-child(2) {
  opacity: 0;
  transform: scaleX(0);
}

.hamburger--open span:nth-child(3) {
  transform: translateY(-5.5px) rotate(-45deg);
}

.app-layout__menu-button {
  min-width: 36px;
  min-height: 36px;
  transition:
    var(--theme-transition),
    background-color var(--motion-fast-duration) var(--motion-ease),
    color var(--motion-fast-duration) var(--motion-ease);
}

.app-layout__menu-button--open {
  color: var(--accent-text);
  background: var(--accent-soft);
}

.app-layout__main {
  flex: 1;
  width: 100%;
  overflow-x: hidden;
  padding: var(--page-pad-y) var(--page-pad-x)
    calc(var(--bottom-nav-h) + var(--space-24) + env(safe-area-inset-bottom));
}

/* Page swipe transitions */
.page-slide-left-enter-active,
.page-slide-right-enter-active {
  transition:
    var(--theme-transition),
    transform var(--motion-control-duration) var(--motion-ease),
    opacity var(--motion-control-duration) var(--motion-ease);
}

.page-slide-left-leave-active,
.page-slide-right-leave-active {
  transition:
    var(--theme-transition),
    transform var(--motion-fast-duration) var(--motion-ease),
    opacity var(--motion-fast-duration) var(--motion-ease);
}

.page-slide-left-enter-from {
  opacity: 0;
  transform: translateX(64px);
}

.page-slide-left-leave-to {
  opacity: 0;
  transform: translateX(-64px);
}

.page-slide-right-enter-from {
  opacity: 0;
  transform: translateX(-64px);
}

.page-slide-right-leave-to {
  opacity: 0;
  transform: translateX(64px);
}

@media (prefers-reduced-motion: reduce) {
  .page-slide-left-enter-active,
  .page-slide-left-leave-active,
  .page-slide-right-enter-active,
  .page-slide-right-leave-active {
    transition: none;
  }
}

@media (min-width: 768px) {
  .app-layout__mobile-title {
    display: none;
  }

  .app-layout__desktop-action {
    display: inline-flex;
  }

  .app-layout__menu-button {
    display: none;
  }

  .app-layout__nav {
    display: flex;
    flex-wrap: wrap;
    min-width: 0;
    align-items: center;
    gap: var(--gap-s);
  }

  .app-layout__nav-link {
    padding: var(--space-8) var(--space-12);
    border-radius: var(--radius-control-m);
    font-size: var(--type-body-medium-emphasis-size);
    letter-spacing: var(--type-body-medium-emphasis-tracking);
    line-height: var(--type-body-medium-emphasis-line);
    font-weight: var(--type-body-medium-emphasis-weight);
    color: var(--text-2);
    transition: var(--theme-transition);
  }

  .app-layout__nav-link:hover {
    background: var(--surface-hover);
    color: var(--text-1);
    transition: var(--theme-transition);
  }

  .app-layout__nav-link--active {
    color: var(--accent-text);
    background: var(--accent-soft);
    transition: var(--theme-transition);
  }

  .app-layout__main {
    padding: var(--space-24) var(--page-pad-x-desktop) var(--space-40);
  }
}

@media (max-width: 479px) {
  .brand__name {
    display: none;
  }
}
</style>
