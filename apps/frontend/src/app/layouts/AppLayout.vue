<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { Building2, Layers, LayoutGrid, LogIn, LogOut, Moon, Sun } from '@lucide/vue';
import { useAuthStore } from '@/core/auth/authStore';
import { useTheme } from '@/app/theme';
import { setLocale } from '@/shared/i18n';
import AppHeader from '@/shared/ui/navigation/AppHeader.vue';
import AppBottomNav from '@/shared/ui/navigation/AppBottomNav.vue';
import type { BottomNavItem } from '@/shared/ui/navigation/AppBottomNav.vue';
import AppIconButton from '@/shared/ui/actions/AppIconButton.vue';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();
const { t, locale } = useI18n();
const { isDark, toggleTheme } = useTheme();

const sectionTitle = computed(() => {
  if (route.name === 'cabinet') {
    return t('nav.cabinet');
  }
  if (route.name === 'catalog') {
    return t('nav.catalog');
  }
  return t('app.name');
});

const bottomItems = computed<BottomNavItem[]>(() => [
  { label: t('nav.catalog'), icon: LayoutGrid, routeName: 'catalog' },
  { label: t('nav.cabinet'), icon: Building2, routeName: 'cabinet' },
]);

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
        <RouterLink class="brand" :to="{ name: 'catalog' }" aria-label="Platform">
          <span class="brand__mark" aria-hidden="true">
            <Layers :size="18" />
          </span>
          <span class="brand__name">{{ $t('app.name') }}</span>
        </RouterLink>
      </template>

      <template #center>
        <span class="app-layout__mobile-title">{{ sectionTitle }}</span>
        <nav class="app-layout__nav" aria-label="Main">
          <RouterLink class="app-layout__nav-link" :to="{ name: 'catalog' }">
            {{ $t('nav.catalog') }}
          </RouterLink>
          <RouterLink class="app-layout__nav-link" :to="{ name: 'cabinet' }">
            {{ $t('nav.cabinet') }}
          </RouterLink>
        </nav>
      </template>

      <template #actions>
        <AppIconButton :label="isDark ? $t('theme.light') : $t('theme.dark')" @click="toggleTheme">
          <Sun v-if="isDark" :size="20" aria-hidden="true" />
          <Moon v-else :size="20" aria-hidden="true" />
        </AppIconButton>
        <AppIconButton :label="$t('theme.locale')" @click="toggleLocale">
          <span class="app-layout__locale">{{ localeLabel }}</span>
        </AppIconButton>
        <AppIconButton v-if="auth.isAuthenticated" :label="$t('nav.logout')" @click="logout">
          <LogOut :size="20" aria-hidden="true" />
        </AppIconButton>
        <AppIconButton v-else :label="$t('nav.login')" @click="router.push({ name: 'login' })">
          <LogIn :size="20" aria-hidden="true" />
        </AppIconButton>
      </template>
    </AppHeader>

    <main class="app-layout__main">
      <RouterView />
    </main>

    <AppBottomNav :items="bottomItems" />
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
  gap: var(--space-8);
  color: var(--text-1);
}

.brand__mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: var(--radius-control-s);
  background: var(--accent);
  color: var(--color-white);
}

.brand__name {
  font-size: 16px;
  line-height: 20px;
  font-weight: 600;
}

.app-layout__mobile-title {
  font-size: 16px;
  line-height: 20px;
  font-weight: 600;
  color: var(--text-1);
}

.app-layout__nav {
  display: none;
}

.app-layout__locale {
  font-size: 12px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: 0.02em;
}

.app-layout__main {
  flex: 1;
  width: 100%;
  padding: var(--space-16) var(--page-pad-x) calc(var(--bottom-nav-h) + var(--space-24) + env(safe-area-inset-bottom));
}

@media (min-width: 768px) {
  .app-layout__mobile-title {
    display: none;
  }

  .app-layout__nav {
    display: flex;
    align-items: center;
    gap: var(--space-8);
  }

  .app-layout__nav-link {
    padding: var(--space-8) var(--space-12);
    border-radius: var(--radius-control-m);
    font-size: 14px;
    line-height: 20px;
    font-weight: 500;
    color: var(--text-2);
  }

  .app-layout__nav-link:hover {
    background: var(--surface-hover);
    color: var(--text-1);
  }

  .app-layout__nav-link.router-link-active {
    color: var(--accent);
    background: var(--accent-soft);
  }

  .app-layout__main {
    padding: var(--space-24) var(--space-32) var(--space-40);
  }
}
</style>
