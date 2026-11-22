import { createI18n } from 'vue-i18n';
import en from './messages/en';
import ru from './messages/ru';

export const SUPPORTED_LOCALES = ['en', 'ru'] as const;
export type SupportedLocale = (typeof SUPPORTED_LOCALES)[number];

const storageKey = 'platform.locale';
const fallbackLocale = 'en';

function supportedLocale(value: string | null | undefined): SupportedLocale | undefined {
  if (!value) return;
  try {
    const language = new Intl.Locale(value).language;
    if (language === 'en' || language === 'ru') return language;
  } catch {
    // Unsupported or invalid locale tags fall through to the next source.
  }
}

function storedLocale(): SupportedLocale | undefined {
  try {
    const stored = localStorage.getItem(storageKey);
    if (stored === 'en' || stored === 'ru') return stored;
  } catch {
    // The language selector still works for the current page without storage.
  }
}

let explicitLocale = storedLocale();
let accountLocale: SupportedLocale | undefined;
let contextLocale: SupportedLocale | undefined;

// A browser choice is persisted. Server defaults last only for the current Core session.
function effectiveLocale(): SupportedLocale {
  return explicitLocale ?? accountLocale ?? contextLocale
    ?? supportedLocale(globalThis.navigator?.language) ?? fallbackLocale;
}

export const i18n = createI18n({
  legacy: false,
  locale: effectiveLocale(),
  fallbackLocale,
  messages: { en, ru },
});

function updateLocale(): void {
  const locale = effectiveLocale();
  i18n.global.locale.value = locale;
  if (globalThis.document) document.documentElement.lang = locale;
}

function syncStoredLocale(event: StorageEvent): void {
  if (event.key !== storageKey && event.key !== null) return;
  try {
    if (event.storageArea !== localStorage) return;
    const stored = localStorage.getItem(storageKey);
    explicitLocale = stored === 'en' || stored === 'ru' ? stored : undefined;
  } catch {
    return;
  }
  updateLocale();
}

export function initLocale(): void {
  updateLocale();
  window.addEventListener('storage', syncStoredLocale);
}

export function setLocale(locale: SupportedLocale): void {
  explicitLocale = locale;
  try { localStorage.setItem(storageKey, locale); } catch { /* Keep the choice in memory. */ }
  updateLocale();
}

export function applyAccountLocale(locale: string | null): void {
  accountLocale = supportedLocale(locale);
  updateLocale();
}

export function applyContextLocale(locale: string): void {
  contextLocale = supportedLocale(locale);
  updateLocale();
}

export function resetSessionLocales(): void {
  accountLocale = undefined;
  contextLocale = undefined;
  updateLocale();
}
