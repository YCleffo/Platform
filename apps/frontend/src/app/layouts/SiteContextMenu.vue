<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { useTheme, type Theme } from '@/app/theme';
import { useAppearance, type Density } from '@/app/appearance';
import AppContextMenu from '@/shared/ui/menus/AppContextMenu.vue';
import type { MenuItem } from '@/shared/ui/menus/types';
import { LayoutGrid, Settings, Sun, Moon } from '@/shared/ui/icons';

const { t } = useI18n();
const router = useRouter();
const { theme, setTheme } = useTheme();
const { appearance, updateAppearance } = useAppearance();
const themes: Theme[] = ['light', 'dark', 'system'];
const densities: Density[] = ['compact', 'comfortable', 'spacious'];
const items = computed<MenuItem[]>(() => [
  { id: 'settings', label: t('nav.settings'), icon: Settings },
  { id: 'catalog', label: t('nav.catalog'), icon: LayoutGrid },
  { id: 'separator', kind: 'separator', label: '' },
  {
    id: 'theme',
    label: t('appearance.theme'),
    icon: theme.value === 'dark' ? Moon : Sun,
    children: themes.map((value) => ({
      id: `theme:${value}`,
      label: t(`appearance.theme_${value}`),
      checked: theme.value === value,
      checkType: 'radio',
    })),
  },
  {
    id: 'density',
    label: t('appearance.density'),
    children: densities.map((value) => ({
      id: `density:${value}`,
      label: t(`appearance.density_${value}`),
      checked: appearance.density === value,
      checkType: 'radio',
    })),
  },
]);
function select(item: MenuItem): void {
  if (item.id.startsWith('theme:')) setTheme(item.id.slice(6) as Theme);
  else if (item.id.startsWith('density:'))
    updateAppearance({ density: item.id.slice(8) as Density });
  else void router.push({ name: item.id });
}
</script>

<template>
  <AppContextMenu
    :items="items"
    :label="$t('menus.siteContext')"
    @select="select"
  >
    <slot />
  </AppContextMenu>
</template>
