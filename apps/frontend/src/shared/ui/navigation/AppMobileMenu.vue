<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch, type Component } from 'vue';
import { RouterLink, useRoute, useRouter, type RouteLocationRaw } from 'vue-router';
import { useI18n } from 'vue-i18n';
import {
  Building2,
  ChevronRight,
  Eye,
  Layers,
  LayoutGrid,
  LogIn,
  LogOut,
  Moon,
  Settings,
  ShieldCheck,
  Sun,
  Tag,
} from '@/shared/ui/icons';
import { useAuthStore } from '@/core/auth/authStore';
import { useAccountStore } from '@/core/account/accountStore';
import { useTheme } from '@/app/theme';
import { setLocale } from '@/shared/i18n';
import { lockScroll, unlockScroll } from '@/shared/composables/useScrollLock';
import { useFocusTrap } from '@/shared/composables/useFocusTrap';

interface MenuLink {
  label: string;
  to: RouteLocationRaw;
  routeName: string;
  icon: Component;
}

const open = defineModel<boolean>({ default: false });

const auth = useAuthStore();
const accountStore = useAccountStore();
const route = useRoute();
const router = useRouter();
const { t, locale } = useI18n();
const { isDark, toggleTheme } = useTheme();

const panel = ref<HTMLElement | null>(null);
const { activate, deactivate } = useFocusTrap(panel);
let overlayActive = false;

const accountId = computed(() =>
  typeof route.params.accountId === 'string' ? route.params.accountId : null,
);
const detail = computed(() => accountStore.accountDetail);
const currentRoute = computed(() => (typeof route.name === 'string' ? route.name : ''));
const accountInitials = computed(() => {
  const name = detail.value?.display_name?.trim() ?? '';
  return name === ''
    ? '•'
    : name
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
});

const mainLinks = computed<MenuLink[]>(() => [
  { label: t('nav.catalog'), to: { name: 'catalog' }, routeName: 'catalog', icon: LayoutGrid },
  { label: t('nav.cabinet'), to: { name: 'cabinet' }, routeName: 'cabinet', icon: Building2 },
  { label: t('nav.settings'), to: { name: 'settings' }, routeName: 'settings', icon: Settings },
]);

const accountLinks = computed<MenuLink[]>(() => {
  if (accountId.value === null || detail.value === null) {
    return [];
  }
  const links: MenuLink[] = [
    {
      label: t('account.overview'),
      to: { name: 'account-overview', params: { accountId: accountId.value } },
      routeName: 'account-overview',
      icon: LayoutGrid,
    },
  ];
  if (detail.value.permissions.view_members) {
    links.push({
      label: t('account.members'),
      to: { name: 'account-members', params: { accountId: accountId.value } },
      routeName: 'account-members',
      icon: Building2,
    });
  }
  if (detail.value.permissions.manage_members) {
    links.push({
      label: t('invitations.title'),
      to: { name: 'account-invitations', params: { accountId: accountId.value } },
      routeName: 'account-invitations',
      icon: Eye,
    });
  }
  if (detail.value.permissions.manage_billing) {
    links.push({
      label: t('billing.title'),
      to: { name: 'account-billing', params: { accountId: accountId.value } },
      routeName: 'account-billing',
      icon: Tag,
    });
  }
  links.push({
    label: t('usage.title'),
    to: { name: 'account-usage', params: { accountId: accountId.value } },
    routeName: 'account-usage',
    icon: Layers,
  });
  links.push({
    label: t('analytics.title'),
    to: { name: 'account-analytics', params: { accountId: accountId.value } },
    routeName: 'account-analytics',
    icon: Layers,
  });
  if (detail.value.permissions.manage_members) {
    links.push({
      label: t('audit.title'),
      to: { name: 'account-audit', params: { accountId: accountId.value } },
      routeName: 'account-audit',
      icon: ShieldCheck,
    });
  }
  return links;
});

const personalLinks = computed<MenuLink[]>(() => [
  { label: t('nav.security'), to: { name: 'security' }, routeName: 'security', icon: ShieldCheck },
  { label: t('privacy.title'), to: { name: 'privacy' }, routeName: 'privacy', icon: Eye },
  {
    label: t('appearance.appearance'),
    to: { name: 'settings' },
    routeName: 'settings',
    icon: Settings,
  },
  {
    label: t('menus.components'),
    to: { name: 'settings-components' },
    routeName: 'settings-components',
    icon: LayoutGrid,
  },
]);

const localeLabel = computed(() => (locale.value === 'ru' ? 'RU' : 'EN'));

function close(): void {
  open.value = false;
}

function activateOverlay(): void {
  if (overlayActive) {
    return;
  }
  overlayActive = true;
  document.addEventListener('keydown', onKeydown);
  lockScroll();
  void nextTick(() => {
    if (overlayActive) {
      activate();
    }
  });
}

function deactivateOverlay(): void {
  document.removeEventListener('keydown', onKeydown);
  if (overlayActive) {
    deactivate();
    overlayActive = false;
    unlockScroll();
  }
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    close();
  }
}

function toggleLocale(): void {
  setLocale(locale.value === 'ru' ? 'en' : 'ru');
}

async function logout(): Promise<void> {
  close();
  try {
    await auth.logout();
  } finally {
    await router.push({ name: 'login' });
  }
}

function goLogin(): void {
  close();
  void router.push({ name: 'login' });
}

watch(open, (value) => {
  if (value) {
    activateOverlay();
  } else {
    deactivateOverlay();
  }
});

watch(
  () => route.fullPath,
  () => {
    if (open.value) {
      close();
    }
  },
);

onBeforeUnmount(deactivateOverlay);
</script>

<template>
  <Teleport to="body">
    <Transition name="mobile-menu">
      <div
        v-if="open"
        class="mobile-menu"
        role="dialog"
        aria-modal="true"
        :aria-label="$t('nav.menuTitle')"
        @click.self="close"
      >
        <div
          ref="panel"
          class="mobile-menu__panel"
          tabindex="-1"
        >
          <!-- Account Card (Authenticated) -->
          <RouterLink
            v-if="accountId !== null && detail !== null"
            class="mobile-menu__account"
            :to="{ name: 'account-overview', params: { accountId } }"
            @click="close"
          >
            <span
              class="mobile-menu__avatar"
              aria-hidden="true"
            >
              {{ accountInitials }}
            </span>
            <div class="mobile-menu__account-copy">
              <strong class="mobile-menu__account-name">{{ detail.display_name }}</strong>
              <div class="mobile-menu__account-meta">
                <span class="mobile-menu__account-badge">{{ detail.role }}</span>
                <span class="mobile-menu__account-status">{{ detail.status }}</span>
              </div>
            </div>
            <ChevronRight
              class="mobile-menu__account-arrow"
              :size="18"
              aria-hidden="true"
            />
          </RouterLink>

          <!-- Content Sections -->
          <div class="mobile-menu__content">
            <!-- Main Navigation -->
            <nav
              class="mobile-menu__section"
              :aria-label="$t('nav.main')"
            >
              <p class="mobile-menu__section-title">{{ $t('nav.main') }}</p>
              <div class="mobile-menu__card">
                <RouterLink
                  v-for="link in mainLinks"
                  :key="link.routeName"
                  class="mobile-menu__link"
                  :class="{ 'mobile-menu__link--active': currentRoute === link.routeName }"
                  :to="link.to"
                  :aria-current="currentRoute === link.routeName ? 'page' : undefined"
                  @click="close"
                >
                  <span
                    class="mobile-menu__link-icon"
                    aria-hidden="true"
                  >
                    <component
                      :is="link.icon"
                      :size="18"
                    />
                  </span>
                  <span class="mobile-menu__link-label">{{ link.label }}</span>
                  <ChevronRight
                    class="mobile-menu__link-arrow"
                    :size="16"
                    aria-hidden="true"
                  />
                </RouterLink>
              </div>
            </nav>

            <!-- Account Navigation -->
            <nav
              v-if="accountLinks.length > 0"
              class="mobile-menu__section"
              :aria-label="$t('nav.account')"
            >
              <p class="mobile-menu__section-title">{{ $t('nav.account') }}</p>
              <div class="mobile-menu__card">
                <RouterLink
                  v-for="link in accountLinks"
                  :key="link.routeName"
                  class="mobile-menu__link"
                  :class="{ 'mobile-menu__link--active': currentRoute === link.routeName }"
                  :to="link.to"
                  :aria-current="currentRoute === link.routeName ? 'page' : undefined"
                  @click="close"
                >
                  <span
                    class="mobile-menu__link-icon"
                    aria-hidden="true"
                  >
                    <component
                      :is="link.icon"
                      :size="18"
                    />
                  </span>
                  <span class="mobile-menu__link-label">{{ link.label }}</span>
                  <ChevronRight
                    class="mobile-menu__link-arrow"
                    :size="16"
                    aria-hidden="true"
                  />
                </RouterLink>
              </div>
            </nav>

            <!-- Personal Navigation -->
            <nav
              class="mobile-menu__section"
              :aria-label="$t('nav.personal')"
            >
              <p class="mobile-menu__section-title">{{ $t('nav.personal') }}</p>
              <div class="mobile-menu__card">
                <RouterLink
                  v-for="link in personalLinks"
                  :key="link.routeName"
                  class="mobile-menu__link"
                  :class="{ 'mobile-menu__link--active': currentRoute === link.routeName }"
                  :to="link.to"
                  :aria-current="currentRoute === link.routeName ? 'page' : undefined"
                  @click="close"
                >
                  <span
                    class="mobile-menu__link-icon"
                    aria-hidden="true"
                  >
                    <component
                      :is="link.icon"
                      :size="18"
                    />
                  </span>
                  <span class="mobile-menu__link-label">{{ link.label }}</span>
                  <ChevronRight
                    class="mobile-menu__link-arrow"
                    :size="16"
                    aria-hidden="true"
                  />
                </RouterLink>
              </div>
            </nav>

            <!-- Preferences -->
            <div class="mobile-menu__section">
              <p class="mobile-menu__section-title">{{ $t('nav.preferences') }}</p>
              <div class="mobile-menu__quick">
                <button
                  type="button"
                  class="mobile-menu__quick-button"
                  @click="toggleTheme"
                >
                  <span
                    class="mobile-menu__quick-icon"
                    aria-hidden="true"
                  >
                    <Sun
                      v-if="isDark"
                      :size="18"
                    />
                    <Moon
                      v-else
                      :size="18"
                    />
                  </span>
                  <span class="mobile-menu__quick-text">
                    {{ isDark ? $t('theme.light') : $t('theme.dark') }}
                  </span>
                </button>

                <button
                  type="button"
                  class="mobile-menu__quick-button"
                  @click="toggleLocale"
                >
                  <span class="mobile-menu__locale-tag">{{ localeLabel }}</span>
                  <span class="mobile-menu__quick-text">{{ $t('theme.locale') }}</span>
                </button>
              </div>
            </div>
          </div>

          <!-- Footer Auth Button -->
          <footer class="mobile-menu__footer">
            <button
              v-if="auth.isAuthenticated"
              type="button"
              class="mobile-menu__auth mobile-menu__auth--logout"
              @click="logout"
            >
              <LogOut
                :size="18"
                aria-hidden="true"
              />
              <span>{{ $t('nav.logout') }}</span>
            </button>
            <button
              v-else
              type="button"
              class="mobile-menu__auth mobile-menu__auth--login"
              @click="goLogin"
            >
              <LogIn
                :size="18"
                aria-hidden="true"
              />
              <span>{{ $t('nav.login') }}</span>
            </button>
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.mobile-menu {
  position: fixed;
  top: 60px;
  left: 0;
  right: 0;
  bottom: 0;
  width: 100vw;
  width: 100dvw;
  height: calc(100vh - 60px);
  height: calc(100dvh - 60px);
  z-index: 850;
  display: flex;
  justify-content: flex-end;
  background: rgba(0, 0, 0, 0.45);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  isolation: isolate;
  overflow: hidden;
  transition:
    var(--theme-transition),
    opacity var(--motion-control-duration) var(--motion-ease);
}

.mobile-menu__panel {
  display: flex;
  flex-direction: column;
  width: 100%;
  max-width: 100%;
  height: 100%;
  background: var(--bg);
  background-image:
    radial-gradient(
      ellipse at 90% 0%,
      color-mix(in srgb, var(--accent) 10%, transparent) 0%,
      transparent 60%
    ),
    radial-gradient(
      ellipse at 10% 100%,
      color-mix(in srgb, var(--accent) 6%, transparent) 0%,
      transparent 60%
    );
  padding: var(--space-16) var(--page-pad-x) max(var(--space-20), env(safe-area-inset-bottom));
  overflow-y: auto;
  outline: none;
  box-shadow: -8px 0 32px rgba(0, 0, 0, 0.24);
  transition:
    var(--theme-transition),
    transform var(--motion-control-duration) var(--motion-ease);
}

@media (min-width: 480px) {
  .mobile-menu__panel {
    max-width: 400px;
    border-left: 1px solid var(--border);
  }
}

.mobile-menu__account {
  display: flex;
  align-items: center;
  gap: var(--space-12);
  margin-top: 0;
  padding: var(--space-12);
  border: 1px solid var(--border);
  border-radius: var(--radius-card-m);
  background: var(--surface);
  box-shadow: var(--shadow-soft);
  color: var(--text-1);
  text-decoration: none;
  transition:
    var(--theme-transition),
    border-color var(--motion-fast-duration) var(--motion-ease),
    background-color var(--motion-fast-duration) var(--motion-ease);
}

.mobile-menu__account:hover {
  border-color: var(--accent);
  box-shadow: 0 4px 16px rgba(34, 96, 255, 0.12);
}

.mobile-menu__account:active {
  opacity: 0.85;
}

.mobile-menu__avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: var(--radius-circle);
  background: var(--accent);
  color: var(--color-white);
  font-size: var(--type-body-medium-emphasis-size);
  font-weight: var(--type-body-medium-emphasis-weight);
  line-height: var(--type-body-medium-emphasis-line);
  letter-spacing: var(--type-body-medium-emphasis-tracking);
  box-shadow: 0 2px 10px rgba(34, 96, 255, 0.3);
  flex: 0 0 auto;
}

.mobile-menu__account-copy {
  display: grid;
  gap: 3px;
  flex: 1;
  min-width: 0;
}

.mobile-menu__account-name {
  font-size: var(--type-body-medium-emphasis-size);
  font-weight: var(--type-body-medium-emphasis-weight);
  line-height: var(--type-body-medium-emphasis-line);
  letter-spacing: var(--type-body-medium-emphasis-tracking);
  color: var(--text-1);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.mobile-menu__account-meta {
  display: flex;
  align-items: center;
  gap: var(--space-6);
  overflow: hidden;
}

.mobile-menu__account-badge {
  display: inline-flex;
  padding: 1px var(--space-8);
  border-radius: var(--radius-badge-pill);
  background: var(--accent-soft);
  color: var(--accent-text);
  font-size: var(--type-body-caption-size);
  font-weight: var(--type-body-caption-weight);
  line-height: var(--type-body-caption-line);
  letter-spacing: var(--type-body-caption-tracking);
  text-transform: capitalize;
}

.mobile-menu__account-status {
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  line-height: var(--type-body-small-line);
  letter-spacing: var(--type-body-small-tracking);
  color: var(--text-3);
  text-transform: capitalize;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.mobile-menu__account-arrow {
  color: var(--text-3);
  flex-shrink: 0;
  transition:
    var(--theme-transition),
    color var(--motion-fast-duration) var(--motion-ease);
}

.mobile-menu__account:hover .mobile-menu__account-arrow {
  color: var(--accent);
}

.mobile-menu__content {
  display: flex;
  flex-direction: column;
  gap: var(--space-12);
  padding: var(--space-12) 0;
  width: 100%;
}

.mobile-menu__section {
  display: flex;
  flex-direction: column;
  gap: var(--space-6);
}

.mobile-menu__section-title {
  padding: 0 var(--space-4);
  margin: 0;
  font-size: var(--type-body-caption-size);
  font-weight: var(--type-body-caption-weight);
  line-height: var(--type-body-caption-line);
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--text-3);
}

.mobile-menu__card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-card-m);
  overflow: hidden;
  box-shadow: var(--shadow-soft);
  transition: var(--theme-transition);
}

.mobile-menu__link {
  display: flex;
  align-items: center;
  gap: var(--space-12);
  min-height: 52px;
  padding: var(--space-10) var(--space-12);
  color: var(--text-1);
  text-decoration: none;
  border-bottom: 1px solid var(--border);
  transition:
    var(--theme-transition),
    background-color var(--motion-fast-duration) var(--motion-ease);
}

.mobile-menu__link:last-child {
  border-bottom: none;
}

.mobile-menu__link:hover {
  background: var(--surface-hover);
}

.mobile-menu__link:active {
  opacity: 0.85;
}

.mobile-menu__link--active {
  background: var(--accent-soft);
  color: var(--accent-text);
  font-weight: var(--type-body-medium-emphasis-weight);
}

.mobile-menu__link-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: var(--radius-control-m);
  background: var(--surface-2);
  color: var(--text-2);
  flex: 0 0 auto;
  transition: var(--theme-transition);
}

.mobile-menu__link--active .mobile-menu__link-icon {
  background: var(--accent);
  color: var(--color-white);
  box-shadow: 0 2px 8px rgba(34, 96, 255, 0.3);
}

.mobile-menu__link-label {
  flex: 1;
  min-width: 0;
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  line-height: var(--type-body-medium-line);
  letter-spacing: var(--type-body-medium-tracking);
}

.mobile-menu__link-arrow {
  color: var(--text-3);
  opacity: 0.6;
  flex-shrink: 0;
  transition:
    var(--theme-transition),
    color var(--motion-fast-duration) var(--motion-ease),
    opacity var(--motion-fast-duration) var(--motion-ease);
}

.mobile-menu__link:hover .mobile-menu__link-arrow {
  opacity: 1;
  color: var(--accent);
}

.mobile-menu__quick {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--space-10);
}

.mobile-menu__quick-button {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-8);
  min-height: 48px;
  padding: var(--space-8) var(--space-12);
  border: 1px solid var(--border);
  border-radius: var(--radius-card-s);
  background: var(--surface);
  color: var(--text-1);
  font-size: var(--type-body-small-emphasis-size);
  font-weight: var(--type-body-small-emphasis-weight);
  line-height: var(--type-body-small-emphasis-line);
  letter-spacing: var(--type-body-small-emphasis-tracking);
  cursor: pointer;
  box-shadow: var(--shadow-soft);
  transition:
    var(--theme-transition),
    background-color var(--motion-fast-duration) var(--motion-ease),
    border-color var(--motion-fast-duration) var(--motion-ease);
}

.mobile-menu__quick-button:hover {
  background: var(--surface-hover);
  border-color: var(--border-hover, var(--border));
}

.mobile-menu__quick-button:active {
  opacity: 0.85;
}

.mobile-menu__quick-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--accent);
}

.mobile-menu__locale-tag {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 2px 7px;
  border-radius: var(--radius-control-s);
  background: var(--accent-soft);
  color: var(--accent-text);
  font-size: var(--type-body-caption-size);
  font-weight: var(--type-body-caption-weight);
  line-height: var(--type-body-caption-line);
  letter-spacing: 0.04em;
}

.mobile-menu__footer {
  margin-top: auto;
  padding-top: var(--space-16);
  border-top: 1px solid var(--border);
}

.mobile-menu__auth {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-8);
  width: 100%;
  min-height: 50px;
  padding: var(--space-8) var(--space-16);
  border-radius: var(--radius-card-s);
  font-size: var(--type-body-medium-emphasis-size);
  font-weight: var(--type-body-medium-emphasis-weight);
  line-height: var(--type-body-medium-emphasis-line);
  letter-spacing: var(--type-body-medium-emphasis-tracking);
  cursor: pointer;
  transition:
    var(--theme-transition),
    background-color var(--motion-fast-duration) var(--motion-ease);
}

.mobile-menu__auth:active {
  opacity: 0.85;
}

.mobile-menu__auth--logout {
  background: var(--surface);
  border: 1px solid var(--border);
  color: var(--danger-text, #ef4444);
}

.mobile-menu__auth--logout:hover {
  background: var(--danger-soft, #fef2f2);
  border-color: var(--danger-border, #fca5a5);
}

.mobile-menu__auth--login {
  background: var(--accent);
  border: 1px solid transparent;
  color: var(--color-white);
  box-shadow: 0 4px 14px rgba(34, 96, 255, 0.25);
}

.mobile-menu__auth--login:hover {
  background: var(--accent-hover, #1d4ed8);
}

/* Animations */
.mobile-menu-enter-active,
.mobile-menu-leave-active {
  transition:
    var(--theme-transition),
    opacity var(--motion-control-duration) var(--motion-ease);
}

.mobile-menu-enter-active .mobile-menu__panel,
.mobile-menu-leave-active .mobile-menu__panel {
  transition:
    var(--theme-transition),
    transform var(--motion-control-duration) var(--motion-ease);
}

.mobile-menu-enter-from,
.mobile-menu-leave-to {
  opacity: 0;
}

.mobile-menu-enter-from .mobile-menu__panel,
.mobile-menu-leave-to .mobile-menu__panel {
  transform: translateX(100%);
}

@media (prefers-reduced-motion: reduce) {
  .mobile-menu-enter-active,
  .mobile-menu-leave-active,
  .mobile-menu-enter-active .mobile-menu__panel,
  .mobile-menu-leave-active .mobile-menu__panel {
    transition: none;
  }
}
</style>
