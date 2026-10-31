<script setup lang="ts">
interface Props {
  variant?: 'text' | 'card' | 'list';
  lines?: number;
}

withDefaults(defineProps<Props>(), {
  variant: 'text',
  lines: 3,
});
</script>

<template>
  <div class="app-skeleton" :class="`app-skeleton--${variant}`" aria-hidden="true">
    <template v-if="variant === 'text'">
      <span v-for="i in lines" :key="i" class="app-skeleton__line" :style="{ width: `${95 - (i % 3) * 18}%` }" />
    </template>
    <template v-else-if="variant === 'card'">
      <span class="app-skeleton__block app-skeleton__block--tall" />
      <span class="app-skeleton__line" style="width: 60%" />
      <span class="app-skeleton__line" style="width: 40%" />
    </template>
    <template v-else>
      <span v-for="i in lines" :key="i" class="app-skeleton__row" />
    </template>
  </div>
</template>

<style scoped>
.app-skeleton {
  display: grid;
  gap: var(--space-8);
}

.app-skeleton--card {
  gap: var(--space-12);
}

.app-skeleton__line,
.app-skeleton__row,
.app-skeleton__block {
  display: block;
  border-radius: var(--radius-control-s);
  background: var(--surface-hover);
  animation: app-skeleton-pulse var(--motion-skeleton-duration) var(--motion-pulse-ease) infinite;
  transition: var(--theme-transition);
}

.app-skeleton__line {
  height: 12px;
}

.app-skeleton__row {
  height: 40px;
  border-radius: var(--radius-control-m);
}

.app-skeleton__block--tall {
  height: 96px;
  border-radius: var(--radius-card-s);
}

@keyframes app-skeleton-pulse {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0.5;
  }
}
</style>
