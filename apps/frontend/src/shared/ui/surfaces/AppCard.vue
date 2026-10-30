<script setup lang="ts">
interface Props {
  interactive?: boolean;
  padding?: 'compact' | 'default' | 'large';
}

withDefaults(defineProps<Props>(), {
  interactive: false,
  padding: 'default',
});
</script>

<template>
  <div
    class="app-card"
    :class="[`app-card--${padding}`, { 'app-card--interactive': interactive }]"
  >
    <slot />
  </div>
</template>

<style scoped>
.app-card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: var(--radius-card-m);
  box-shadow: var(--shadow-soft);
}

.app-card--compact {
  padding: var(--space-16);
}

.app-card--default {
  padding: var(--space-20);
}

.app-card--large {
  padding: var(--space-24);
}

.app-card--interactive {
  transition:
    border-color 120ms ease,
    box-shadow 120ms ease;
}

.app-card--interactive:hover {
  border-color: var(--border-strong);
}
</style>
