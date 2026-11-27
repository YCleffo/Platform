<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  DEFAULT_APPEARANCE,
  useAppearance,
  type AppearancePreset,
  type ColorMode,
  type Density,
  type FontFamilyKey,
  type Shadows,
} from '@/app/appearance';
import {
  PALETTE_PRESETS,
  contrastRatio,
  normalizeHex,
  type PalettePreset,
} from '@/app/theme/palette';
import { useTheme, type Theme } from '@/app/theme';
import { setLocale } from '@/shared/i18n';
import { LayoutGrid, Moon, Sun } from '@/shared/ui/icons';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppDisclosure from '@/shared/ui/surfaces/AppDisclosure.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppRange from '@/shared/ui/fields/AppRange.vue';
import AppSwitch from '@/shared/ui/fields/AppSwitch.vue';
import AppCheckbox from '@/shared/ui/fields/AppCheckbox.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';

const { locale, t } = useI18n();
const {
  appearance,
  updateAppearance,
  systemReduced,
  applyPreset,
  activePreset,
  beginEdit,
  endEdit,
} = useAppearance();
const { theme, resolved, setTheme } = useTheme();
const themes = [
  { value: 'light', icon: Sun },
  { value: 'dark', icon: Moon },
  { value: 'system', icon: LayoutGrid },
] as const;
const densities: Density[] = ['compact', 'comfortable', 'spacious'];
const shadows: Shadows[] = ['none', 'subtle', 'normal', 'strong'];
const presets: AppearancePreset[] = ['default', 'compact', 'reading'];
const colorModes: ColorMode[] = ['default', 'accent', 'custom', 'monochrome'];
const fontFamilies: FontFamilyKey[] = ['inter', 'roboto', 'mono', 'system', 'serif'];
const advancedOpen = ref(true);
const numeric = [
  { key: 'fontScale', id: 'font-scale', min: 0.9, max: 1.4, step: 0.05, unit: '%' },
  { key: 'spacingScale', id: 'spacing-scale', min: 0.75, max: 1.25, step: 0.05, unit: '%' },
  { key: 'buttonPaddingX', id: 'button-x', min: 8, max: 32, step: 1, unit: 'px' },
  { key: 'buttonPaddingY', id: 'button-y', min: 6, max: 16, step: 1, unit: 'px' },
  { key: 'radiusScale', id: 'radius-scale', min: 0, max: 1.5, step: 0.1, unit: '%' },
] as const;
const previewName = ref('Platform');
const dialogOpen = ref(false);
const spinning = ref(false);
const previewSelected = ref(true);
const motionOff = computed(() => !appearance.animations || systemReduced.value);

const accentHexDraft = computed({
  get: () => appearance.accentColor,
  set: (value: string) => {
    const clean = normalizeHex(value, '');
    if (clean) updateAppearance({ accentColor: clean });
  },
});

const backgroundHexDraft = computed({
  get: () => appearance.backgroundColor,
  set: (value: string) => {
    const clean = normalizeHex(value, '');
    if (clean) updateAppearance({ backgroundColor: clean });
  },
});

const liveContrast = computed(() => {
  const bg =
    appearance.colorMode === 'custom'
      ? appearance.backgroundColor
      : resolved.value === 'dark'
        ? '#0E1422'
        : '#FFFFFF';
  const ratio = contrastRatio(appearance.accentColor, bg);
  return {
    ratio: ratio.toFixed(1),
    level: ratio >= 7 ? 'AAA' : ratio >= 4.5 ? 'AA' : 'AA Large',
  };
});

function isColorPresetActive(preset: PalettePreset): boolean {
  if (appearance.colorMode !== preset.mode) return false;
  if (preset.mode === 'default') return true;
  return appearance.accentColor.toUpperCase() === preset.accent.toUpperCase();
}

function applyColorPreset(preset: PalettePreset): void {
  updateAppearance({
    colorMode: preset.mode,
    accentColor: preset.accent,
    ...(preset.background ? { backgroundColor: preset.background } : {}),
  });
}

function fontChange(value: string | string[]): void {
  if (typeof value === 'string' && fontFamilies.includes(value as FontFamilyKey)) {
    updateAppearance({ fontFamily: value as FontFamilyKey });
  }
}

function languageChange(value: string | string[]): void {
  if (value === 'en' || value === 'ru') setLocale(value);
}
function shadowChange(value: string | string[]): void {
  if (typeof value === 'string' && shadows.includes(value as Shadows))
    updateAppearance({ shadows: value as Shadows });
}
function chooseTheme(value: Theme): void {
  setTheme(value);
}
</script>

<template>
  <div class="appearance-view">
    <div class="appearance-view__controls">
      <AppCard>
        <h2>{{ $t('appearance.colorLanguage') }}</h2>
        <fieldset class="appearance-view__fieldset">
          <legend>{{ $t('appearance.theme') }}</legend>
          <div class="appearance-view__choices">
            <label
              v-for="option in themes"
              :key="option.value"
              class="appearance-choice"
              :class="{ 'appearance-choice--selected': theme === option.value }"
            >
              <input
                type="radio"
                name="appearance-theme"
                :value="option.value"
                :checked="theme === option.value"
                @change="chooseTheme(option.value)"
              />
              <component
                :is="option.icon"
                :size="20"
                aria-hidden="true"
              />
              <span>{{ $t(`appearance.theme_${option.value}`) }}</span>
            </label>
          </div>
        </fieldset>
        <label
          class="appearance-view__select-label"
          for="appearance-language"
        >
          {{ $t('appearance.language') }}
        </label>
        <AppDropdown
          id="appearance-language"
          :model-value="locale"
          :label="$t('appearance.language')"
          :options="[
            { value: 'ru', label: 'Русский' },
            { value: 'en', label: 'English' },
          ]"
          @update:model-value="languageChange"
        />
      </AppCard>

      <AppCard>
        <h2>{{ $t('appearance.colorSection') }}</h2>
        <h3 class="appearance-view__presets-title">{{ $t('appearance.colorPresets') }}</h3>
        <div
          class="appearance-palettes"
          role="group"
          :aria-label="$t('appearance.colorPresets')"
        >
          <button
            v-for="preset in PALETTE_PRESETS"
            :key="preset.id"
            type="button"
            class="appearance-palette-chip"
            :aria-pressed="isColorPresetActive(preset)"
            @click="applyColorPreset(preset)"
          >
            <span
              class="appearance-palette-chip__swatch"
              :style="{ backgroundColor: preset.accent }"
              aria-hidden="true"
            />
            <span class="appearance-palette-chip__label">
              {{ $t(`appearance.presetPalette_${preset.id}`) }}
            </span>
          </button>
        </div>

        <fieldset class="appearance-view__fieldset">
          <legend>{{ $t('appearance.colorMode') }}</legend>
          <div class="appearance-view__choices">
            <label
              v-for="mode in colorModes"
              :key="mode"
              class="appearance-choice"
              :class="{ 'appearance-choice--selected': appearance.colorMode === mode }"
            >
              <input
                type="radio"
                name="appearance-color-mode"
                :value="mode"
                :checked="appearance.colorMode === mode"
                @change="updateAppearance({ colorMode: mode })"
              />
              <span>{{ $t(`appearance.colorMode_${mode}`) }}</span>
            </label>
          </div>
          <p class="appearance-view__note appearance-view__mode-hint">
            {{ $t('appearance.colorModeHint') }}
          </p>
        </fieldset>

        <div
          v-if="appearance.colorMode !== 'default'"
          class="appearance-color-pickers"
        >
          <div class="appearance-color-field">
            <label
              for="appearance-accent-picker"
              class="appearance-view__select-label"
            >
              {{ $t('appearance.accentColor') }}
            </label>
            <div class="appearance-color-row">
              <input
                id="appearance-accent-picker"
                type="color"
                class="appearance-color-native"
                :value="appearance.accentColor"
                @input="
                  updateAppearance({
                    accentColor: ($event.target as HTMLInputElement).value.toUpperCase(),
                  })
                "
              />
              <AppInput
                id="appearance-accent-hex"
                v-model="accentHexDraft"
                :label="$t('appearance.accentColor')"
                maxlength="7"
              />
            </div>
          </div>

          <div
            v-if="appearance.colorMode === 'custom'"
            class="appearance-color-field"
          >
            <label
              for="appearance-bg-picker"
              class="appearance-view__select-label"
            >
              {{ $t('appearance.backgroundColor') }}
            </label>
            <div class="appearance-color-row">
              <input
                id="appearance-bg-picker"
                type="color"
                class="appearance-color-native"
                :value="appearance.backgroundColor"
                @input="
                  updateAppearance({
                    backgroundColor: ($event.target as HTMLInputElement).value.toUpperCase(),
                  })
                "
              />
              <AppInput
                id="appearance-bg-hex"
                v-model="backgroundHexDraft"
                :label="$t('appearance.backgroundColor')"
                maxlength="7"
              />
            </div>
          </div>
        </div>

        <p
          v-if="appearance.colorMode !== 'default'"
          class="appearance-view__note appearance-contrast-badge"
        >
          <AppBadge variant="neutral">
            {{ $t('appearance.contrastBadge', liveContrast) }}
          </AppBadge>
        </p>

        <label
          class="appearance-view__select-label"
          for="appearance-font-family"
        >
          {{ $t('appearance.fontFamily') }}
        </label>
        <AppDropdown
          id="appearance-font-family"
          :model-value="appearance.fontFamily"
          :label="$t('appearance.fontFamily')"
          :options="
            fontFamilies.map((font) => ({
              value: font,
              label: $t(`appearance.font_${font}`),
            }))
          "
          @update:model-value="fontChange"
        />
      </AppCard>

      <AppCard>
        <h2>{{ $t('appearance.sizes') }}</h2>
        <h3 class="appearance-view__presets-title">{{ $t('appearance.presets') }}</h3>
        <p class="appearance-view__note">{{ $t('appearance.presetsHint') }}</p>
        <div
          class="appearance-view__presets"
          role="group"
          :aria-label="$t('appearance.presets')"
        >
          <button
            v-for="preset in presets"
            :id="`appearance-preset-${preset}`"
            :key="preset"
            type="button"
            class="appearance-preset"
            :aria-pressed="activePreset === preset"
            @click="applyPreset(preset)"
          >
            <strong>{{ $t(`appearance.preset_${preset}`) }}</strong>
            <span>{{ $t(`appearance.preset_${preset}_hint`) }}</span>
          </button>
        </div>
        <fieldset class="appearance-view__fieldset">
          <legend>{{ $t('appearance.density') }}</legend>
          <div class="appearance-view__choices">
            <label
              v-for="density in densities"
              :key="density"
              class="appearance-choice"
              :class="{ 'appearance-choice--selected': appearance.density === density }"
            >
              <input
                type="radio"
                name="appearance-density"
                :value="density"
                :checked="appearance.density === density"
                @change="updateAppearance({ density })"
              />
              <span>{{ $t(`appearance.density_${density}`) }}</span>
            </label>
          </div>
        </fieldset>
        <AppRange
          v-for="control in numeric.slice(0, 1)"
          :id="`appearance-${control.id}`"
          :key="control.key"
          :model-value="appearance[control.key]"
          :label="$t(`appearance.${control.key}`)"
          :min="control.min"
          :max="control.max"
          :step="control.step"
          :default-value="DEFAULT_APPEARANCE[control.key]"
          :multiplier="control.unit === '%' ? 100 : 1"
          :suffix="control.unit === '%' ? '%' : ` ${$t('appearance.pixels')}`"
          @update:model-value="updateAppearance({ [control.key]: $event })"
          @start="beginEdit"
          @end="endEdit"
        />
        <AppDisclosure
          id="appearance-advanced"
          v-model="advancedOpen"
          class="appearance-view__advanced"
          :label="$t('appearance.advanced')"
        >
          <AppRange
            v-for="control in numeric.slice(1)"
            :id="`appearance-${control.id}`"
            :key="control.key"
            :model-value="appearance[control.key]"
            :label="$t(`appearance.${control.key}`)"
            :min="control.min"
            :max="control.max"
            :step="control.step"
            :default-value="DEFAULT_APPEARANCE[control.key]"
            :multiplier="control.unit === '%' ? 100 : 1"
            :suffix="control.unit === '%' ? '%' : ` ${$t('appearance.pixels')}`"
            @update:model-value="updateAppearance({ [control.key]: $event })"
            @start="beginEdit"
            @end="endEdit"
          />
        </AppDisclosure>
      </AppCard>

      <AppCard>
        <h2>{{ $t('appearance.motionShadows') }}</h2>
        <AppSwitch
          id="appearance-animations"
          :model-value="appearance.animations"
          :label="$t('appearance.animations')"
          aria-describedby="appearance-motion-note"
          @update:model-value="updateAppearance({ animations: $event })"
        />
        <p
          id="appearance-motion-note"
          class="appearance-view__note"
        >
          {{ systemReduced ? $t('appearance.systemReduced') : $t('appearance.motionNote') }}
        </p>
        <AppRange
          id="appearance-speed"
          :model-value="appearance.animationSpeed"
          :label="$t('appearance.animationSpeed')"
          :min="0.5"
          :max="2"
          :step="0.25"
          :default-value="1"
          suffix="×"
          :disabled="motionOff"
          @update:model-value="updateAppearance({ animationSpeed: $event })"
          @start="beginEdit"
          @end="endEdit"
        />
        <AppRange
          id="appearance-theme-duration"
          :model-value="appearance.themeDuration"
          :label="$t('appearance.themeDuration')"
          :min="0"
          :max="600"
          :step="20"
          :default-value="200"
          :suffix="` ${$t('appearance.milliseconds')}`"
          :disabled="motionOff"
          @update:model-value="updateAppearance({ themeDuration: $event })"
          @start="beginEdit"
          @end="endEdit"
        />
        <label
          for="appearance-shadows"
          class="appearance-view__select-label"
        >
          {{ $t('appearance.shadows') }}
        </label>
        <AppDropdown
          id="appearance-shadows"
          :model-value="appearance.shadows"
          :label="$t('appearance.shadows')"
          :options="shadows.map((value) => ({ value, label: t(`appearance.shadows_${value}`) }))"
          @update:model-value="shadowChange"
        />
      </AppCard>
    </div>

    <aside
      class="appearance-view__preview"
      :aria-label="$t('appearance.preview')"
    >
      <AppCard padding="large">
        <div class="appearance-view__preview-heading">
          <span class="appearance-view__eyebrow">{{ $t('appearance.preview') }}</span>
          <AppBadge variant="primary">
            {{ $t(`appearance.density_${appearance.density}`) }}
          </AppBadge>
        </div>
        <h2>{{ $t('appearance.previewTitle') }}</h2>
        <p class="appearance-view__note">{{ $t('appearance.previewDescription') }}</p>
        <AppInput
          id="appearance-preview-input"
          v-model="previewName"
          :label="$t('appearance.exampleField')"
        />
        <AppCheckbox
          id="appearance-preview-checkbox"
          v-model="previewSelected"
          :label="$t('controls.previewSelection')"
        />
        <div class="appearance-view__buttons">
          <AppButton
            :loading="spinning"
            @click="dialogOpen = true"
          >
            {{ $t('appearance.primaryButton') }}
          </AppButton>
          <AppButton
            variant="secondary"
            @click="spinning = !spinning"
          >
            {{ spinning ? $t('appearance.stop') : $t('appearance.play') }}
          </AppButton>
        </div>
        <AppSkeleton
          variant="text"
          :lines="2"
        />
        <p class="appearance-view__note">{{ $t('appearance.previewHint') }}</p>
      </AppCard>
    </aside>
    <AppDialog
      v-model="dialogOpen"
      :title="$t('appearance.previewTitle')"
      :description="$t('appearance.previewDescription')"
    >
      <AppButton
        block
        @click="dialogOpen = false"
      >
        {{ $t('common.close') }}
      </AppButton>
    </AppDialog>
  </div>
</template>

<style scoped>
.appearance-view {
  display: grid;
  gap: var(--space-24);
  align-items: start;
  min-width: 0;
}
.appearance-view__advanced {
  border-top: 1px solid var(--border);
  padding-top: var(--space-12);
  transition: var(--theme-transition);
}
.appearance-view__advanced summary {
  cursor: pointer;
  color: var(--accent-text);
  font-size: var(--type-body-medium-emphasis-size);
  font-weight: var(--type-body-medium-emphasis-weight);
  letter-spacing: var(--type-body-medium-emphasis-tracking);
  line-height: var(--type-body-medium-emphasis-line);
  padding: var(--space-8) 0;
  transition: var(--theme-transition);
}
.appearance-view__presets-title {
  font-size: var(--type-body-medium-emphasis-size);
  font-weight: var(--type-body-medium-emphasis-weight);
  letter-spacing: var(--type-body-medium-emphasis-tracking);
  line-height: var(--type-body-medium-emphasis-line);
  margin-bottom: var(--space-8);
}
.appearance-view__presets {
  display: grid;
  gap: var(--gap-s);
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 120px), 1fr));
  margin: var(--space-16) 0 var(--space-24);
}
.appearance-preset {
  display: grid;
  gap: var(--space-6);
  align-content: start;
  padding: var(--space-12);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-control-m);
  text-align: left;
  color: var(--text-1);
  transition: var(--theme-transition);
}
.appearance-preset strong {
  font-size: var(--type-body-medium-emphasis-size);
  font-weight: var(--type-body-medium-emphasis-weight);
  letter-spacing: var(--type-body-medium-emphasis-tracking);
  line-height: var(--type-body-medium-emphasis-line);
  overflow-wrap: anywhere;
}
.appearance-preset span {
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  color: var(--text-2);
  transition: var(--theme-transition);
}
.appearance-preset:hover {
  background: var(--surface-hover);
  transition: var(--theme-transition);
}
.appearance-preset[aria-pressed='true'] {
  border-color: var(--accent);
  background: var(--accent-soft);
  transition: var(--theme-transition);
}
.appearance-preset[aria-pressed='true'] strong {
  color: var(--accent-text);
  transition: var(--theme-transition);
}
.appearance-view__controls {
  display: grid;
  gap: var(--space-20);
  min-width: 0;
}
.appearance-view h2 {
  font-size: var(--type-h3-size);
  letter-spacing: var(--type-h3-tracking);
  line-height: var(--type-h3-line);
  font-weight: var(--type-h3-weight);
  margin-bottom: var(--space-16);
}
.appearance-view__fieldset {
  border: 0;
  padding: 0;
  margin: 0 0 var(--space-20);
  min-width: 0;
}
.appearance-view__fieldset legend,
.appearance-view__select-label,
.appearance-range label {
  font-size: var(--type-body-medium-emphasis-size);
  letter-spacing: var(--type-body-medium-emphasis-tracking);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
}
.appearance-view__fieldset legend {
  margin-bottom: var(--space-12);
}
.appearance-view__choices {
  display: grid;
  grid-template-columns: repeat(
    auto-fit,
    minmax(min(100%, calc(88px * var(--ui-font-scale))), 1fr)
  );
  gap: var(--gap-s);
}
.appearance-choice {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: var(--gap-s);
  padding: var(--space-12) var(--space-8);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-control-m);
  cursor: pointer;
  color: var(--text-2);
  min-width: 0;
  text-align: center;
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  overflow-wrap: normal;
  hyphens: none;
  transition: var(--theme-transition);
}
.appearance-choice input {
  position: absolute;
  opacity: 0;
  inset: 0;
  margin: 0;
  width: 100%;
  height: 100%;
  cursor: pointer;
}
.appearance-choice:has(:focus-visible) {
  border-color: var(--focus-border);
  transition: var(--theme-transition);
}
.appearance-palettes {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 138px), 1fr));
  gap: var(--gap-s);
  margin: var(--space-12) 0 var(--space-20);
}
.appearance-palette-chip {
  display: flex;
  align-items: center;
  gap: var(--space-8);
  padding: var(--space-8) var(--space-10);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-control-m);
  background: var(--surface);
  color: var(--text-1);
  cursor: pointer;
  min-width: 0;
  text-align: left;
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  transition: var(--theme-transition);
}
.appearance-palette-chip:hover {
  background: var(--surface-hover);
}
.appearance-palette-chip[aria-pressed='true'] {
  border-color: var(--accent);
  background: var(--accent-soft);
  color: var(--accent-text);
  font-weight: var(--type-body-small-emphasis-weight);
}
.appearance-palette-chip__swatch {
  width: 16px;
  height: 16px;
  flex-shrink: 0;
  border-radius: var(--radius-circle);
  border: 1px solid var(--border-strong);
}
.appearance-palette-chip__label {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.appearance-view__mode-hint {
  margin-top: var(--space-8);
}
.appearance-color-pickers {
  display: grid;
  gap: var(--space-16);
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr));
  margin-bottom: var(--space-16);
}
.appearance-color-field {
  display: grid;
  gap: var(--space-6);
  min-width: 0;
}
.appearance-color-row {
  display: grid;
  grid-template-columns: 44px minmax(0, 1fr);
  gap: var(--space-8);
  align-items: end;
}
.appearance-color-native {
  width: 44px;
  height: 44px;
  padding: 2px;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-control-m);
  background: var(--surface);
  cursor: pointer;
}
.appearance-contrast-badge {
  margin-bottom: var(--space-16);
}
.appearance-choice--selected {
  color: var(--accent-text);
  background: var(--accent-soft);
  border-color: var(--accent);
  transition: var(--theme-transition);
}
.appearance-view__select-label {
  display: block;
  margin-bottom: var(--space-8);
}
.appearance-view__note {
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  color: var(--text-2);
  transition: var(--theme-transition);
}
.appearance-view__preview {
  min-width: 0;
}
.appearance-view__preview .app-card {
  display: grid;
  gap: var(--space-20);
}
.appearance-view__preview h2 {
  margin: 0;
  font-size: var(--type-h2-size);
  font-weight: var(--type-h2-weight);
  letter-spacing: var(--type-h2-tracking);
  line-height: var(--type-h2-line);
}
.appearance-view__preview-heading {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--gap-s);
}
.appearance-view__eyebrow {
  font-size: var(--type-body-caption-size);
  font-weight: var(--type-body-caption-weight);
  line-height: var(--type-body-caption-line);
  color: var(--text-3);
  text-transform: uppercase;
  letter-spacing: 0.08em;
  transition: var(--theme-transition);
}
.appearance-view__buttons {
  display: flex;
  gap: var(--gap-s);
  flex-wrap: wrap;
}
@media (min-width: 1024px) {
  .appearance-view {
    grid-template-columns: minmax(0, 1fr) minmax(280px, 0.65fr);
  }
  .appearance-view__preview {
    position: sticky;
    top: calc(var(--control-h-l) + var(--space-24));
  }
}
</style>
