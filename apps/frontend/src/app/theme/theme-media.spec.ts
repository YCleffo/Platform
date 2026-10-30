import { afterEach, describe, expect, it, vi } from 'vitest';

type ChangeListener = (event: MediaQueryListEvent) => void;

describe('theme system preference', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.resetModules();
  });

  it('reacts to matchMedia change events', async () => {
    let listener: ChangeListener | null = null;
    const media = {
      matches: false,
      media: '(prefers-color-scheme: dark)',
      onchange: null,
      addEventListener: (_type: string, callback: ChangeListener) => {
        listener = callback;
      },
      removeEventListener: () => {},
      addListener: () => {},
      removeListener: () => {},
      dispatchEvent: () => false,
    } as unknown as MediaQueryList;

    vi.stubGlobal('matchMedia', vi.fn(() => media));
    vi.resetModules();

    const { initTheme, useTheme } = await import('./index');
    initTheme();
    const { setTheme, resolved, isDark } = useTheme();
    setTheme('system');

    expect(resolved.value).toBe('light');
    expect(listener).not.toBeNull();

    (listener as unknown as ChangeListener)({ matches: true } as MediaQueryListEvent);
    expect(isDark.value).toBe(true);
    expect(resolved.value).toBe('dark');
    expect(document.documentElement.dataset.theme).toBe('dark');

    (listener as unknown as ChangeListener)({ matches: false } as MediaQueryListEvent);
    expect(isDark.value).toBe(false);
    expect(resolved.value).toBe('light');
    expect(document.documentElement.dataset.theme).toBe('light');
  });
});
