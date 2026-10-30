<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/core/auth/authStore';
import { setLocale, SUPPORTED_LOCALES } from '@/shared/i18n';
import type { SupportedLocale } from '@/shared/i18n';
import Button from '@/shared/ui/Button.vue';

const auth = useAuthStore();
const router = useRouter();
const { locale } = useI18n();

function changeLocale(event: Event): void {
  const value = (event.target as HTMLSelectElement).value;
  setLocale(value as SupportedLocale);
}

async function logout(): Promise<void> {
  await auth.logout();
  await router.push({ name: 'login' });
}
</script>

<template>
  <div class="app-layout">
    <header class="app-header">
      <div class="app-header__inner">
        <RouterLink class="app-header__brand" :to="{ name: 'catalog' }">
          {{ $t('app.name') }}
        </RouterLink>
        <nav class="app-header__nav" aria-label="Main">
          <RouterLink :to="{ name: 'catalog' }">{{ $t('nav.catalog') }}</RouterLink>
          <RouterLink v-if="auth.isAuthenticated" :to="{ name: 'cabinet' }">
            {{ $t('nav.cabinet') }}
          </RouterLink>
        </nav>
        <div class="app-header__actions">
          <select
            class="app-header__locale"
            :value="locale"
            aria-label="Language"
            @change="changeLocale"
          >
            <option v-for="code in SUPPORTED_LOCALES" :key="code" :value="code">
              {{ code.toUpperCase() }}
            </option>
          </select>
          <template v-if="auth.isAuthenticated">
            <Button variant="ghost" @click="logout">{{ $t('nav.logout') }}</Button>
          </template>
          <template v-else>
            <RouterLink :to="{ name: 'login' }">{{ $t('nav.login') }}</RouterLink>
            <RouterLink :to="{ name: 'register' }">{{ $t('nav.register') }}</RouterLink>
          </template>
        </div>
      </div>
    </header>
    <main class="app-main">
      <RouterView />
    </main>
  </div>
</template>

<style scoped>
.app-layout {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

.app-header {
  background: var(--color-surface);
  border-bottom: 1px solid var(--color-border);
}

.app-header__inner {
  display: flex;
  align-items: center;
  gap: var(--space-5);
  max-width: 72rem;
  margin: 0 auto;
  padding: var(--space-3) var(--space-4);
}

.app-header__brand {
  font-weight: 700;
  color: var(--color-text);
  font-size: var(--text-lg);
}

.app-header__nav {
  display: flex;
  gap: var(--space-4);
}

.app-header__actions {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: var(--space-3);
}

.app-header__locale {
  padding: var(--space-1) var(--space-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-surface);
}

.app-main {
  flex: 1;
  width: 100%;
  max-width: 72rem;
  margin: 0 auto;
  padding: var(--space-6) var(--space-4);
}
</style>
