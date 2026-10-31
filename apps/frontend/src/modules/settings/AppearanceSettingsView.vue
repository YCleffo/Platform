<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAppearance, type Density, type Shadows } from '@/app/appearance';
import { useTheme, type Theme } from '@/app/theme';
import { setLocale } from '@/shared/i18n';
import { LayoutGrid, Moon, Sun } from '@/shared/ui/icons';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';

const { locale, t } = useI18n();
const { appearance, updateAppearance, resetAppearance, systemReduced, storageAvailable } = useAppearance();
const { theme, setTheme } = useTheme();
const themes = [{ value: 'light', icon: Sun }, { value: 'dark', icon: Moon }, { value: 'system', icon: LayoutGrid }] as const;
const densities: Density[] = ['compact', 'comfortable', 'spacious'];
const shadows: Shadows[] = ['none', 'subtle', 'normal', 'strong'];
const numeric = [
  { key: 'fontScale', id: 'font-scale', min: .9, max: 1.4, step: .05, unit: '%' },
  { key: 'spacingScale', id: 'spacing-scale', min: .75, max: 1.25, step: .05, unit: '%' },
  { key: 'buttonPaddingX', id: 'button-x', min: 8, max: 32, step: 1, unit: 'px' },
  { key: 'buttonPaddingY', id: 'button-y', min: 6, max: 16, step: 1, unit: 'px' },
  { key: 'radiusScale', id: 'radius-scale', min: 0, max: 1.5, step: .1, unit: '%' },
] as const;
type NumericKey = typeof numeric[number]['key'] | 'animationSpeed' | 'themeDuration';
const previewName = ref('Platform');
const dialogOpen = ref(false);
const spinning = ref(false);
const motionOff = computed(() => !appearance.animations || systemReduced.value);

function numberChange(key: NumericKey, event: Event): void {
  updateAppearance({ [key]: (event.target as HTMLInputElement).valueAsNumber });
}
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
        <fieldset class="appearance-view__fieldset">
          <legend>{{ $t('appearance.density') }}</legend>
          <div class="appearance-view__choices">
            <label v-for="density in densities" :key="density" class="appearance-choice" :class="{ 'appearance-choice--selected': appearance.density === density }">
              <input type="radio" name="appearance-density" :value="density" :checked="appearance.density === density" @change="updateAppearance({ density })" />
              <span>{{ $t(`appearance.density_${density}`) }}</span>
            </label>
          </div>
        </fieldset>
        <div v-for="control in numeric" :key="control.key" class="appearance-range">
          <div class="appearance-range__heading">
            <label :for="`appearance-${control.id}`">{{ $t(`appearance.${control.key}`) }}</label>
            <output :for="`appearance-${control.id}`">{{ control.unit === '%' ? Math.round(appearance[control.key] * 100) : appearance[control.key] }}{{ control.unit === '%' ? '%' : ` ${$t('appearance.pixels')}` }}</output>
          </div>
          <input :id="`appearance-${control.id}`" type="range" :min="control.min" :max="control.max" :step="control.step" :value="appearance[control.key]" @input="numberChange(control.key, $event)" />
        </div>
      </AppCard>

      <AppCard>
        <h2>{{ $t('appearance.motionShadows') }}</h2>
        <label class="appearance-view__toggle" for="appearance-animations">
          <input id="appearance-animations" type="checkbox" :checked="appearance.animations" aria-describedby="appearance-motion-note" @change="updateAppearance({ animations: ($event.target as HTMLInputElement).checked })" />
          <span>{{ $t('appearance.animations') }}</span>
        </label>
        <p id="appearance-motion-note" class="appearance-view__note">{{ systemReduced ? $t('appearance.systemReduced') : $t('appearance.motionNote') }}</p>
        <div class="appearance-range">
          <div class="appearance-range__heading"><label for="appearance-speed">{{ $t('appearance.animationSpeed') }}</label><output for="appearance-speed">{{ appearance.animationSpeed }}×</output></div>
          <input id="appearance-speed" type="range" min="0.5" max="2" step="0.25" :value="appearance.animationSpeed" :disabled="motionOff" @input="numberChange('animationSpeed', $event)" />
        </div>
        <div class="appearance-range">
          <div class="appearance-range__heading"><label for="appearance-theme-duration">{{ $t('appearance.themeDuration') }}</label><output for="appearance-theme-duration">{{ appearance.themeDuration }} {{ $t('appearance.milliseconds') }}</output></div>
          <input id="appearance-theme-duration" type="range" min="0" max="600" step="20" :value="appearance.themeDuration" :disabled="motionOff" @input="numberChange('themeDuration', $event)" />
        </div>
        <label for="appearance-shadows" class="appearance-view__select-label">{{ $t('appearance.shadows') }}</label>
        <AppDropdown id="appearance-shadows" :model-value="appearance.shadows" :label="$t('appearance.shadows')" :options="shadows.map(value => ({ value, label: t(`appearance.shadows_${value}`) }))" @update:model-value="shadowChange" />
      </AppCard>
      <footer class="appearance-view__footer">
        <p class="appearance-view__note" role="status">{{ storageAvailable ? $t('appearance.savedLocally') : $t('appearance.storageUnavailable') }}</p>
        <AppButton variant="secondary" @click="resetAppearance">{{ $t('appearance.reset') }}</AppButton>
      </footer>
    </div>

    <aside class="appearance-view__preview" :aria-label="$t('appearance.preview')">
      <AppCard padding="large">
        <div class="appearance-view__preview-heading"><span class="appearance-view__eyebrow">{{ $t('appearance.preview') }}</span><AppBadge variant="primary">{{ $t(`appearance.density_${appearance.density}`) }}</AppBadge></div>
        <h2>{{ $t('appearance.previewTitle') }}</h2>
        <p class="appearance-view__note">{{ $t('appearance.previewDescription') }}</p>
        <AppInput id="appearance-preview-input" v-model="previewName" :label="$t('appearance.exampleField')" />
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
.appearance-range { display: grid; gap: var(--space-8); margin: var(--space-20) 0; }
.appearance-range__heading { display: flex; justify-content: space-between; align-items: baseline; gap: var(--gap-m); }
.appearance-range output { color: var(--text-2); font-family: var(--font-mono); font-size: var(--type-body-small-size); white-space: nowrap; transition: var(--theme-transition); }
.appearance-range input { width: 100%; min-width: 0; min-height: 32px; margin: 0; accent-color: var(--accent); cursor: pointer; }
.appearance-range input:disabled { cursor: not-allowed; opacity: .5; }
.appearance-view__toggle { display: flex; align-items: center; gap: var(--gap-m); cursor: pointer; font-weight: var(--type-body-medium-emphasis-weight); }
.appearance-view__toggle input { width: 20px; height: 20px; accent-color: var(--accent); }
.appearance-view__note { font-size: var(--type-body-small-size); line-height: var(--type-body-small-line); color: var(--text-2); transition: var(--theme-transition); }
.appearance-view__toggle + .appearance-view__note { margin-top: var(--space-8); }
.appearance-view__footer { display: flex; align-items: center; justify-content: space-between; gap: var(--gap-l); flex-wrap: wrap; }
.appearance-view__footer p { flex: 1; min-width: min(100%, 200px); }
.appearance-view__preview { min-width: 0; }
.appearance-view__preview .app-card { display: grid; gap: var(--space-20); }
.appearance-view__preview h2 { margin: 0; font-size: var(--type-h2-size); line-height: var(--type-h2-line); }
.appearance-view__preview-heading { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--gap-s); }
.appearance-view__eyebrow { font-size: var(--type-body-caption-size); line-height: var(--type-body-caption-line); color: var(--text-3); text-transform: uppercase; letter-spacing: .08em; transition: var(--theme-transition); }
.appearance-view__buttons { display: flex; gap: var(--gap-s); flex-wrap: wrap; }
@media (min-width: 1024px) { .appearance-view { grid-template-columns: minmax(0, 1fr) minmax(280px, .65fr); } .appearance-view__preview { position: sticky; top: calc(var(--control-h-l) + var(--space-24)); } }
</style>
