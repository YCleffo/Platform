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
  X,
} from '@/shared/ui/icons';
import { useAuthStore } from '@/core/auth/authStore';
import { useAccountStore } from '@/core/account/accountStore';
import { useTheme } from '@/app/theme';
import { setLocale } from '@/shared/i18n';
import { lockScroll, unlockScroll } from '@/shared/composables/useScrollLock';
import { useFocusTrap } from '@/shared/composables/useFocusTrap';
import AppIconButton from '@/shared/ui/actions/AppIconButton.vue';

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
      >
        <div
          ref="panel"
          class="mobile-menu__panel"
          tabindex="-1"
        >
          <header class="mobile-menu__header">
            <RouterLink
              class="mobile-menu__brand"
              :to="{ name: 'catalog' }"
              :aria-label="$t('app.name')"
            >
              <span
                class="mobile-menu__mark"
                aria-hidden="true"
              >
                <Layers :size="20" />
              </span>
              <span>{{ $t('app.name') }}</span>
            </RouterLink>
            <AppIconButton
              :label="$t('common.close')"
              @click="close"
            >
              <X
                :size="22"
                aria-hidden="true"
              />
            </AppIconButton>
          </header>

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
            <span class="mobile-menu__account-copy">
              <strong>{{ detail.display_name }}</strong>
              <small>{{ detail.role }} · {{ detail.status }}</small>
            </span>
            <ChevronRight
              :size="18"
              aria-hidden="true"
            />
          </RouterLink>

          <div class="mobile-menu__content">
            <nav
              class="mobile-menu__section"
              :aria-label="$t('nav.main')"
            >
              <p class="mobile-menu__section-title">{{ $t('nav.main') }}</p>
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
                    :size="20"
                  />
                </span>
                <span class="mobile-menu__link-label">{{ link.label }}</span>
                <ChevronRight
                  :size="18"
                  aria-hidden="true"
                />
              </RouterLink>
            </nav>

            <nav
              v-if="accountLinks.length > 0"
              class="mobile-menu__section"
              :aria-label="$t('nav.account')"
            >
              <p class="mobile-menu__section-title">{{ $t('nav.account') }}</p>
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
                    :size="20"
                  />
                </span>
                <span class="mobile-menu__link-label">{{ link.label }}</span>
                <ChevronRight
                  :size="18"
                  aria-hidden="true"
                />
              </RouterLink>
            </nav>

            <nav
              class="mobile-menu__section"
              :aria-label="$t('nav.personal')"
            >
              <p class="mobile-menu__section-title">{{ $t('nav.personal') }}</p>
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
                    :size="20"
                  />
                </span>
                <span class="mobile-menu__link-label">{{ link.label }}</span>
                <ChevronRight
                  :size="18"
                  aria-hidden="true"
                />
              </RouterLink>
            </nav>

            <div class="mobile-menu__section">
              <p class="mobile-menu__section-title">{{ $t('nav.preferences') }}</p>
              <div class="mobile-menu__quick">
                <button
                  type="button"
                  class="mobile-menu__quick-button"
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
                  <span>{{ isDark ? $t('theme.light') : $t('theme.dark') }}</span>
                </button>
                <button
                  type="button"
                  class="mobile-menu__quick-button"
                  @click="toggleLocale"
                >
                  <span class="mobile-menu__locale">{{ localeLabel }}</span>
                  <span>{{ $t('theme.locale') }}</span>
                </button>
              </div>
            </div>
          </div>

          <footer class="mobile-menu__footer">
            <button
              v-if="auth.isAuthenticated"
              type="button"
              class="mobile-menu__auth"
              @click="logout"
            >
              <LogOut
                :size="20"
                aria-hidden="true"
              />
              <span>{{ $t('nav.logout') }}</span>
            </button>
            <button
              v-else
              type="button"
              class="mobile-menu__auth"
              @click="goLogin"
            >
              <LogIn
                :size="20"
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
  inset: 0;
  width: 100vw;
  width: 100dvw;
  z-index: 900;
  display: flex;
  background: var(--bg);
  color: var(--text-1);
  isolation: isolate;
  overflow: hidden;
  transition: var(--theme-transition);
}

.mobile-menu::before {
  position: absolute;
  top: -120px;
  right: -100px;
  width: 320px;
  height: 320px;
  border-radius: 50%;
  background: radial-gradient(circle, var(--accent-soft) 0%, transparent 70%);
  content: '';
  pointer-events: none;
  z-index: -1;
}

.mobile-menu__panel {
  display: flex;
  flex-direction: column;
  width: 100%;
  min-height: 100dvh;
  padding: max(var(--space-12), env(safe-area-inset-top)) var(--page-pad-x)
    max(var(--space-16), env(safe-area-inset-bottom));
  overflow-y: auto;
  outline: none;
}

.mobile-menu__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-12);
  padding-bottom: var(--space-16);
  border-bottom: 1px solid var(--border);
}

.mobile-menu__brand {
  display: inline-flex;
  align-items: center;
  gap: var(--space-8);
  font-size: var(--type-h2-size);
  letter-spacing: var(--type-h2-tracking);
  line-height: var(--type-h2-line);
  font-weight: var(--type-h2-weight);
  color: var(--text-1);
}

.mobile-menu__mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: var(--radius-control-m);
  background: var(--accent);
  color: var(--color-white);
}

.mobile-menu__account {
  display: flex;
  align-items: center;
  gap: var(--space-12);
  margin-top: var(--space-16);
  padding: var(--space-12);
  border: 1px solid var(--border);
  border-radius: var(--radius-card-m);
  background: linear-gradient(135deg, var(--surface-2), var(--surface));
  color: var(--text-1);
  transition: var(--theme-transition);
}

.mobile-menu__account:hover {
  border-color: var(--accent);
  text-decoration: none;
  transition: var(--theme-transition);
}

.mobile-menu__avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: var(--accent);
  color: var(--color-white);
  font-size: var(--type-body-medium-emphasis-size);
  letter-spacing: var(--type-body-medium-emphasis-tracking);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  flex: 0 0 auto;
}

.mobile-menu__account-copy {
  display: grid;
  gap: 2px;
  flex: 1;
  min-width: 0;
}

.mobile-menu__account-copy strong,
.mobile-menu__account-copy small {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.mobile-menu__account-copy strong {
  font-size: var(--type-body-medium-emphasis-size);
  letter-spacing: var(--type-body-medium-emphasis-tracking);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
}

.mobile-menu__account-copy small {
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  color: var(--text-2);
  text-transform: capitalize;
}

.mobile-menu__content {
  display: grid;
  gap: var(--space-20);
  flex: 1;
  padding: var(--space-20) 0;
  max-width: 560px;
  width: 100%;
  margin: 0 auto;
}

.mobile-menu__section {
  display: grid;
  gap: var(--space-4);
}

.mobile-menu__section-title {
  padding: 0 var(--space-12) var(--space-4);
  font-size: var(--type-body-small-emphasis-size);
  line-height: var(--type-body-small-emphasis-line);
  font-weight: var(--type-body-small-emphasis-weight);
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--text-3);
  transition: var(--theme-transition);
}

.mobile-menu__link {
  display: flex;
  align-items: center;
  gap: var(--space-12);
  min-height: 52px;
  padding: var(--space-8) var(--space-12);
  border-radius: var(--radius-control-m);
  font-size: var(--type-body-medium-emphasis-size);
  letter-spacing: var(--type-body-medium-emphasis-tracking);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  color: var(--text-1);
  transition: var(--theme-transition);
}

.mobile-menu__link:hover {
  background: var(--surface-hover);
  text-decoration: none;
  transition: var(--theme-transition);
}

.mobile-menu__link--active {
  background: var(--accent-soft);
  color: var(--accent-text);
}

.mobile-menu__link-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: var(--radius-control-s);
  background: var(--surface-hover);
  color: currentColor;
  flex: 0 0 auto;
}

.mobile-menu__link-label {
  flex: 1;
  min-width: 0;
}

.mobile-menu__quick {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--space-8);
}

.mobile-menu__quick-button {
  display: flex;
  align-items: center;
  gap: var(--space-8);
  min-height: 48px;
  padding: var(--space-8) var(--space-12);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  background: var(--surface);
  color: var(--text-1);
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  text-align: left;
  transition: var(--theme-transition);
}

.mobile-menu__quick-button:hover {
  background: var(--surface-hover);
  transition: var(--theme-transition);
}

.mobile-menu__locale {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 28px;
  height: 24px;
  border-radius: var(--radius-control-s);
  background: var(--accent-soft);
  color: var(--accent-text);
  font-size: var(--type-body-small-emphasis-size);
  letter-spacing: var(--type-body-small-emphasis-tracking);
  line-height: var(--type-body-small-emphasis-line);
  font-weight: var(--type-body-small-emphasis-weight);
}

.mobile-menu__footer {
  padding-top: var(--space-16);
  border-top: 1px solid var(--border);
}

.mobile-menu__auth {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-8);
  width: 100%;
  min-height: 48px;
  padding: var(--space-8) var(--space-12);
  border-radius: var(--radius-control-m);
  background: var(--surface-hover);
  color: var(--text-1);
  font-size: var(--type-body-medium-emphasis-size);
  letter-spacing: var(--type-body-medium-emphasis-tracking);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  transition: var(--theme-transition);
}

.mobile-menu__auth:hover {
  background: var(--border);
  transition: var(--theme-transition);
}

.mobile-menu-enter-active,
.mobile-menu-leave-active {
  transition:
    opacity var(--motion-enter-duration) var(--motion-ease),
    var(--theme-transition);
}

.mobile-menu-enter-active .mobile-menu__panel,
.mobile-menu-leave-active .mobile-menu__panel {
  transition:
    transform var(--motion-enter-duration) var(--motion-ease),
    var(--theme-transition);
}

.mobile-menu-enter-from,
.mobile-menu-leave-to {
  opacity: 0;
}

.mobile-menu-enter-from .mobile-menu__panel,
.mobile-menu-leave-to .mobile-menu__panel {
  transform: translateY(-12px);
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
