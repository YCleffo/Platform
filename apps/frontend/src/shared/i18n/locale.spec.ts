import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const storageKey = 'platform.locale';

beforeEach(() => {
  vi.resetModules();
  localStorage.clear();
  vi.spyOn(navigator, 'language', 'get').mockReturnValue('en-GB');
});

afterEach(() => {
  vi.restoreAllMocks();
  localStorage.clear();
});

describe('interface language preference', () => {
  it('reads a persisted browser choice before account and workspace defaults', async () => {
    localStorage.setItem(storageKey, 'en');
    const { i18n, applyAccountLocale, applyContextLocale, resetSessionLocales } =
      await import('./index');
    applyAccountLocale('ru-RU');
    applyContextLocale('ru');
    resetSessionLocales();
    expect(i18n.global.locale.value).toBe('en');
    expect(document.documentElement.lang).toBe('en');
    expect(localStorage.getItem(storageKey)).toBe('en');
  });

  it('uses workspace and account defaults without persisting either to the browser', async () => {
    const { i18n, applyAccountLocale, applyContextLocale, resetSessionLocales } =
      await import('./index');
    expect(i18n.global.locale.value).toBe('en');

    applyContextLocale('ru-RU');
    expect(i18n.global.locale.value).toBe('ru');
    applyAccountLocale('en-US');
    expect(i18n.global.locale.value).toBe('en');
    applyContextLocale('ru-RU');
    expect(i18n.global.locale.value).toBe('en');
    applyAccountLocale(null);
    expect(i18n.global.locale.value).toBe('ru');

    resetSessionLocales();
    expect(i18n.global.locale.value).toBe('en');
    expect(localStorage.getItem(storageKey)).toBeNull();
  });

  it('keeps the last manual choice when storage is disabled', async () => {
    const denied = () => {
      throw new DOMException('Storage is unavailable', 'SecurityError');
    };
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(denied);
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(denied);
    const { i18n, setLocale, applyAccountLocale, applyContextLocale } = await import('./index');

    setLocale('ru');
    setLocale('en');
    applyAccountLocale('ru-RU');
    applyContextLocale('ru-RU');
    expect(i18n.global.locale.value).toBe('en');
    expect(document.documentElement.lang).toBe('en');
  });

  it('ignores unsupported or malformed locale tags', async () => {
    vi.spyOn(navigator, 'language', 'get').mockReturnValue('fr-FR');
    const { i18n, applyAccountLocale, applyContextLocale } = await import('./index');
    applyAccountLocale('rubbish');
    applyContextLocale('ru-');
    expect(i18n.global.locale.value).toBe('en');
  });
});
