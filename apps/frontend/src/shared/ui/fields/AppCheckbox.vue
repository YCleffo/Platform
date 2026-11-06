<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, useAttrs } from 'vue';
import { Check } from '@/shared/ui/icons';

defineOptions({ inheritAttrs: false });
const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    description?: string;
    disabled?: boolean;
    indeterminate?: boolean;
    name?: string;
    value?: string;
    required?: boolean;
  }>(),
  {
    disabled: false,
    indeterminate: false,
    required: false,
    description: undefined,
    name: undefined,
    value: undefined,
  },
);
const model = defineModel<boolean>({ default: false });
const attrs = useAttrs();
const input = ref<HTMLInputElement | null>(null);
let form: HTMLFormElement | null = null;
let resetTimer: ReturnType<typeof setTimeout> | undefined;
function describedBy(): string | undefined {
  return (
    [
      props.description ? `${props.id}-description` : '',
      typeof attrs['aria-describedby'] === 'string' ? attrs['aria-describedby'] : '',
    ]
      .filter(Boolean)
      .join(' ') || undefined
  );
}
function change(event: Event): void {
  if (!props.disabled) model.value = (event.target as HTMLInputElement).checked;
}
function onReset(): void {
  clearTimeout(resetTimer);
  resetTimer = setTimeout(() => {
    if (input.value !== null) {
      model.value = input.value.checked;
    }
  }, 0);
}
onMounted(() => {
  form = input.value?.form ?? null;
  form?.addEventListener('reset', onReset);
});
onBeforeUnmount(() => {
  clearTimeout(resetTimer);
  form?.removeEventListener('reset', onReset);
  form = null;
});
</script>

<template>
  <label
    class="app-checkbox"
    :class="{ 'app-checkbox--disabled': disabled }"
    :for="id"
  >
    <span class="app-checkbox__control">
      <input
        ref="input"
        :id="id"
        v-bind="$attrs"
        type="checkbox"
        :checked="model"
        :indeterminate="indeterminate"
        :disabled="disabled"
        :required="required"
        :name="name"
        :value="value"
        :aria-labelledby="`${id}-label`"
        :aria-describedby="describedBy()"
        @change="change"
      />
      <span
        class="app-checkbox__box"
        aria-hidden="true"
      >
        <Check :size="17" />
        <span class="app-checkbox__dash" />
      </span>
    </span>
    <span class="app-checkbox__copy">
      <span
        :id="`${id}-label`"
        class="app-checkbox__label"
      >
        {{ label }}
      </span>
      <span
        v-if="description"
        :id="`${id}-description`"
        class="app-checkbox__description"
      >
        {{ description }}
      </span>
    </span>
  </label>
</template>

<style scoped>
.app-checkbox {
  --checkbox-size: calc(18px * var(--ui-density-scale, 1));
  --checkbox-radius: calc(6px * var(--ui-radius-scale, 1));
  display: flex;
  align-items: center;
  gap: var(--space-12);
  min-height: var(--control-h-l);
  cursor: pointer;
  position: relative;
  min-width: 0;
}
.app-checkbox__control {
  display: grid;
  place-items: center;
  position: relative;
  flex: 0 0 var(--checkbox-size);
  width: var(--checkbox-size);
  height: var(--checkbox-size);
}
.app-checkbox input {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  margin: 0;
  opacity: 0;
  z-index: 1;
  cursor: inherit;
}
.app-checkbox__box {
  position: relative;
  display: grid;
  place-items: center;
  width: var(--checkbox-size);
  height: var(--checkbox-size);
  border: 2px solid var(--text-2);
  border-radius: var(--checkbox-radius);
  background: var(--surface);
  color: var(--color-white);
  box-shadow: var(--shadow-soft);
  transition:
    var(--theme-transition),
    transform var(--motion-control-duration) var(--motion-spring-ease);
}
.app-checkbox__box :deep(svg) {
  display: block;
  position: absolute;
  inset: 0;
  margin: auto;
  width: calc(12px * var(--ui-density-scale, 1));
  height: calc(12px * var(--ui-density-scale, 1));
  transform-origin: center;
  opacity: 0;
  transform: scale(0.45) rotate(-20deg);
  transition:
    var(--theme-transition),
    opacity var(--motion-fast-duration) var(--motion-ease),
    transform var(--motion-control-duration) var(--motion-spring-ease);
}
.app-checkbox__dash {
  position: absolute;
  top: 50%;
  left: 50%;
  width: calc(8px * var(--ui-density-scale, 1));
  height: calc(2px * var(--ui-density-scale, 1));
  border-radius: calc(1px * var(--ui-radius-scale, 1));
  background: currentColor;
  opacity: 0;
  transform: translate(-50%, -50%) scale(0.45);
  transition:
    var(--theme-transition),
    opacity var(--motion-fast-duration) var(--motion-ease),
    transform var(--motion-control-duration) var(--motion-spring-ease);
}
.app-checkbox input:checked + .app-checkbox__box,
.app-checkbox input:indeterminate + .app-checkbox__box {
  background: var(--accent);
  border-color: var(--accent);
}
.app-checkbox input:checked + .app-checkbox__box :deep(svg) {
  opacity: 1;
  transform: scale(1) rotate(0);
}
.app-checkbox input:indeterminate + .app-checkbox__box :deep(svg) {
  opacity: 0;
}
.app-checkbox input:indeterminate + .app-checkbox__box .app-checkbox__dash {
  opacity: 1;
  transform: translate(-50%, -50%) scale(1);
}
.app-checkbox input:focus-visible + .app-checkbox__box {
  outline: 2px solid var(--focus-border);
  outline-offset: 4px;
}
.app-checkbox:active:not(.app-checkbox--disabled) .app-checkbox__box {
  transform: scale(0.9);
}
.app-checkbox__copy {
  display: grid;
  gap: var(--space-4);
  min-width: 0;
}
.app-checkbox__label {
  font-size: var(--type-body-medium-emphasis-size);
  letter-spacing: var(--type-body-medium-emphasis-tracking);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  overflow-wrap: anywhere;
}
.app-checkbox__description {
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  color: var(--text-2);
  transition: var(--theme-transition);
}
.app-checkbox--disabled {
  opacity: 0.45;
  cursor: not-allowed;
}
@media (hover: hover) {
  .app-checkbox:hover:not(.app-checkbox--disabled) .app-checkbox__box {
    border-color: var(--accent);
    box-shadow: var(--focus-ring);
  }
}
@media (forced-colors: active) {
  .app-checkbox__box {
    forced-color-adjust: none;
    background: Canvas;
    color: HighlightText;
    border-color: ButtonText;
  }
  .app-checkbox input:checked + .app-checkbox__box,
  .app-checkbox input:indeterminate + .app-checkbox__box {
    background: Highlight;
    border-color: Highlight;
  }
}
</style>
