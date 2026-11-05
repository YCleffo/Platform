<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, useAttrs } from 'vue';
defineOptions({ inheritAttrs: false });
const props = withDefaults(
  defineProps<{
    id: string;
    label: string;
    description?: string;
    disabled?: boolean;
    name?: string;
  }>(),
  { description: undefined, disabled: false, name: undefined },
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
    class="app-switch"
    :class="{ 'app-switch--disabled': disabled }"
    :for="id"
  >
    <span class="app-switch__copy">
      <span
        :id="`${id}-label`"
        class="app-switch__label"
      >
        {{ label }}
      </span>
      <span
        v-if="description"
        :id="`${id}-description`"
        class="app-switch__description"
      >
        {{ description }}
      </span>
    </span>
    <span class="app-switch__control">
      <input
        ref="input"
        :id="id"
        v-bind="$attrs"
        v-model="model"
        type="checkbox"
        role="switch"
        :disabled="disabled"
        :name="name"
        :aria-labelledby="`${id}-label`"
        :aria-describedby="describedBy()"
      />
      <span
        class="app-switch__track"
        aria-hidden="true"
      >
        <span class="app-switch__thumb" />
      </span>
    </span>
  </label>
</template>

<style scoped>
.app-switch {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-20);
  min-height: 44px;
  min-width: 0;
  cursor: pointer;
}
.app-switch__copy {
  display: grid;
  gap: var(--space-4);
  min-width: 0;
}
.app-switch__label {
  font-size: var(--type-body-medium-emphasis-size);
  letter-spacing: var(--type-body-medium-emphasis-tracking);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  overflow-wrap: anywhere;
}
.app-switch__description {
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  color: var(--text-2);
  transition: var(--theme-transition);
}
.app-switch__control {
  position: relative;
  display: grid;
  place-items: center;
  flex: 0 0 52px;
  width: 52px;
  height: 44px;
}
.app-switch input {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  margin: 0;
  opacity: 0;
  z-index: 1;
  cursor: inherit;
}
.app-switch__track {
  position: relative;
  width: 52px;
  height: 32px;
  border: 1px solid var(--text-2);
  border-radius: 999px;
  background: var(--surface-2);
  transition: var(--theme-transition);
}
.app-switch__thumb {
  display: block;
  position: absolute;
  top: 3px;
  left: 3px;
  width: 24px;
  height: 24px;
  border-radius: 999px;
  background: var(--color-white);
  box-shadow: var(--shadow-soft);
  border: 1px solid var(--border-strong);
  transition:
    var(--theme-transition),
    transform var(--motion-control-duration) var(--motion-spring-ease),
    width var(--motion-control-duration) var(--motion-spring-ease);
}
.app-switch input:checked + .app-switch__track {
  background: var(--accent);
  border-color: var(--accent);
}
.app-switch input:checked + .app-switch__track .app-switch__thumb {
  transform: translateX(20px);
  border-color: var(--color-white);
}
.app-switch input:focus-visible + .app-switch__track {
  outline: 2px solid var(--focus-border);
  outline-offset: 4px;
}
.app-switch:active:not(.app-switch--disabled) .app-switch__thumb {
  width: 28px;
}
.app-switch:active:not(.app-switch--disabled)
  input:checked
  + .app-switch__track
  .app-switch__thumb {
  transform: translateX(16px);
}
.app-switch--disabled {
  opacity: 0.45;
  cursor: not-allowed;
}
@media (hover: hover) {
  .app-switch:hover:not(.app-switch--disabled) .app-switch__track {
    box-shadow: var(--focus-ring);
  }
}
@media (forced-colors: active) {
  .app-switch__track {
    forced-color-adjust: none;
    background: Canvas;
    border-color: ButtonText;
  }
  .app-switch input:checked + .app-switch__track {
    background: Highlight;
    border-color: Highlight;
  }
  .app-switch__thumb {
    background: ButtonText;
  }
  .app-switch input:checked + .app-switch__track .app-switch__thumb {
    background: HighlightText;
  }
}
</style>
