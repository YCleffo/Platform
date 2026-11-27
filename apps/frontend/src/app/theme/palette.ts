export type ColorMode = 'default' | 'accent' | 'custom' | 'monochrome';
export type FontFamilyKey = 'inter' | 'roboto' | 'system' | 'mono' | 'serif';

export interface PalettePreset {
  id: string;
  accent: string;
  background?: string;
  mode: ColorMode;
}

export const PALETTE_PRESETS: readonly PalettePreset[] = [
  { id: 'default_blue', accent: '#2260FF', mode: 'default' },
  { id: 'indigo_focus', accent: '#4F46E5', mode: 'accent' },
  { id: 'emerald_fresh', accent: '#059669', mode: 'accent' },
  { id: 'violet_studio', accent: '#7C3AED', mode: 'accent' },
  { id: 'rose_vivid', accent: '#E11D48', mode: 'accent' },
  { id: 'amber_warm', accent: '#D97706', mode: 'accent' },
  { id: 'mono_sapphire', accent: '#2260FF', mode: 'monochrome' },
  { id: 'mono_forest', accent: '#0F766E', mode: 'monochrome' },
  { id: 'mono_plum', accent: '#6D28D9', mode: 'monochrome' },
  { id: 'mono_graphite', accent: '#475569', mode: 'monochrome' },
] as const;

export const FONT_STACKS: Record<FontFamilyKey, { primary: string; full: string }> = {
  inter: {
    primary: 'Inter',
    full: "'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
  },
  roboto: {
    primary: 'Roboto',
    full: "'Roboto', 'Inter', system-ui, -apple-system, 'Segoe UI', Arial, sans-serif",
  },
  system: {
    primary: 'system-ui',
    full: "system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
  },
  mono: {
    primary: 'Space Mono',
    full: "'Space Mono', ui-monospace, 'Cascadia Code', Menlo, monospace",
  },
  serif: {
    primary: 'Georgia',
    full: "Georgia, 'Times New Roman', serif",
  },
};

export interface Rgb {
  r: number;
  g: number;
  b: number;
}

export function normalizeHex(input: unknown, fallback: string): string {
  if (typeof input !== 'string') return fallback;
  const trimmed = input.trim();
  const shortMatch = /^#([0-9a-fA-F]{3})$/.exec(trimmed);
  if (shortMatch) {
    const c = shortMatch[1]!;
    return `#${c[0]}${c[0]}${c[1]}${c[1]}${c[2]}${c[2]}`.toUpperCase();
  }
  if (/^#[0-9a-fA-F]{6}$/.test(trimmed)) {
    return trimmed.toUpperCase();
  }
  return fallback;
}

function hexToRgb(hex: string): Rgb {
  const normalized = normalizeHex(hex, '#2260FF');
  return {
    r: parseInt(normalized.slice(1, 3), 16),
    g: parseInt(normalized.slice(3, 5), 16),
    b: parseInt(normalized.slice(5, 7), 16),
  };
}

function rgbToHex({ r, g, b }: Rgb): string {
  const clamp = (n: number) => Math.max(0, Math.min(255, Math.round(n)));
  return `#${clamp(r).toString(16).padStart(2, '0')}${clamp(g).toString(16).padStart(2, '0')}${clamp(b).toString(16).padStart(2, '0')}`.toUpperCase();
}

function mix(hexA: string, hexB: string, weightA: number): string {
  const a = hexToRgb(hexA);
  const b = hexToRgb(hexB);
  const w = Math.max(0, Math.min(1, weightA));
  return rgbToHex({
    r: a.r * w + b.r * (1 - w),
    g: a.g * w + b.g * (1 - w),
    b: a.b * w + b.b * (1 - w),
  });
}

function withAlpha(hex: string, alpha: number): string {
  const { r, g, b } = hexToRgb(hex);
  return `rgb(${r} ${g} ${b} / ${Math.max(0, Math.min(1, alpha)).toFixed(2)})`;
}

export function relativeLuminance(hex: string): number {
  const { r, g, b } = hexToRgb(hex);
  const channel = (c: number) => {
    const s = c / 255;
    return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
  };
  return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

export function contrastRatio(hexA: string, hexB: string): number {
  const l1 = relativeLuminance(hexA);
  const l2 = relativeLuminance(hexB);
  const lighter = Math.max(l1, l2);
  const darker = Math.min(l1, l2);
  return (lighter + 0.05) / (darker + 0.05);
}

export function accessibleOnColor(backgroundHex: string): string {
  const whiteContrast = contrastRatio(backgroundHex, '#FFFFFF');
  const darkContrast = contrastRatio(backgroundHex, '#0B1020');
  return whiteContrast >= darkContrast ? '#FFFFFF' : '#0B1020';
}

function ensureTextContrast(foregroundHex: string, backgroundHex: string, minRatio = 4.6): string {
  if (contrastRatio(foregroundHex, backgroundHex) >= minRatio) return foregroundHex;
  const bgLum = relativeLuminance(backgroundHex);
  const target = bgLum > 0.45 ? '#0B1020' : '#FFFFFF';
  let candidate = foregroundHex;
  for (let step = 1; step <= 16; step++) {
    candidate = mix(target, foregroundHex, step / 16);
    if (contrastRatio(candidate, backgroundHex) >= minRatio) return candidate;
  }
  return target;
}

function ensureUiContrast(foregroundHex: string, backgroundHex: string, minRatio = 3.1): string {
  return ensureTextContrast(foregroundHex, backgroundHex, minRatio);
}

const PALETTE_VAR_KEYS = [
  '--color-primary-50',
  '--color-primary-100',
  '--color-primary-200',
  '--color-primary-300',
  '--color-primary-400',
  '--color-primary-500',
  '--color-primary-600',
  '--color-primary-700',
  '--color-primary-800',
  '--color-primary-900',
  '--color-hover-primary',
  '--color-pressed-primary',
  '--color-focus-border',
  '--color-focus-ring',
  '--color-selected-surface',
  '--color-selected-accent',
  '--color-link',
  '--color-link-hover',
  '--color-app-background',
  '--color-surface',
  '--color-white',
  '--color-surface-secondary',
  '--color-surface-hover',
  '--color-card',
  '--color-elevated',
  '--color-overlay',
  '--color-border-default',
  '--color-border-strong',
  '--color-text-primary',
  '--color-text-secondary',
  '--color-text-muted',
  '--color-text-disabled',
  '--accent',
  '--accent-hover',
  '--accent-pressed',
  '--accent-soft',
  '--accent-soft-2',
  '--accent-strong',
  '--accent-text',
  '--link',
  '--link-hover',
  '--focus-border',
  '--shadow-focus-ring',
  '--bg',
  '--surface',
  '--surface-2',
  '--surface-hover',
  '--card',
  '--overlay-surface',
  '--border',
  '--border-strong',
  '--text-1',
  '--text-2',
  '--text-3',
  '--text-disabled',
  '--selected-surface',
  '--selected-accent',
  '--on-accent',
  '--disabled-text',
  '--disabled-border',
  '--disabled-surface',
  '--color-disabled-text',
  '--color-disabled-border',
  '--color-disabled-surface',
] as const;

export function applyPaletteToRoot(
  root: HTMLElement,
  options: {
    colorMode: ColorMode;
    accentColor: string;
    backgroundColor: string;
    resolvedTheme: 'light' | 'dark';
  },
): void {
  const { colorMode, resolvedTheme } = options;

  if (colorMode === 'default') {
    for (const key of PALETTE_VAR_KEYS) {
      root.style.removeProperty(key);
    }
    delete root.dataset.colorMode;
    return;
  }

  root.dataset.colorMode = colorMode;
  const accent = normalizeHex(options.accentColor, '#2260FF');
  const isDark =
    colorMode === 'custom'
      ? relativeLuminance(normalizeHex(options.backgroundColor, '#F6F8FC')) < 0.32
      : resolvedTheme === 'dark';

  // Build accent ramp
  const p50 = mix(accent, '#FFFFFF', 0.08);
  const p100 = mix(accent, '#FFFFFF', 0.16);
  const p200 = mix(accent, '#FFFFFF', 0.34);
  const p300 = mix(accent, '#FFFFFF', 0.54);
  const p400 = mix(accent, '#FFFFFF', 0.76);
  const p500 = accent;
  const p600 = mix(accent, '#000000', 0.86);
  const p700 = mix(accent, '#000000', 0.72);
  const p800 = mix(accent, '#000000', 0.56);
  const p900 = mix(accent, '#000000', 0.4);

  const vars: Record<string, string> = {
    '--color-primary-50': p50,
    '--color-primary-100': p100,
    '--color-primary-200': p200,
    '--color-primary-300': p300,
    '--color-primary-400': p400,
    '--color-primary-500': p500,
    '--color-primary-600': p600,
    '--color-primary-700': p700,
    '--color-primary-800': p800,
    '--color-primary-900': p900,
    '--accent': isDark ? p400 : p500,
    '--accent-hover': isDark ? p300 : p600,
    '--accent-pressed': isDark ? p500 : p700,
    '--color-hover-primary': isDark ? p300 : p600,
    '--color-pressed-primary': isDark ? p500 : p700,
    '--on-accent': accessibleOnColor(isDark ? p400 : p500),
    '--shadow-focus-ring': `0px 0px 0px 1px ${withAlpha(isDark ? p300 : p500, 0.32)}`,
    '--color-focus-ring': withAlpha(isDark ? p300 : p500, 0.32),
  };

  let surfaceRef = isDark ? '#111827' : '#F6F8FC';

  if (colorMode === 'monochrome') {
    const bg = isDark ? mix(accent, '#070B14', 0.22) : mix(accent, '#FFFFFF', 0.08);
    const surface = isDark ? mix(accent, '#0B101D', 0.28) : mix(accent, '#FFFFFF', 0.03);
    const surface2 = isDark ? mix(accent, '#101728', 0.32) : mix(accent, '#FFFFFF', 0.13);
    const surfaceHover = isDark ? mix(accent, '#162038', 0.36) : mix(accent, '#FFFFFF', 0.18);
    const card = isDark ? mix(accent, '#0E1525', 0.3) : mix(accent, '#FFFFFF', 0.04);
    const border = isDark ? mix(accent, '#1E293B', 0.36) : mix(accent, '#CBD5E1', 0.24);
    const borderStrong = ensureUiContrast(
      isDark ? mix(accent, '#334155', 0.45) : mix(accent, '#94A3B8', 0.32),
      surface,
      3.1,
    );
    const text1 = ensureTextContrast(isDark ? mix(accent, '#F8FAFC', 0.14) : mix(accent, '#0B1020', 0.25), surface, 7);
    const text2 = ensureTextContrast(isDark ? mix(accent, '#CBD5E1', 0.18) : mix(accent, '#334155', 0.24), surface, 4.8);
    const text3 = ensureTextContrast(isDark ? mix(accent, '#94A3B8', 0.16) : mix(accent, '#475569', 0.22), surface, 4.6);
    const disabledSurface = surface2;
    const disabledBorder = border;
    const disabledText = ensureTextContrast(isDark ? '#64748B' : '#64748B', disabledSurface, 3.0);
    surfaceRef = surface;

    Object.assign(vars, {
      '--color-app-background': bg,
      '--color-surface': surface,
      '--color-surface-secondary': surface2,
      '--color-surface-hover': surfaceHover,
      '--color-card': card,
      '--color-elevated': card,
      '--color-overlay': card,
      '--color-border-default': border,
      '--color-border-strong': borderStrong,
      '--color-text-primary': text1,
      '--color-text-secondary': text2,
      '--color-text-muted': text3,
      '--color-text-disabled': disabledText,
      '--color-disabled-text': disabledText,
      '--color-disabled-border': disabledBorder,
      '--color-disabled-surface': disabledSurface,
      '--bg': bg,
      '--surface': surface,
      '--surface-2': surface2,
      '--surface-hover': surfaceHover,
      '--card': card,
      '--overlay-surface': card,
      '--border': border,
      '--border-strong': borderStrong,
      '--text-1': text1,
      '--text-2': text2,
      '--text-3': text3,
      '--text-disabled': disabledText,
      '--disabled-text': disabledText,
      '--disabled-border': disabledBorder,
      '--disabled-surface': disabledSurface,
    });
  } else if (colorMode === 'custom') {
    const bg = normalizeHex(options.backgroundColor, '#F6F8FC');
    const surface = isDark ? mix(bg, '#FFFFFF', 0.94) : mix(bg, '#FFFFFF', 0.25);
    const surface2 = isDark ? mix(bg, '#FFFFFF', 0.88) : mix(bg, '#0B1020', 0.95);
    const surfaceHover = isDark ? mix(bg, '#FFFFFF', 0.82) : mix(bg, '#0B1020', 0.91);
    const card = isDark ? mix(bg, '#FFFFFF', 0.92) : mix(bg, '#FFFFFF', 0.15);
    const border = isDark ? mix(bg, '#FFFFFF', 0.8) : mix(bg, '#0B1020', 0.86);
    const borderStrong = ensureUiContrast(
      isDark ? mix(bg, '#FFFFFF', 0.68) : mix(bg, '#0B1020', 0.74),
      surface,
      3.1,
    );
    const text1 = ensureTextContrast(isDark ? '#F8FAFC' : '#101828', surface, 7);
    const text2 = ensureTextContrast(isDark ? '#D0D5DD' : '#475467', surface, 4.8);
    const text3 = ensureTextContrast(isDark ? '#98A2B3' : '#475467', surface, 4.6);
    const disabledSurface = surface2;
    const disabledBorder = border;
    const disabledText = ensureTextContrast(isDark ? '#8B95A7' : '#667085', disabledSurface, 3.0);
    surfaceRef = surface;

    Object.assign(vars, {
      '--color-app-background': bg,
      '--color-surface': surface,
      '--color-surface-secondary': surface2,
      '--color-surface-hover': surfaceHover,
      '--color-card': card,
      '--color-elevated': card,
      '--color-overlay': card,
      '--color-border-default': border,
      '--color-border-strong': borderStrong,
      '--color-text-primary': text1,
      '--color-text-secondary': text2,
      '--color-text-muted': text3,
      '--color-text-disabled': disabledText,
      '--color-disabled-text': disabledText,
      '--color-disabled-border': disabledBorder,
      '--color-disabled-surface': disabledSurface,
      '--bg': bg,
      '--surface': surface,
      '--surface-2': surface2,
      '--surface-hover': surfaceHover,
      '--card': card,
      '--overlay-surface': card,
      '--border': border,
      '--border-strong': borderStrong,
      '--text-1': text1,
      '--text-2': text2,
      '--text-3': text3,
      '--text-disabled': disabledText,
      '--disabled-text': disabledText,
      '--disabled-border': disabledBorder,
      '--disabled-surface': disabledSurface,
    });
  } else {
    // 'accent' mode keeps neutral surface polarity matching theme, clearing custom bg vars
    const bgResetKeys = [
      '--color-app-background',
      '--color-surface',
      '--color-surface-secondary',
      '--color-surface-hover',
      '--color-card',
      '--color-elevated',
      '--color-overlay',
      '--color-border-default',
      '--color-border-strong',
      '--color-text-primary',
      '--color-text-secondary',
      '--color-text-muted',
      '--color-text-disabled',
      '--color-disabled-text',
      '--color-disabled-border',
      '--color-disabled-surface',
      '--bg',
      '--surface',
      '--surface-2',
      '--surface-hover',
      '--card',
      '--overlay-surface',
      '--border',
      '--border-strong',
      '--text-1',
      '--text-2',
      '--text-3',
      '--text-disabled',
      '--disabled-text',
      '--disabled-border',
      '--disabled-surface',
    ] as const;
    for (const key of bgResetKeys) {
      root.style.removeProperty(key);
    }
  }

  const softSurface = isDark ? mix(accent, surfaceRef, 0.22) : p50;
  const softSurface2 = isDark ? mix(accent, surfaceRef, 0.34) : p100;
  const accentText = ensureTextContrast(isDark ? p300 : p600, surfaceRef, 4.6);
  const focusBorder = ensureUiContrast(isDark ? p300 : p500, surfaceRef, 3.2);

  Object.assign(vars, {
    '--accent-soft': softSurface,
    '--accent-soft-2': softSurface2,
    '--accent-strong': isDark ? p300 : p600,
    '--accent-text': accentText,
    '--link': accentText,
    '--link-hover': ensureTextContrast(isDark ? p200 : p700, surfaceRef, 4.6),
    '--color-link': accentText,
    '--color-link-hover': ensureTextContrast(isDark ? p200 : p700, surfaceRef, 4.6),
    '--selected-surface': softSurface,
    '--color-selected-surface': softSurface,
    '--selected-accent': focusBorder,
    '--color-selected-accent': focusBorder,
    '--focus-border': focusBorder,
    '--color-focus-border': focusBorder,
  });

  for (const [key, value] of Object.entries(vars)) {
    root.style.setProperty(key, value);
  }
}

export function applyFontFamilyToRoot(root: HTMLElement, fontFamily: FontFamilyKey): void {
  const stack = FONT_STACKS[fontFamily] ?? FONT_STACKS.inter;
  const familyTokens = [
    '--font-sans',
    '--type-display-family',
    '--type-h1-family',
    '--type-h2-family',
    '--type-h3-family',
    '--type-body-large-family',
    '--type-body-large-emphasis-family',
    '--type-body-medium-family',
    '--type-body-medium-emphasis-family',
    '--type-body-small-family',
    '--type-body-small-emphasis-family',
    '--type-body-caption-family',
    '--type-body-ultra-small-family',
    '--type-button-s-family',
    '--type-button-m-family',
    '--type-button-l-family',
    '--type-metric-number-family',
    '--type-overline-label-family',
    '--type-link-medium-family',
    '--type-link-small-family',
    '--type-strikethrough-medium-family',
    '--type-strikethrough-small-family',
    '--type-table-text-family',
    '--type-table-header-family',
  ] as const;

  if (fontFamily === 'inter') {
    for (const token of familyTokens) {
      root.style.removeProperty(token);
    }
    return;
  }

  root.style.setProperty('--font-sans', stack.full);
  for (const token of familyTokens) {
    if (token !== '--font-sans') {
      root.style.setProperty(token, stack.primary);
    }
  }
}
