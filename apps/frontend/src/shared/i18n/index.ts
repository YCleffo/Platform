import { createI18n } from 'vue-i18n';
import en from './messages/en';
import ru from './messages/ru';

export const SUPPORTED_LOCALES = ['en', 'ru'] as const;
export type SupportedLocale = (typeof SUPPORTED_LOCALES)[number];

function detectLocale(): SupportedLocale {
  const stored = localStorage.getItem('platform.locale');
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
  localStorage.setItem('platform.locale', locale);
  document.documentElement.lang = locale;
}
