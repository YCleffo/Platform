import { afterEach, describe, expect, it, vi } from 'vitest';
import { useTheme } from './index';

const transitionDescriptor = Object.getOwnPropertyDescriptor(document, 'startViewTransition');

function observeTransitions() {
  const updates: (() => Promise<void>)[] = [];
  const skipTransition = vi.fn();
  const start = vi.fn((update: () => Promise<void>) => {
    updates.push(update);
    return { ready: Promise.resolve(), finished: Promise.resolve(), skipTransition };
  });
  Object.defineProperty(document, 'startViewTransition', { configurable: true, value: start });
  return { start, updates, skipTransition };
}

afterEach(() => {
  if (transitionDescriptor) {
    Object.defineProperty(document, 'startViewTransition', transitionDescriptor);
  } else {
    Reflect.deleteProperty(document, 'startViewTransition');
  }
  document.documentElement.style.removeProperty('--ui-theme-duration');
});

describe('theme', () => {
  it('applies explicit light/dark and persists the choice', () => {
    const { setTheme, resolved, isDark } = useTheme();

    setTheme('dark');
    expect(document.documentElement.dataset.theme).toBe('dark');
    expect(localStorage.getItem('platform.theme')).toBe('dark');
    expect(isDark.value).toBe(true);

    setTheme('light');
    expect(document.documentElement.dataset.theme).toBe('light');
    expect(resolved.value).toBe('light');
  });

  it('supports system mode', () => {
    const { setTheme, theme, resolved } = useTheme();
    setTheme('system');
    expect(theme.value).toBe('system');
    expect(['light', 'dark']).toContain(resolved.value);
  });

  it('toggles between light and dark', () => {
    const { setTheme, toggleTheme, resolved } = useTheme();
    setTheme('light');
    toggleTheme();
    expect(resolved.value).toBe('dark');
    toggleTheme();
    expect(resolved.value).toBe('light');
  });

  it('persists a different preference without capturing identical colors', () => {
    const { setTheme, resolved, theme } = useTheme();
    setTheme('system');
    const { start } = observeTransitions();

    setTheme(resolved.value);

    expect(theme.value).toBe(resolved.value);
    expect(localStorage.getItem('platform.theme')).toBe(resolved.value);
    expect(start).not.toHaveBeenCalled();
  });

  it('applies a zero-duration theme immediately without a snapshot', () => {
    const { setTheme } = useTheme();
    setTheme('light');
    const { start } = observeTransitions();
    document.documentElement.style.setProperty('--ui-theme-duration', '0ms');

    setTheme('dark');

    expect(document.documentElement.dataset.theme).toBe('dark');
    expect(document.documentElement.dataset.themeChanging).toBeUndefined();
    expect(start).not.toHaveBeenCalled();
  });

  it('ignores an obsolete snapshot after returning to the painted theme', async () => {
    const { setTheme } = useTheme();
    setTheme('light');
    const { start, updates, skipTransition } = observeTransitions();

    setTheme('dark');
    expect(start).toHaveBeenCalledTimes(1);
    setTheme('light');
    await updates[0]!();

    expect(skipTransition).toHaveBeenCalledTimes(1);
    expect(start).toHaveBeenCalledTimes(1);
    expect(document.documentElement.dataset.theme).toBe('light');
    expect(document.documentElement.dataset.themeChanging).toBeUndefined();
    expect(localStorage.getItem('platform.theme')).toBe('light');
  });
});
