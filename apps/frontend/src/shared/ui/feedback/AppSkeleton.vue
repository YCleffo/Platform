<script setup lang="ts">
interface Props {
  variant?: 'text' | 'card' | 'list' | 'table';
  lines?: number;
  rows?: number;
  columns?: number;
  height?: string;
}

withDefaults(defineProps<Props>(), {
  variant: 'text',
  lines: 3,
  rows: 4,
  columns: 4,
  height: undefined,
});
</script>

<template>
  <div
    class="app-skeleton"
    :class="`app-skeleton--${variant}`"
    :style="height ? { minHeight: height } : undefined"
    aria-hidden="true"
  >
    <template v-if="variant === 'text'">
      <span
        v-for="i in lines"
        :key="i"
        class="app-skeleton__line"
        :style="{ width: `${95 - (i % 3) * 18}%` }"
      />
    </template>
    <template v-else-if="variant === 'card'">
      <span class="app-skeleton__block app-skeleton__block--tall" />
      <span
        class="app-skeleton__line"
        style="width: 60%"
      />
      <span
        class="app-skeleton__line"
        style="width: 40%"
      />
    </template>
    <template v-else-if="variant === 'table'">
      <div class="app-skeleton__table">
        <div
          class="app-skeleton__table-header"
          :style="{ gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))` }"
        >
          <span
            v-for="c in columns"
            :key="`th-${c}`"
            class="app-skeleton__table-th"
            :style="{ width: `${Math.max(45, 80 - (c % 3) * 20)}%` }"
          />
        </div>
        <div
          v-for="r in rows"
          :key="`tr-${r}`"
          class="app-skeleton__table-row"
          :style="{ gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))` }"
        >
          <span
            v-for="c in columns"
            :key="`td-${r}-${c}`"
            class="app-skeleton__table-cell"
            :style="{ width: `${Math.max(35, 90 - ((r * 2 + c) % 5) * 14)}%` }"
          />
        </div>
      </div>
    </template>
    <template v-else>
      <span
        v-for="i in lines"
        :key="i"
        class="app-skeleton__row"
      />
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

.app-skeleton--table {
  width: 100%;
}

.app-skeleton__table {
  display: grid;
  gap: var(--space-8);
  width: 100%;
}

.app-skeleton__table-header {
  display: grid;
  gap: var(--space-12);
  padding: var(--space-12) var(--space-16);
  background: var(--surface-2);
  border-bottom: 1px solid var(--border);
  border-radius: var(--radius-control-s) var(--radius-control-s) 0 0;
}

.app-skeleton__table-th {
  display: block;
  height: 14px;
  border-radius: var(--radius-control-s);
  background: var(--surface-hover);
  animation: app-skeleton-pulse var(--motion-skeleton-duration) var(--motion-pulse-ease) infinite;
  transition: var(--theme-transition);
}

.app-skeleton__table-row {
  display: grid;
  gap: var(--space-12);
  padding: var(--space-12) var(--space-16);
  border-bottom: 1px solid var(--border);
  align-items: center;
}

.app-skeleton__table-row:last-child {
  border-bottom: none;
}

.app-skeleton__table-cell {
  display: block;
  height: 14px;
  border-radius: var(--radius-control-s);
  background: var(--surface-hover);
  animation: app-skeleton-pulse var(--motion-skeleton-duration) var(--motion-pulse-ease) infinite;
  transition: var(--theme-transition);
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
