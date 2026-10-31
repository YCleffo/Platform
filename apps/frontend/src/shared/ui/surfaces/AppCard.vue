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
  transition: var(--theme-transition);
}

.app-card--compact {
  padding: var(--pad-card-compact);
}

.app-card--default {
  padding: var(--pad-card-default);
}

.app-card--large {
  padding: var(--pad-card-large);
}

.app-card--interactive {
  transition:
    var(--theme-transition);
}

.app-card--interactive:hover {
  border-color: var(--border-strong);
  transition: var(--theme-transition);
}
</style>
