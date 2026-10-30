<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Layers, Moon, Sun } from '@lucide/vue';
import { useTheme } from '@/app/theme';
import { setLocale } from '@/shared/i18n';
import AppIconButton from '@/shared/ui/actions/AppIconButton.vue';

const { locale } = useI18n();
const { isDark, toggleTheme } = useTheme();

function toggleLocale(): void {
  setLocale(locale.value === 'ru' ? 'en' : 'ru');
}
</script>

<template>
  <div class="auth-layout">
    <header class="auth-layout__header">
      <RouterLink class="brand" :to="{ name: 'catalog' }" aria-label="Platform">
        <span class="brand__mark" aria-hidden="true">
          <Layers :size="18" />
        </span>
        <span class="brand__name">{{ $t('app.name') }}</span>
      </RouterLink>
      <div class="auth-layout__actions">
        <AppIconButton :label="isDark ? $t('theme.light') : $t('theme.dark')" @click="toggleTheme">
          <Sun v-if="isDark" :size="20" aria-hidden="true" />
          <Moon v-else :size="20" aria-hidden="true" />
        </AppIconButton>
        <AppIconButton :label="$t('theme.locale')" @click="toggleLocale">
          <span class="auth-layout__locale">{{ locale === 'ru' ? 'RU' : 'EN' }}</span>
        </AppIconButton>
      </div>
    </header>

    <main class="auth-layout__main">
      <div class="auth-layout__card">
        <RouterView />
      </div>
    </main>
  </div>
</template>

<style scoped>
.auth-layout {
  min-height: 100dvh;
  display: flex;
  flex-direction: column;
  background: var(--bg);
}

.auth-layout__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-12);
  padding: var(--space-8) var(--page-pad-x);
  background: var(--surface);
  border-bottom: 1px solid var(--border);
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

.auth-layout__actions {
  display: flex;
  align-items: center;
  gap: var(--space-4);
}

.auth-layout__locale {
  font-size: 12px;
  line-height: 16px;
  font-weight: 600;
  letter-spacing: 0.02em;
}

.auth-layout__main {
  flex: 1;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: var(--space-24) var(--page-pad-x);
}

.auth-layout__card {
  width: 100%;
  max-width: 420px;
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: var(--radius-card-l);
  box-shadow: var(--shadow-card);
  padding: var(--space-24);
}

@media (min-width: 768px) {
  .auth-layout__header {
    padding: var(--space-12) var(--space-32);
  }

  .auth-layout__main {
    align-items: center;
    padding: var(--space-48) var(--space-24);
  }
}
</style>
