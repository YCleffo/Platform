import { createI18n } from 'vue-i18n';
import en from './messages/en';
import ru from './messages/ru';

export const SUPPORTED_LOCALES = ['en', 'ru'] as const;
export type SupportedLocale = (typeof SUPPORTED_LOCALES)[number];

function detectLocale(): SupportedLocale {
  let stored: string | null = null;
  try { stored = localStorage.getItem('platform.locale'); } catch { /* Browser language remains available. */ }
  if (stored === 'en' || stored === 'ru') {
    return stored;
  }
  const browser = navigator.language.slice(0, 2).toLowerCase();
  return browser === 'ru' ? 'ru' : 'en';
}

export const i18n = createI18n({
  legacy: false,
  locale: detectLocale(),
  fallbackLocale: 'en',
  messages: { en, ru },
});

export function setLocale(locale: SupportedLocale): void {
  i18n.global.locale.value = locale;
  try { localStorage.setItem('platform.locale', locale); } catch { /* Session-only language remains available. */ }
  document.documentElement.lang = locale;
}
