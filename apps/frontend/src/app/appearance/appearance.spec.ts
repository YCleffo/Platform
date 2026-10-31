import { afterEach, describe, expect, it, vi } from 'vitest';
import { APPEARANCE_KEY, DEFAULT_APPEARANCE, initAppearance, normalizeAppearance, useAppearance } from './index';

let stop: (() => void) | undefined;
afterEach(() => { stop?.(); vi.unstubAllGlobals(); vi.restoreAllMocks(); useAppearance().resetAppearance(); });

describe('appearance preferences', () => {
  it('coalesces a continuous edit into one undo and does not record no-op updates', () => {
    localStorage.removeItem(APPEARANCE_KEY);
    stop = initAppearance();
    const prefs = useAppearance();
    prefs.updateAppearance({ fontScale: 1 });
    expect(prefs.canUndo.value).toBe(false);
    prefs.beginEdit();
    prefs.updateAppearance({ fontScale: 1.1 });
    prefs.updateAppearance({ fontScale: 1.2 });
    prefs.updateAppearance({ fontScale: 1.4 });
    prefs.endEdit();
    expect(prefs.canUndo.value).toBe(true);
    prefs.undoAppearance();
    expect(prefs.appearance.fontScale).toBe(1);
    expect(prefs.canUndo.value).toBe(false);
    expect(JSON.parse(localStorage.getItem(APPEARANCE_KEY)!).fontScale).toBe(1);
  });

  it('applies presets without changing motion preferences and can undo a reset', () => {
    localStorage.removeItem(APPEARANCE_KEY);
    stop = initAppearance();
    const prefs = useAppearance();
    prefs.updateAppearance({ animations: false, themeDuration: 420 });
    prefs.applyPreset('reading');
    expect(prefs.activePreset.value).toBe('reading');
    expect(prefs.appearance).toMatchObject({ fontScale: 1.2, buttonPaddingX: 20, animations: false, themeDuration: 420 });
    prefs.resetAppearance();
    expect(prefs.isDefault.value).toBe(true);
    prefs.undoAppearance();
    expect(prefs.appearance).toMatchObject({ fontScale: 1.2, animations: false, themeDuration: 420 });
    prefs.updateAppearance({ buttonPaddingX: 22 });
    expect(prefs.activePreset.value).toBe(null);
  });

  it('drops stale undo history when another tab changes the preferences', () => {
    localStorage.removeItem(APPEARANCE_KEY);
    stop = initAppearance();
    const prefs = useAppearance();
    prefs.updateAppearance({ fontScale: 1.4 });
    localStorage.setItem(APPEARANCE_KEY, JSON.stringify({ fontScale: .95 }));
    window.dispatchEvent(new StorageEvent('storage', { key: APPEARANCE_KEY }));
    expect(prefs.canUndo.value).toBe(false);
    prefs.undoAppearance();
    expect(prefs.appearance.fontScale).toBe(.95);
  });

  it('reports blocked storage immediately and keeps corrupt JSON recoverable', () => {
    localStorage.setItem(APPEARANCE_KEY, '{broken');
    stop = initAppearance();
    expect(useAppearance().storageAvailable.value).toBe(true);
    vi.stubGlobal('localStorage', { getItem: () => { throw new DOMException('Blocked', 'SecurityError'); } });
    stop = initAppearance();
    expect(useAppearance().storageAvailable.value).toBe(false);
    expect(useAppearance().isDefault.value).toBe(true);
  });

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
