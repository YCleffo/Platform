import { describe, expect, it } from 'vitest';
import { initAppearance, setAccountInterfacePolicy, useAppearance } from './index';
describe('account interface policy', () => {
  it('applies only for the active Core workspace without overwriting browser preferences', () => {
    const stop = initAppearance(); const browser = useAppearance(); browser.updateAppearance({ animations: true }); const stored = localStorage.getItem('platform.appearance.v1');
    setAccountInterfacePolicy({ animations: false, transparency: false }); expect(document.documentElement.dataset.motion).toBe('reduced'); expect(document.documentElement.dataset.transparency).toBe('opaque');
    expect(browser.appearance.animations).toBe(true); expect(localStorage.getItem('platform.appearance.v1')).toBe(stored);
    setAccountInterfacePolicy(null); expect(document.documentElement.dataset.motion).toBe('full'); expect(document.documentElement.dataset.transparency).toBeUndefined(); stop();
  });
});
