import { afterEach, describe, expect, it, vi } from 'vitest';
import { APPEARANCE_KEY, DEFAULT_APPEARANCE, initAppearance, normalizeAppearance, useAppearance } from './index';

let stop: (() => void) | undefined;
afterEach(() => { stop?.(); vi.unstubAllGlobals(); vi.restoreAllMocks(); useAppearance().resetAppearance(); });

describe('appearance preferences', () => {
  it('bounds untrusted stored values and drops unknown fields', () => {
    const value = normalizeAppearance({ fontScale: 99, spacingScale: -1, themeDuration: 'url(https://invalid)',
      radiusScale: NaN, density: 'unknown', buttonPaddingX: Infinity, extra: 'ignored' });
    expect(value.fontScale).toBe(1.4);
    expect(value.spacingScale).toBe(.75);
    expect(value.themeDuration).toBe(DEFAULT_APPEARANCE.themeDuration);
    expect(value.radiusScale).toBe(1);
    expect(value.density).toBe('comfortable');
    expect(value.buttonPaddingX).toBe(16);
    expect(value).not.toHaveProperty('extra');
  });

  it('restores and synchronizes CSS, persists changes and resets defaults', () => {
    localStorage.setItem(APPEARANCE_KEY, JSON.stringify({ density: 'compact', fontScale: 1.2 }));
    stop = initAppearance();
    const { appearance, updateAppearance, resetAppearance } = useAppearance();
    expect(appearance.density).toBe('compact');
    expect(document.documentElement.style.getPropertyValue('--ui-font-scale')).toBe('1.2');
    updateAppearance({ animationSpeed: 2, shadows: 'none', buttonPaddingX: 24 });
    expect(JSON.parse(localStorage.getItem(APPEARANCE_KEY)!)).toMatchObject({ animationSpeed: 2, buttonPaddingX: 24 });
    expect(document.documentElement.style.getPropertyValue('--ui-motion-scale')).toBe('0.5');
    expect(document.documentElement.dataset.shadows).toBe('none');
    resetAppearance();
    expect({ ...appearance }).toEqual(DEFAULT_APPEARANCE);
  });

  it('handles corrupt JSON, blocked storage and another tab clearing preferences', () => {
    localStorage.setItem(APPEARANCE_KEY, '{invalid');
    stop = initAppearance();
    const { appearance, updateAppearance, storageAvailable } = useAppearance();
    expect({ ...appearance }).toEqual(DEFAULT_APPEARANCE);
    updateAppearance({ density: 'spacious' });
    localStorage.removeItem(APPEARANCE_KEY);
    window.dispatchEvent(new StorageEvent('storage', { key: APPEARANCE_KEY, newValue: null }));
    expect(appearance.density).toBe('comfortable');
    vi.stubGlobal('localStorage', { setItem: () => { throw new Error('blocked'); } });
    updateAppearance({ fontScale: 1.3 });
    expect(appearance.fontScale).toBe(1.3);
    expect(storageAvailable.value).toBe(false);
  });

  it('honors live system reduced motion and explicit animation disabling', () => {
    let onChange: ((event: MediaQueryListEvent) => void) | undefined;
    vi.spyOn(window, 'matchMedia').mockReturnValue({ matches: true,
      addEventListener: (_: string, callback: EventListenerOrEventListenerObject) => { onChange = callback as (event: MediaQueryListEvent) => void; },
      removeEventListener: vi.fn(),
    } as unknown as MediaQueryList);
    stop = initAppearance();
    expect(document.documentElement.dataset.motion).toBe('reduced');
    onChange?.({ matches: false } as MediaQueryListEvent);
    expect(document.documentElement.dataset.motion).toBe('full');
    useAppearance().updateAppearance({ animations: false });
    expect(document.documentElement.dataset.motion).toBe('reduced');
    expect(document.documentElement.style.getPropertyValue('--ui-motion-scale')).toBe('0');
  });
});
