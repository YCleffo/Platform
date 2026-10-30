<script setup lang="ts">
import type { Component } from 'vue';
import { Inbox } from '@lucide/vue';

interface Props {
  title?: string;
  description?: string;
  icon?: Component;
}

withDefaults(defineProps<Props>(), {
  title: undefined,
  description: undefined,
  icon: undefined,
});
</script>

<template>
  <div class="app-empty">
    <div class="app-empty__icon">
      <component :is="icon ?? Inbox" :size="24" aria-hidden="true" />
    </div>
    <p v-if="title" class="app-empty__title">{{ title }}</p>
    <p v-if="description" class="app-empty__description">{{ description }}</p>
    <div v-if="$slots.default" class="app-empty__action">
      <slot />
    </div>
  </div>
</template>

<style scoped>
.app-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-8);
  padding: var(--space-40) var(--space-16);
  text-align: center;
}

.app-empty__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  border-radius: var(--radius-badge-module-tile);
  background: var(--surface-2);
  color: var(--text-3);
}

.app-empty__title {
  font-size: 16px;
  line-height: 24px;
  font-weight: 500;
  color: var(--text-1);
}

.app-empty__description {
  font-size: 14px;
  line-height: 20px;
  color: var(--text-2);
}

.app-empty__action {
  margin-top: var(--space-8);
}
</style>
