<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Layers, Moon, Settings, Sun } from '@/shared/ui/icons';
import { useTheme } from '@/app/theme';
import { useRouter } from 'vue-router';
import { setLocale } from '@/shared/i18n';
import AppIconButton from '@/shared/ui/actions/AppIconButton.vue';
import SiteContextMenu from './SiteContextMenu.vue';

const { locale } = useI18n();
const { isDark, toggleTheme } = useTheme();
const router = useRouter();

function toggleLocale(): void {
  setLocale(locale.value === 'ru' ? 'en' : 'ru');
}
</script>

<template>
  <SiteContextMenu>
  <div class="auth-layout">
    <header class="auth-layout__header">
      <RouterLink class="brand" :to="{ name: 'catalog' }" :aria-label="$t('app.name')">
        <span class="brand__mark" aria-hidden="true">
          <Layers :size="18" />
        </span>
        <span class="brand__name">{{ $t('app.name') }}</span>
      </RouterLink>
      <div class="auth-layout__actions">
        <AppIconButton :label="$t('nav.settings')" @click="router.push({ name: 'settings' })"><Settings :size="20" aria-hidden="true" /></AppIconButton>
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
  </SiteContextMenu>
</template>

<style scoped>
.auth-layout {
  min-height: 100dvh;
  display: flex;
  flex-direction: column;
  background: var(--bg);
  transition: var(--theme-transition);
}

.auth-layout__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--gap-l);
  padding: var(--space-8) var(--page-pad-x);
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  transition: var(--theme-transition);
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
  width: 32px;
  height: 32px;
  border-radius: var(--radius-control-s);
  background: var(--accent);
  color: var(--color-white);
  transition: var(--theme-transition);
}

.brand__name {
  font-size: var(--type-h3-size);
  line-height: var(--type-h3-line);
  font-weight: var(--type-h3-weight);
}

.auth-layout__actions {
  display: flex;
  align-items: center;
  gap: var(--gap-xs);
}

.auth-layout__locale {
  font-size: var(--type-body-small-emphasis-size);
  line-height: var(--type-body-small-emphasis-line);
  font-weight: var(--type-body-small-emphasis-weight);
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
  transition: var(--theme-transition);
}

@media (min-width: 768px) {
  .auth-layout__header {
    padding: var(--space-12) var(--page-pad-x-desktop);
  }

  .auth-layout__main {
    align-items: center;
    padding: var(--space-48) var(--page-pad-x-tablet);
  }
}
@media (max-width: 479px) { .brand__name { display: none; } }
</style>
