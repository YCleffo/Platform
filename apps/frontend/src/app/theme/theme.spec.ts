import { describe, expect, it } from 'vitest';
import { useTheme } from './index';

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
});
