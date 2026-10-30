import { computed, ref } from 'vue';

export type Theme = 'light' | 'dark' | 'system';

const STORAGE_KEY = 'platform.theme';

function storedTheme(): Theme {
  const value = localStorage.getItem(STORAGE_KEY);
  return value === 'light' || value === 'dark' || value === 'system' ? value : 'system';
}

const theme = ref<Theme>(storedTheme());
const systemDark = ref(false);
let media: MediaQueryList | null = null;

function applyTheme(): void {
  const resolved = theme.value === 'system' ? (systemDark.value ? 'dark' : 'light') : theme.value;
  document.documentElement.dataset.theme = resolved;
}

function ensureMedia(): void {
  if (media !== null) {
    return;
  }
  media = window.matchMedia('(prefers-color-scheme: dark)');
  systemDark.value = media.matches;
  media.addEventListener('change', (event) => {
    systemDark.value = event.matches;
    if (theme.value === 'system') {
      applyTheme();
    }
  });
}

export function initTheme(): void {
  ensureMedia();
  applyTheme();
}

export function useTheme() {
  ensureMedia();
  const resolved = computed(() =>
    theme.value === 'system' ? (systemDark.value ? 'dark' : 'light') : theme.value,
  );
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
