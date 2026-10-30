import { computed, ref } from 'vue';

export type Theme = 'light' | 'dark' | 'system';

const STORAGE_KEY = 'platform.theme';

function storedTheme(): Theme {
  const value = localStorage.getItem(STORAGE_KEY);
  return value === 'light' || value === 'dark' || value === 'system' ? value : 'system';
}

const theme = ref<Theme>(storedTheme());
const media = window.matchMedia('(prefers-color-scheme: dark)');

function systemDark(): boolean {
  return media.matches;
}

function applyTheme(): void {
  const resolved = theme.value === 'system' ? (systemDark() ? 'dark' : 'light') : theme.value;
  document.documentElement.dataset.theme = resolved;
}

export function initTheme(): void {
  applyTheme();
  media.addEventListener('change', () => {
    if (theme.value === 'system') {
      applyTheme();
    }
  });
}

export function useTheme() {
  const resolved = computed(() => (theme.value === 'system' ? (systemDark() ? 'dark' : 'light') : theme.value));
  const isDark = computed(() => resolved.value === 'dark');

  function setTheme(next: Theme): void {
    theme.value = next;
    localStorage.setItem(STORAGE_KEY, next);
    applyTheme();
  }

  function toggleTheme(): void {
    setTheme(isDark.value ? 'light' : 'dark');
  }

  return { theme, resolved, isDark, setTheme, toggleTheme };
}
