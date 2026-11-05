<script setup lang="ts">
import { computed, ref, watch } from 'vue';

const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    min: number;
    max: number;
    step?: number;
    defaultValue: number;
    multiplier?: number;
    suffix?: string;
    disabled?: boolean;
    description?: string;
  }>(),
  { step: 1, multiplier: 1, suffix: '', disabled: false, description: undefined },
);
const model = defineModel<number>({ required: true });
const emit = defineEmits<{ start: []; end: [] }>();
const formatted = computed(
  () => `${Number((model.value * props.multiplier).toFixed(2))}${props.suffix}`,
);
const pressed = ref(false);
const progress = computed(() =>
  props.max > props.min
    ? Math.max(0, Math.min(100, ((model.value - props.min) / (props.max - props.min)) * 100))
    : 0,
);
const editingKeys = new Set([
  'ArrowLeft',
  'ArrowRight',
  'ArrowUp',
  'ArrowDown',
  'Home',
  'End',
  'PageUp',
  'PageDown',
]);
function start(): void {
  if (props.disabled || pressed.value) return;
  pressed.value = true;
  emit('start');
}
function end(): void {
  if (!pressed.value) return;
  pressed.value = false;
  emit('end');
}
watch(
  () => props.disabled,
  (value) => {
    if (value) end();
  },
);
function change(delta: number): void {
  if (props.disabled) return;
  model.value = Math.max(
    props.min,
    Math.min(props.max, Number((model.value + props.step * delta).toFixed(6))),
  );
}
</script>

<template>
  <div
    class="app-range"
    :class="{ 'app-range--disabled': disabled, 'app-range--pressed': pressed && !disabled }"
    :style="{ '--range-progress': `${progress}%` }"
    role="group"
    :aria-labelledby="`${id}-label`"
  >
    <div class="app-range__heading">
      <label
        :id="`${id}-label`"
        :for="id"
      >
        {{ label }}
      </label>
      <output :for="id">{{ formatted }}</output>
      <button
        type="button"
        class="app-range__reset"
        :aria-label="$t('appearance.resetControl', { label })"
        :disabled="disabled || model === defaultValue"
        @click="model = defaultValue"
      >
        {{ $t('appearance.defaultValue') }}
      </button>
    </div>
    <div class="app-range__track">
      <button
        type="button"
        class="app-range__step"
        :aria-label="$t('appearance.decrease', { label })"
        :disabled="disabled || model <= min"
        @click="change(-1)"
      >
        −
      </button>
      <div class="app-range__slider">
        <span
          class="app-range__rail"
          aria-hidden="true"
        />
        <input
          :id="id"
          type="range"
          :min="min"
          :max="max"
          :step="step"
          :value="model"
          :disabled="disabled"
          :aria-valuetext="formatted"
          :aria-describedby="description ? `${id}-hint` : undefined"
          @pointerdown="$event.button === 0 && start()"
          @keydown="editingKeys.has($event.key) && start()"
          @input="!disabled && (model = ($event.target as HTMLInputElement).valueAsNumber)"
          @change="end"
          @pointerup="end"
          @lostpointercapture="end"
          @keyup="editingKeys.has($event.key) && end()"
          @blur="end"
          @pointercancel="end"
        />
      </div>
      <button
        type="button"
        class="app-range__step"
        :aria-label="$t('appearance.increase', { label })"
        :disabled="disabled || model >= max"
        @click="change(1)"
      >
        +
      </button>
    </div>
    <p
      v-if="description"
      :id="`${id}-hint`"
      class="app-range__hint"
    >
      {{ description }}
    </p>
  </div>
</template>

<style scoped>
.app-range {
  --range-progress: 0%;
  display: grid;
  gap: var(--space-8);
  margin: var(--space-20) 0;
  min-width: 0;
}
.app-range__heading {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: var(--space-8);
}
.app-range label {
  flex: 1;
  font-size: var(--type-body-medium-emphasis-size);
  letter-spacing: var(--type-body-medium-emphasis-tracking);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
}
.app-range output {
  font-family: var(--font-mono);
  font-size: var(--type-body-small-size);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  font-variant-numeric: tabular-nums;
  color: var(--accent-text);
  background: var(--accent-soft);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  padding: var(--space-4) var(--space-8);
  white-space: nowrap;
  transition:
    var(--theme-transition),
    transform var(--motion-control-duration) var(--motion-spring-ease);
}
.app-range--pressed output {
  transform: scale(1.06);
}
.app-range__reset {
  color: var(--accent-text);
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  text-decoration: underline;
  text-underline-offset: 0.2em;
  padding: var(--space-4);
  transition: var(--theme-transition);
}
.app-range__track {
  display: flex;
  align-items: center;
  gap: var(--space-12);
  min-width: 0;
}
.app-range__step {
  display: grid;
  place-items: center;
  flex: 0 0 44px;
  height: 44px;
  background: var(--surface);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-control-m);
  font-size: var(--type-h3-size);
  font-weight: var(--type-h3-weight);
  letter-spacing: var(--type-h3-tracking);
  line-height: var(--type-h3-line);
  color: var(--text-1);
  box-shadow: var(--shadow-soft);
  transition:
    var(--theme-transition),
    transform var(--motion-control-duration) var(--motion-spring-ease);
}
.app-range__step:active:enabled {
  transform: scale(0.9);
  background: var(--accent-soft);
  color: var(--accent-text);
}
.app-range__slider {
  flex: 1;
  position: relative;
  min-width: 0;
  height: 44px;
}
.app-range__rail {
  position: absolute;
  inset: 19px 12px;
  border-radius: 999px;
  background: var(--border-strong);
  pointer-events: none;
  transition: var(--theme-transition);
}
.app-range__rail::before {
  content: '';
  display: block;
  width: var(--range-progress);
  height: 100%;
  border-radius: inherit;
  background: var(--accent);
  transition: var(--theme-transition);
}
.app-range input {
  --range-thumb-scale: 1;
  position: relative;
  appearance: none;
  -webkit-appearance: none;
  width: 100%;
  min-width: 0;
  height: 44px;
  margin: 0;
  padding: 0;
  background: transparent;
  border-radius: 999px;
  cursor: grab;
  outline: none;
  box-shadow: none;
  direction: ltr;
}
.app-range input::-webkit-slider-runnable-track {
  height: 6px;
  background: transparent;
}
.app-range input::-moz-range-track {
  height: 6px;
  background: transparent;
}
.app-range input::-moz-range-progress {
  background: transparent;
}
.app-range input::-webkit-slider-thumb {
  appearance: none;
  -webkit-appearance: none;
  width: 24px;
  height: 24px;
  margin-top: -9px;
  border: 3px solid var(--accent);
  border-radius: 50%;
  background: var(--color-white);
  box-shadow: var(--shadow-soft);
  transform: scale(var(--range-thumb-scale));
  transition:
    var(--theme-transition),
    transform var(--motion-control-duration) var(--motion-spring-ease);
}
.app-range input::-moz-range-thumb {
  width: 18px;
  height: 18px;
  border: 3px solid var(--accent);
  border-radius: 50%;
  background: var(--color-white);
  box-shadow: var(--shadow-soft);
  transform: scale(var(--range-thumb-scale));
  transition:
    var(--theme-transition),
    transform var(--motion-control-duration) var(--motion-spring-ease);
}
.app-range input:focus-visible {
  --range-thumb-scale: 1.12;
  outline: 2px solid var(--focus-border);
  outline-offset: 2px;
}
.app-range--pressed input:enabled,
.app-range input:active:enabled {
  --range-thumb-scale: 1.28;
  cursor: grabbing;
}
.app-range button:disabled {
  opacity: 0.45;
  cursor: default;
}
.app-range--disabled .app-range__slider {
  opacity: 0.45;
}
.app-range--disabled input {
  cursor: not-allowed;
}
.app-range__hint {
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  color: var(--text-2);
  transition: var(--theme-transition);
}
@media (pointer: coarse) {
  .app-range__reset {
    min-height: 44px;
  }
}
@media (hover: hover) {
  .app-range__step:hover:enabled {
    background: var(--accent-soft);
    color: var(--accent-text);
    border-color: var(--accent);
  }
  .app-range input:hover:enabled {
    --range-thumb-scale: 1.12;
  }
  .app-range--pressed input:hover:enabled {
    --range-thumb-scale: 1.28;
  }
}
@media (forced-colors: active) {
  .app-range__rail {
    display: none;
  }
  .app-range input {
    appearance: auto;
    -webkit-appearance: auto;
    accent-color: Highlight;
  }
}
</style>
