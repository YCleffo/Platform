import { computed, reactive, readonly, ref } from 'vue';

export type Density = 'compact' | 'comfortable' | 'spacious';
export type Shadows = 'none' | 'subtle' | 'normal' | 'strong';
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
}

export const APPEARANCE_KEY = 'platform.appearance.v1';
export const DEFAULT_APPEARANCE: Readonly<Appearance> = Object.freeze({
  density: 'comfortable', fontScale: 1, spacingScale: 1, radiusScale: 1,
  buttonPaddingX: 16, buttonPaddingY: 10, shadows: 'normal', animations: true,
  animationSpeed: 1, themeDuration: 200,
});

export const APPEARANCE_PRESETS = {
  default: { density: 'comfortable', fontScale: 1, spacingScale: 1, buttonPaddingX: 16, buttonPaddingY: 10 },
  compact: { density: 'compact', fontScale: 1, spacingScale: 1, buttonPaddingX: 16, buttonPaddingY: 10 },
  reading: { density: 'comfortable', fontScale: 1.2, spacingScale: 1.1, buttonPaddingX: 20, buttonPaddingY: 12 },
} satisfies Record<string, Partial<Appearance>>;
export type AppearancePreset = keyof typeof APPEARANCE_PRESETS;

function bounded(value: unknown, fallback: number, min: number, max: number): number {
  return typeof value === 'number' && Number.isFinite(value) ? Math.max(min, Math.min(max, value)) : fallback;
}

export function normalizeAppearance(value: unknown): Appearance {
  const v = typeof value === 'object' && value !== null ? value as Record<string, unknown> : {};
  const d = DEFAULT_APPEARANCE;
  return {
    density: v.density === 'compact' || v.density === 'spacious' ? v.density : d.density,
    fontScale: bounded(v.fontScale, d.fontScale, .9, 1.4),
    spacingScale: bounded(v.spacingScale, d.spacingScale, .75, 1.25),
    radiusScale: bounded(v.radiusScale, d.radiusScale, 0, 1.5),
    buttonPaddingX: bounded(v.buttonPaddingX, d.buttonPaddingX, 8, 32),
    buttonPaddingY: bounded(v.buttonPaddingY, d.buttonPaddingY, 6, 16),
    shadows: v.shadows === 'none' || v.shadows === 'subtle' || v.shadows === 'strong' ? v.shadows : d.shadows,
    animations: typeof v.animations === 'boolean' ? v.animations : d.animations,
    animationSpeed: bounded(v.animationSpeed, d.animationSpeed, .5, 2),
    themeDuration: bounded(v.themeDuration, d.themeDuration, 0, 600),
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
  root.dataset.motion = !state.animations || systemReduced.value ? 'reduced' : 'full';
  const values: Record<string, string> = {
    '--ui-density-scale': String({ compact: .85, comfortable: 1, spacious: 1.15 }[state.density]),
    '--ui-font-scale': String(state.fontScale), '--ui-spacing-scale': String(state.spacingScale),
    '--ui-radius-scale': String(state.radiusScale),
    '--ui-button-padding-x': `${state.buttonPaddingX}px`, '--ui-button-padding-y': `${state.buttonPaddingY}px`,
    '--ui-shadow-strength': String({ none: 0, subtle: .6, normal: 1, strong: 1.4 }[state.shadows]),
    '--ui-motion-scale': String(!state.animations || systemReduced.value ? 0 : 1 / state.animationSpeed),
    '--ui-theme-duration': `${state.themeDuration}ms`,
  };
  for (const [key, value] of Object.entries(values)) root.style.setProperty(key, value);
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
const activePreset = computed(() => (Object.keys(APPEARANCE_PRESETS) as AppearancePreset[]).find(key =>
  Object.entries(APPEARANCE_PRESETS[key]).every(([name, value]) => state[name as keyof Appearance] === value)) ?? null);

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
  return { appearance: readonly(state), systemReduced: readonly(systemReduced),
    storageAvailable: readonly(storageAvailable), updateAppearance,
    beginEdit, endEdit, undoAppearance, canUndo, isDefault, activePreset,
    applyPreset: (preset: AppearancePreset) => { endEdit(); updateAppearance(APPEARANCE_PRESETS[preset]); },
    resetAppearance: () => updateAppearance({ ...DEFAULT_APPEARANCE }) };
}

if (import.meta.hot) import.meta.hot.dispose(() => stop?.());
