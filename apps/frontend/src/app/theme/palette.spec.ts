import { describe, expect, it } from 'vitest';
import {
  accessibleOnColor,
  applyPaletteToRoot,
  contrastRatio,
  PALETTE_PRESETS,
} from './palette';

describe('palette WCAG contrast', () => {
  it('picks high-contrast button text for bright and dark custom accents', () => {
    expect(accessibleOnColor('#FDE047')).toBe('#0B1020');
    expect(accessibleOnColor('#1E293B')).toBe('#FFFFFF');

    for (const preset of PALETTE_PRESETS) {
      const onAccent = accessibleOnColor(preset.accent);
      expect(contrastRatio(onAccent, preset.accent)).toBeGreaterThanOrEqual(4.5);
    }
  });

  it('maintains WCAG AA text (>=4.5:1) and UI/focus (>=3:1) across monochrome and custom palettes', () => {
    const root = document.createElement('html');

    const configs = [
      { colorMode: 'monochrome' as const, accentColor: '#2260FF', backgroundColor: '#F6F8FC', resolvedTheme: 'light' as const },
      { colorMode: 'monochrome' as const, accentColor: '#0F766E', backgroundColor: '#0B1020', resolvedTheme: 'dark' as const },
      { colorMode: 'custom' as const, accentColor: '#FACC15', backgroundColor: '#FFFFFF', resolvedTheme: 'light' as const },
      { colorMode: 'custom' as const, accentColor: '#38BDF8', backgroundColor: '#0F172A', resolvedTheme: 'dark' as const },
      { colorMode: 'accent' as const, accentColor: '#E11D48', backgroundColor: '#F6F8FC', resolvedTheme: 'light' as const },
    ];

    for (const cfg of configs) {
      applyPaletteToRoot(root, cfg);
      const surface =
        root.style.getPropertyValue('--surface') ||
        (cfg.resolvedTheme === 'dark' ? '#111827' : '#FFFFFF');
      const text1 = root.style.getPropertyValue('--text-1');
      const text2 = root.style.getPropertyValue('--text-2');
      const text3 = root.style.getPropertyValue('--text-3');
      const accentText = root.style.getPropertyValue('--accent-text');
      const focusBorder = root.style.getPropertyValue('--focus-border');
      const accent = root.style.getPropertyValue('--accent');
      const onAccent = root.style.getPropertyValue('--on-accent');

      expect(contrastRatio(onAccent, accent)).toBeGreaterThanOrEqual(4.5);
      expect(contrastRatio(accentText, surface)).toBeGreaterThanOrEqual(4.5);
      expect(contrastRatio(focusBorder, surface)).toBeGreaterThanOrEqual(3.0);

      if (text1 && text2 && text3) {
        expect(contrastRatio(text1, surface)).toBeGreaterThanOrEqual(4.5);
        expect(contrastRatio(text2, surface)).toBeGreaterThanOrEqual(4.5);
        expect(contrastRatio(text3, surface)).toBeGreaterThanOrEqual(4.5);
      }
    }
  });
});
