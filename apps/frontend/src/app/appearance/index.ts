import { computed, reactive, readonly, ref } from 'vue';
import {
  applyFontFamilyToRoot,
  applyPaletteToRoot,
  FONT_STACKS,
  normalizeHex,
  type ColorMode,
  type FontFamilyKey,
} from '@/app/theme/palette';

export type Density = 'compact' | 'comfortable' | 'spacious';
export type Shadows = 'none' | 'subtle' | 'normal' | 'strong';
export type { ColorMode, FontFamilyKey };
export interface Appearance {
  density: Density;
  fontScale: number;
  spacingScale: number;
  radiusScale: number;
  buttonPaddingX: number;
  buttonPaddingY: number;
  shadows: Shadows;
  animations: boolean;
  animationSpeed: number;
  themeDuration: number;
  fontFamily: FontFamilyKey;
  colorMode: ColorMode;
  accentColor: string;
  backgroundColor: string;
}

export const APPEARANCE_KEY = 'platform.appearance.v1';
export const DEFAULT_APPEARANCE: Readonly<Appearance> = Object.freeze({
  density: 'comfortable',
  fontScale: 1,
  spacingScale: 1,
  radiusScale: 1,
  buttonPaddingX: 16,
  buttonPaddingY: 10,
  shadows: 'normal',
  animations: true,
  animationSpeed: 1,
  themeDuration: 200,
  fontFamily: 'inter',
  colorMode: 'default',
  accentColor: '#2260FF',
  backgroundColor: '#F6F8FC',
});

export const APPEARANCE_PRESETS = {
  default: { density: 'comfortable', fontScale: 1, spacingScale: 1, buttonPaddingX: 16, buttonPaddingY: 10 },
  compact: { density: 'compact', fontScale: 1, spacingScale: 1, buttonPaddingX: 16, buttonPaddingY: 10 },
  reading: { density: 'comfortable', fontScale: 1.2, spacingScale: 1.1, buttonPaddingX: 20, buttonPaddingY: 12 },
} satisfies Record<string, Partial<Appearance>>;
export type AppearancePreset = keyof typeof APPEARANCE_PRESETS;

const NUMERIC_STEPS = {
  fontScale: { min: 0.85, max: 1.4, step: 0.05 },
  spacingScale: { min: 0.75, max: 1.25, step: 0.05 },
  radiusScale: { min: 0, max: 1.5, step: 0.1 },
  buttonPaddingX: { min: 8, max: 32, step: 1 },
  buttonPaddingY: { min: 6, max: 16, step: 1 },
  animationSpeed: { min: 0.5, max: 2, step: 0.25 },
  themeDuration: { min: 0, max: 600, step: 20 },
} as const;

function bounded(value: unknown, fallback: number, min: number, max: number): number {
  return typeof value === 'number' && Number.isFinite(value) ? Math.max(min, Math.min(max, value)) : fallback;
}

/**
 * Native range inputs snap to `min + n * step`. Stored preferences must use the
 * same grid so the thumb, output and applied CSS cannot disagree.
 */
function stepped(
  value: unknown,
  fallback: number,
  limits: { min: number; max: number; step: number },
): number {
  const current = bounded(value, fallback, limits.min, limits.max);
  const aligned = limits.min + Math.round((current - limits.min) / limits.step) * limits.step;
  return Number(Math.max(limits.min, Math.min(limits.max, aligned)).toFixed(6));
}

function isColorMode(value: unknown): value is ColorMode {
  return value === 'default' || value === 'accent' || value === 'custom' || value === 'monochrome';
}

function isFontFamily(value: unknown): value is FontFamilyKey {
  return typeof value === 'string' && value in FONT_STACKS;
}

export function normalizeAppearance(value: unknown): Appearance {
  const v = typeof value === 'object' && value !== null ? (value as Record<string, unknown>) : {};
  const d = DEFAULT_APPEARANCE;
  return {
    density: v.density === 'compact' || v.density === 'spacious' ? v.density : d.density,
    fontScale: stepped(v.fontScale, d.fontScale, NUMERIC_STEPS.fontScale),
    spacingScale: stepped(v.spacingScale, d.spacingScale, NUMERIC_STEPS.spacingScale),
    radiusScale: stepped(v.radiusScale, d.radiusScale, NUMERIC_STEPS.radiusScale),
    buttonPaddingX: stepped(v.buttonPaddingX, d.buttonPaddingX, NUMERIC_STEPS.buttonPaddingX),
    buttonPaddingY: stepped(v.buttonPaddingY, d.buttonPaddingY, NUMERIC_STEPS.buttonPaddingY),
    shadows: v.shadows === 'none' || v.shadows === 'subtle' || v.shadows === 'strong' ? v.shadows : d.shadows,
    animations: typeof v.animations === 'boolean' ? v.animations : d.animations,
    animationSpeed: stepped(v.animationSpeed, d.animationSpeed, NUMERIC_STEPS.animationSpeed),
    themeDuration: stepped(v.themeDuration, d.themeDuration, NUMERIC_STEPS.themeDuration),
    fontFamily: isFontFamily(v.fontFamily) ? v.fontFamily : d.fontFamily,
    colorMode: isColorMode(v.colorMode) ? v.colorMode : d.colorMode,
    accentColor: normalizeHex(v.accentColor, d.accentColor),
    backgroundColor: normalizeHex(v.backgroundColor, d.backgroundColor),
  };
}

const storageAvailable = ref(true);
function readStored(): Appearance {
  let raw: string | null;
  try { raw = localStorage.getItem(APPEARANCE_KEY); storageAvailable.value = true; }
  catch { storageAvailable.value = false; return { ...DEFAULT_APPEARANCE }; }
  try { return normalizeAppearance(JSON.parse(raw ?? 'null')); }
  catch { return { ...DEFAULT_APPEARANCE }; }
}

const state = reactive<Appearance>(readStored());
const systemReduced = ref(false);
let accountInterfacePolicy: { animations: boolean; transparency: boolean } | null = null;
/** Session-only policy; never writes account data into browser appearance preferences. */
export function setAccountInterfacePolicy(policy: { animations: boolean; transparency: boolean } | null): void {
  accountInterfacePolicy = policy; apply();
}
let stop: (() => void) | undefined;
const history = ref<Appearance[]>([]);
let editSnapshot: Appearance | undefined;
function equal(a: Appearance, b: Appearance): boolean { return JSON.stringify(a) === JSON.stringify(b); }
function remember(value: Appearance): void { history.value = [...history.value.slice(-19), { ...value }]; }
function beginEdit(): void { if (!editSnapshot) editSnapshot = { ...state }; }
function endEdit(): void {
  if (editSnapshot && !equal(editSnapshot, state)) remember(editSnapshot);
  editSnapshot = undefined;
}

function apply(): void {
  const root = document.documentElement;
  root.dataset.density = state.density;
  root.dataset.shadows = state.shadows;
  const reduced = !state.animations || systemReduced.value || accountInterfacePolicy?.animations === false;
  root.dataset.motion = reduced ? 'reduced' : 'full';
  if (accountInterfacePolicy?.transparency === false) root.dataset.transparency = 'opaque';
  else delete root.dataset.transparency;
  const values: Record<string, string> = {
    '--ui-density-scale': String({ compact: 0.85, comfortable: 1, spacious: 1.15 }[state.density]),
    '--ui-font-scale': String(state.fontScale),
    '--ui-spacing-scale': String(state.spacingScale),
    '--ui-radius-scale': String(state.radiusScale),
    '--ui-button-padding-x': `${state.buttonPaddingX}px`,
    '--ui-button-padding-y': `${state.buttonPaddingY}px`,
    '--ui-shadow-strength': String({ none: 0, subtle: 0.6, normal: 1, strong: 1.4 }[state.shadows]),
    '--ui-motion-scale': String(reduced ? 0 : 1 / state.animationSpeed),
    '--ui-theme-duration': `${state.themeDuration}ms`,
  };
  for (const [key, value] of Object.entries(values)) root.style.setProperty(key, value);

  const resolvedTheme = root.dataset.theme === 'dark' ? 'dark' : 'light';
  applyFontFamilyToRoot(root, state.fontFamily);
  applyPaletteToRoot(root, {
    colorMode: state.colorMode,
    accentColor: state.accentColor,
    backgroundColor: state.backgroundColor,
    resolvedTheme,
  });
}

/** Re-applies dynamic palette after a light/dark theme switch. */
export function refreshAppearancePalette(): void {
  apply();
}

function persist(): void {
  apply();
  try {
    localStorage.setItem(APPEARANCE_KEY, JSON.stringify(state));
    storageAvailable.value = true;
  } catch { storageAvailable.value = false; }
}
function updateAppearance(patch: Partial<Appearance>): void {
  const next = normalizeAppearance({ ...state, ...patch });
  if (equal(next, state)) return;
  if (!editSnapshot) remember({ ...state });
  Object.assign(state, next);
  persist();
}
function undoAppearance(): void {
  endEdit();
  const previous = history.value.pop();
  if (previous) { Object.assign(state, previous); persist(); }
}
const canUndo = computed(() => history.value.length > 0);
const isDefault = computed(() => equal(state, { ...DEFAULT_APPEARANCE }));
const activePreset = computed(() =>
  (Object.keys(APPEARANCE_PRESETS) as AppearancePreset[]).find((key) =>
    Object.entries(APPEARANCE_PRESETS[key]).every(([name, value]) => state[name as keyof Appearance] === value),
  ) ?? null,
);

export function initAppearance(): () => void {
  stop?.();
  history.value = []; editSnapshot = undefined;
  Object.assign(state, readStored());
  const media = window.matchMedia('(prefers-reduced-motion: reduce)');
  systemReduced.value = media.matches;
  const onMotion = (event: MediaQueryListEvent) => { systemReduced.value = event.matches; apply(); };
  const onStorage = (event: StorageEvent) => {
    if (event.key === APPEARANCE_KEY || event.key === null) { history.value = []; editSnapshot = undefined; Object.assign(state, readStored()); apply(); }
  };
  media.addEventListener('change', onMotion);
  window.addEventListener('storage', onStorage);
  apply();
  stop = () => { media.removeEventListener('change', onMotion); window.removeEventListener('storage', onStorage); };
  return stop;
}

export function useAppearance() {
  return {
    appearance: readonly(state),
    systemReduced: readonly(systemReduced),
    storageAvailable: readonly(storageAvailable),
    updateAppearance,
    beginEdit,
    endEdit,
    undoAppearance,
    canUndo,
    isDefault,
    activePreset,
    applyPreset: (preset: AppearancePreset) => { endEdit(); updateAppearance(APPEARANCE_PRESETS[preset]); },
    resetAppearance: () => updateAppearance({ ...DEFAULT_APPEARANCE }),
  };
}

if (import.meta.hot) import.meta.hot.dispose(() => stop?.());
