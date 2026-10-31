<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { DEFAULT_APPEARANCE, useAppearance, type AppearancePreset, type Density, type Shadows } from '@/app/appearance';
import { useTheme, type Theme } from '@/app/theme';
import { setLocale } from '@/shared/i18n';
import { LayoutGrid, Moon, Sun } from '@/shared/ui/icons';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
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
const { appearance, updateAppearance, resetAppearance, systemReduced, storageAvailable, applyPreset, activePreset, undoAppearance, canUndo, isDefault, beginEdit, endEdit } = useAppearance();
const { theme, setTheme } = useTheme();
const themes = [{ value: 'light', icon: Sun }, { value: 'dark', icon: Moon }, { value: 'system', icon: LayoutGrid }] as const;
const densities: Density[] = ['compact', 'comfortable', 'spacious'];
const shadows: Shadows[] = ['none', 'subtle', 'normal', 'strong'];
const presets: AppearancePreset[] = ['default', 'compact', 'reading'];
const numeric = [
  { key: 'fontScale', id: 'font-scale', min: .9, max: 1.4, step: .05, unit: '%' },
  { key: 'spacingScale', id: 'spacing-scale', min: .75, max: 1.25, step: .05, unit: '%' },
  { key: 'buttonPaddingX', id: 'button-x', min: 8, max: 32, step: 1, unit: 'px' },
  { key: 'buttonPaddingY', id: 'button-y', min: 6, max: 16, step: 1, unit: 'px' },
  { key: 'radiusScale', id: 'radius-scale', min: 0, max: 1.5, step: .1, unit: '%' },
] as const;
const previewName = ref('Platform');
const dialogOpen = ref(false);
const spinning = ref(false);
const previewSelected = ref(true);
const motionOff = computed(() => !appearance.animations || systemReduced.value);

function languageChange(value: string | string[]): void {
  if (value === 'en' || value === 'ru') setLocale(value);
}
function shadowChange(value: string | string[]): void {
  if (typeof value === 'string' && shadows.includes(value as Shadows)) updateAppearance({ shadows: value as Shadows });
}
function chooseTheme(value: Theme): void { setTheme(value); }
</script>

<template>
  <div class="appearance-view">
    <div class="appearance-view__toolbar">
      <p class="appearance-view__note" role="status">{{ storageAvailable ? $t('appearance.savedLocally') : $t('appearance.storageUnavailable') }}</p>
      <div class="appearance-view__buttons">
        <AppButton id="appearance-undo" variant="ghost" :disabled="!canUndo" @click="undoAppearance">{{ $t('appearance.undo') }}</AppButton>
        <AppButton id="appearance-reset" variant="secondary" :disabled="isDefault" @click="resetAppearance">{{ $t('appearance.reset') }}</AppButton>
      </div>
    </div>
    <div class="appearance-view__controls">
      <AppCard>
        <h2>{{ $t('appearance.colorLanguage') }}</h2>
        <fieldset class="appearance-view__fieldset">
          <legend>{{ $t('appearance.theme') }}</legend>
          <div class="appearance-view__choices">
            <label v-for="option in themes" :key="option.value" class="appearance-choice" :class="{ 'appearance-choice--selected': theme === option.value }">
              <input type="radio" name="appearance-theme" :value="option.value" :checked="theme === option.value" @change="chooseTheme(option.value)" />
              <component :is="option.icon" :size="20" aria-hidden="true" />
              <span>{{ $t(`appearance.theme_${option.value}`) }}</span>
            </label>
          </div>
        </fieldset>
        <label class="appearance-view__select-label" for="appearance-language">{{ $t('appearance.language') }}</label>
        <AppDropdown id="appearance-language" :model-value="locale" :label="$t('appearance.language')" :options="[{ value: 'ru', label: 'Русский' }, { value: 'en', label: 'English' }]" searchable @update:model-value="languageChange" />
      </AppCard>

      <AppCard>
        <h2>{{ $t('appearance.sizes') }}</h2>
        <h3 class="appearance-view__presets-title">{{ $t('appearance.presets') }}</h3>
        <p class="appearance-view__note">{{ $t('appearance.presetsHint') }}</p>
        <div class="appearance-view__presets" role="group" :aria-label="$t('appearance.presets')">
          <button v-for="preset in presets" :id="`appearance-preset-${preset}`" :key="preset" type="button" class="appearance-preset" :aria-pressed="activePreset === preset" @click="applyPreset(preset)">
            <strong>{{ $t(`appearance.preset_${preset}`) }}</strong><span>{{ $t(`appearance.preset_${preset}_hint`) }}</span>
          </button>
        </div>
        <fieldset class="appearance-view__fieldset">
          <legend>{{ $t('appearance.density') }}</legend>
          <div class="appearance-view__choices">
            <label v-for="density in densities" :key="density" class="appearance-choice" :class="{ 'appearance-choice--selected': appearance.density === density }">
              <input type="radio" name="appearance-density" :value="density" :checked="appearance.density === density" @change="updateAppearance({ density })" />
              <span>{{ $t(`appearance.density_${density}`) }}</span>
            </label>
          </div>
        </fieldset>
        <AppRange v-for="control in numeric.slice(0, 1)" :id="`appearance-${control.id}`" :key="control.key" :model-value="appearance[control.key]"
          :label="$t(`appearance.${control.key}`)" :min="control.min" :max="control.max" :step="control.step" :default-value="DEFAULT_APPEARANCE[control.key]"
          :multiplier="control.unit === '%' ? 100 : 1" :suffix="control.unit === '%' ? '%' : ` ${$t('appearance.pixels')}`"
          @update:model-value="updateAppearance({ [control.key]: $event })" @start="beginEdit" @end="endEdit" />
        <details id="appearance-advanced" class="appearance-view__advanced">
          <summary>{{ $t('appearance.advanced') }}</summary>
          <AppRange v-for="control in numeric.slice(1)" :id="`appearance-${control.id}`" :key="control.key" :model-value="appearance[control.key]"
            :label="$t(`appearance.${control.key}`)" :min="control.min" :max="control.max" :step="control.step" :default-value="DEFAULT_APPEARANCE[control.key]"
            :multiplier="control.unit === '%' ? 100 : 1" :suffix="control.unit === '%' ? '%' : ` ${$t('appearance.pixels')}`"
            @update:model-value="updateAppearance({ [control.key]: $event })" @start="beginEdit" @end="endEdit" />
        </details>
      </AppCard>

      <AppCard>
        <h2>{{ $t('appearance.motionShadows') }}</h2>
        <AppSwitch id="appearance-animations" :model-value="appearance.animations" :label="$t('appearance.animations')" aria-describedby="appearance-motion-note" @update:model-value="updateAppearance({ animations: $event })" />
        <p id="appearance-motion-note" class="appearance-view__note">{{ systemReduced ? $t('appearance.systemReduced') : $t('appearance.motionNote') }}</p>
        <AppRange id="appearance-speed" :model-value="appearance.animationSpeed" :label="$t('appearance.animationSpeed')" :min="0.5" :max="2" :step="0.25" :default-value="1" suffix="×" :disabled="motionOff" @update:model-value="updateAppearance({ animationSpeed: $event })" @start="beginEdit" @end="endEdit" />
        <AppRange id="appearance-theme-duration" :model-value="appearance.themeDuration" :label="$t('appearance.themeDuration')" :min="0" :max="600" :step="20" :default-value="200" :suffix="` ${$t('appearance.milliseconds')}`" :disabled="motionOff" @update:model-value="updateAppearance({ themeDuration: $event })" @start="beginEdit" @end="endEdit" />
        <label for="appearance-shadows" class="appearance-view__select-label">{{ $t('appearance.shadows') }}</label>
        <AppDropdown id="appearance-shadows" :model-value="appearance.shadows" :label="$t('appearance.shadows')" :options="shadows.map(value => ({ value, label: t(`appearance.shadows_${value}`) }))" @update:model-value="shadowChange" />
      </AppCard>
    </div>

    <aside class="appearance-view__preview" :aria-label="$t('appearance.preview')">
      <AppCard padding="large">
        <div class="appearance-view__preview-heading"><span class="appearance-view__eyebrow">{{ $t('appearance.preview') }}</span><AppBadge variant="primary">{{ $t(`appearance.density_${appearance.density}`) }}</AppBadge></div>
        <h2>{{ $t('appearance.previewTitle') }}</h2>
        <p class="appearance-view__note">{{ $t('appearance.previewDescription') }}</p>
        <AppInput id="appearance-preview-input" v-model="previewName" :label="$t('appearance.exampleField')" />
        <AppCheckbox id="appearance-preview-checkbox" v-model="previewSelected" :label="$t('controls.previewSelection')" />
        <div class="appearance-view__buttons">
          <AppButton :loading="spinning" @click="dialogOpen = true">{{ $t('appearance.primaryButton') }}</AppButton>
          <AppButton variant="secondary" @click="spinning = !spinning">{{ spinning ? $t('appearance.stop') : $t('appearance.play') }}</AppButton>
        </div>
        <AppSkeleton variant="text" :lines="2" />
        <p class="appearance-view__note">{{ $t('appearance.previewHint') }}</p>
      </AppCard>
    </aside>
    <AppDialog v-model="dialogOpen" :title="$t('appearance.previewTitle')" :description="$t('appearance.previewDescription')">
      <AppButton block @click="dialogOpen = false">{{ $t('common.close') }}</AppButton>
    </AppDialog>
  </div>
</template>

<style scoped>
.appearance-view { display: grid; gap: var(--space-24); align-items: start; min-width: 0; }
.appearance-view__toolbar { grid-column: 1 / -1; display: flex; justify-content: space-between; align-items: center; gap: var(--gap-m); flex-wrap: wrap; padding: var(--space-12) var(--space-16); background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-control-l); transition: var(--theme-transition); }
.appearance-view__toolbar > p { flex: 1; min-width: min(100%, 220px); }
.appearance-view__toolbar .appearance-view__buttons { flex: 1; min-width: min(100%, 320px); }
.appearance-view__toolbar .app-button { flex: 1; }
.appearance-view__advanced { border-top: 1px solid var(--border); padding-top: var(--space-12); transition: var(--theme-transition); }
.appearance-view__advanced summary { cursor: pointer; color: var(--accent-text); font-size: var(--type-body-medium-emphasis-size); line-height: var(--type-body-medium-emphasis-line); padding: var(--space-8) 0; transition: var(--theme-transition); }
.appearance-view__presets-title { font-size: var(--type-body-medium-emphasis-size); line-height: var(--type-body-medium-emphasis-line); margin-bottom: var(--space-8); }
.appearance-view__presets { display: grid; gap: var(--gap-s); grid-template-columns: repeat(auto-fit, minmax(min(100%, 120px), 1fr)); margin: var(--space-16) 0 var(--space-24); }
.appearance-preset { display: grid; gap: var(--space-6); align-content: start; padding: var(--space-12); border: 1px solid var(--border-strong); border-radius: var(--radius-control-m); text-align: left; color: var(--text-1); transition: var(--theme-transition); }
.appearance-preset strong { font-size: var(--type-body-medium-emphasis-size); line-height: var(--type-body-medium-emphasis-line); overflow-wrap: anywhere; }
.appearance-preset span { font-size: var(--type-body-small-size); line-height: var(--type-body-small-line); color: var(--text-2); transition: var(--theme-transition); }
.appearance-preset:hover { background: var(--surface-hover); transition: var(--theme-transition); }
.appearance-preset[aria-pressed='true'] { border-color: var(--accent); background: var(--accent-soft); transition: var(--theme-transition); }
.appearance-preset[aria-pressed='true'] strong { color: var(--accent-text); transition: var(--theme-transition); }
.appearance-view__controls { display: grid; gap: var(--space-20); min-width: 0; }
.appearance-view h2 { font-size: var(--type-h3-size); line-height: var(--type-h3-line); font-weight: var(--type-h3-weight); margin-bottom: var(--space-16); }
.appearance-view__fieldset { border: 0; padding: 0; margin: 0 0 var(--space-20); min-width: 0; }
.appearance-view__fieldset legend, .appearance-view__select-label, .appearance-range label { font-size: var(--type-body-medium-emphasis-size); line-height: var(--type-body-medium-emphasis-line); font-weight: var(--type-body-medium-emphasis-weight); }
.appearance-view__fieldset legend { margin-bottom: var(--space-12); }
.appearance-view__choices { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, calc(72px * var(--ui-font-scale))), 1fr)); gap: var(--gap-s); }
.appearance-choice { position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: var(--gap-s); padding: var(--space-12) var(--space-8); border: 1px solid var(--border-strong); border-radius: var(--radius-control-m); cursor: pointer; color: var(--text-2); min-width: 0; text-align: center; font-size: var(--type-body-small-size); overflow-wrap: anywhere; transition: var(--theme-transition); }
.appearance-choice input { position: absolute; opacity: 0; inset: 0; margin: 0; width: 100%; height: 100%; cursor: pointer; }
.appearance-choice:has(:focus-visible) { box-shadow: var(--focus-ring); transition: var(--theme-transition); }
.appearance-choice--selected { color: var(--accent-text); background: var(--accent-soft); border-color: var(--accent); transition: var(--theme-transition); }
.appearance-view__select-label { display: block; margin-bottom: var(--space-8); }
.appearance-view__note { font-size: var(--type-body-small-size); line-height: var(--type-body-small-line); color: var(--text-2); transition: var(--theme-transition); }
.appearance-view__preview { min-width: 0; }
.appearance-view__preview .app-card { display: grid; gap: var(--space-20); }
.appearance-view__preview h2 { margin: 0; font-size: var(--type-h2-size); line-height: var(--type-h2-line); }
.appearance-view__preview-heading { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--gap-s); }
.appearance-view__eyebrow { font-size: var(--type-body-caption-size); line-height: var(--type-body-caption-line); color: var(--text-3); text-transform: uppercase; letter-spacing: .08em; transition: var(--theme-transition); }
.appearance-view__buttons { display: flex; gap: var(--gap-s); flex-wrap: wrap; }
@media (min-width: 1024px) { .appearance-view { grid-template-columns: minmax(0, 1fr) minmax(280px, .65fr); } .appearance-view__preview { position: sticky; top: calc(var(--control-h-l) + var(--space-24)); } }
</style>
