<script setup lang="ts">
import { CircleAlert } from '@lucide/vue';

interface Props {
  title?: string;
  message?: string;
}

withDefaults(defineProps<Props>(), {
  title: undefined,
  message: undefined,
});

defineEmits<{ retry: [] }>();
</script>

<template>
  <div class="app-error" role="alert">
    <div class="app-error__icon">
      <CircleAlert :size="24" aria-hidden="true" />
    </div>
    <p v-if="title" class="app-error__title">{{ title }}</p>
    <p v-if="message" class="app-error__message">{{ message }}</p>
    <slot />
  </div>
</template>

<style scoped>
.app-error {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-8);
  padding: var(--space-40) var(--space-16);
  text-align: center;
}

.app-error__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  border-radius: var(--radius-badge-module-tile);
  background: var(--danger-soft);
  color: var(--danger);
}

.app-error__title {
  font-size: var(--type-body-large-emphasis-size);
  line-height: var(--type-body-large-emphasis-line);
  font-weight: var(--type-body-large-emphasis-weight);
  color: var(--text-1);
}

.app-error__message {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  color: var(--text-2);
}
</style>
