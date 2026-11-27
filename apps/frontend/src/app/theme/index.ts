import { computed, nextTick, ref } from 'vue';
import { refreshAppearancePalette } from '@/app/appearance';

export type Theme = 'light' | 'dark' | 'system';

const STORAGE_KEY = 'platform.theme';

function storedTheme(): Theme {
  try {
    const value = localStorage.getItem(STORAGE_KEY);
    return value === 'light' || value === 'dark' || value === 'system' ? value : 'system';
  } catch { return 'system'; }
}

const theme = ref<Theme>(storedTheme());
const systemDark = ref(false);
const resolved = computed(() =>
  theme.value === 'system' ? (systemDark.value ? 'dark' : 'light') : theme.value,
);
let media: MediaQueryList | null = null;
let activeTransition: ViewTransition | undefined;
let changeId = 0;
let storageListening = false;

function applyTheme(): void {
  document.documentElement.dataset.theme = resolved.value;
  refreshAppearancePalette();
}

function changeTheme(next: Theme, persist = true): void {
  theme.value = next;
  if (persist) { try { localStorage.setItem(STORAGE_KEY, next); } catch { /* Session-only preferences still work. */ } }
  const id = ++changeId;
  activeTransition?.skipTransition();
  activeTransition = undefined;
  const root = document.documentElement;
  if (root.dataset.theme === resolved.value) {
    delete root.dataset.themeChanging;
    return;
  }
  root.dataset.themeChanging = '';
  const commit = () => {
    if (id !== changeId) return;
    applyTheme();
  };
  const withoutMotion =
    root.dataset.motion === 'reduced' ||
    root.style.getPropertyValue('--ui-theme-duration') === '0ms' ||
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (typeof document.startViewTransition === 'function' && !withoutMotion) {
    try {
      activeTransition = document.startViewTransition(async () => { commit(); await nextTick(); });
      void activeTransition.ready.catch(() => undefined);
      void activeTransition.finished.catch(() => undefined).finally(() => {
        if (id === changeId) { delete root.dataset.themeChanging; activeTransition = undefined; }
      });
      return;
    } catch { /* Atomic fallback if capture is unavailable. */ }
  }
  commit();
  // Commit all colors together before restoring hover transitions in older browsers.
  void root.offsetWidth;
  delete root.dataset.themeChanging;
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
      changeTheme('system', false);
    }
  });
}

export function initTheme(): void {
  ensureMedia();
  applyTheme();
  if (!storageListening) {
    window.addEventListener('storage', (event) => {
      if (event.key === STORAGE_KEY || event.key === null) changeTheme(storedTheme(), false);
    });
    storageListening = true;
  }
}

export function useTheme() {
  ensureMedia();
  const isDark = computed(() => resolved.value === 'dark');

  function setTheme(next: Theme): void {
    changeTheme(next);
  }

  function toggleTheme(): void {
    setTheme(isDark.value ? 'light' : 'dark');
  }

  return { theme, resolved, isDark, setTheme, toggleTheme };
}
