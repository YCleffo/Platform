<script setup lang="ts">
interface Props {
  variant?: 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info';
  size?: 's' | 'm';
  dot?: boolean;
}

withDefaults(defineProps<Props>(), {
  variant: 'neutral',
  size: 'm',
  dot: false,
});
</script>

<template>
  <span class="app-badge" :class="[`app-badge--${variant}`, `app-badge--${size}`]">
    <span v-if="dot" class="app-badge__dot" aria-hidden="true"></span>
    <slot />
  </span>
</template>

<style scoped>
.app-badge {
  display: inline-flex;
  align-items: center;
  gap: var(--space-4);
  border-radius: var(--radius-badge-pill);
  font-weight: 500;
  white-space: nowrap;
}

.app-badge--s {
  padding: 2px 8px;
  font-size: 11px;
  line-height: 16px;
}

.app-badge--m {
  padding: 3px 10px;
  font-size: 12px;
  line-height: 16px;
}

.app-badge--neutral {
  background: var(--surface-2);
  color: var(--text-2);
}

.app-badge--primary {
  background: var(--accent-soft);
  color: var(--accent-strong);
}

.app-badge--success {
  background: var(--success-soft);
  color: var(--success-text);
}

.app-badge--warning {
  background: var(--warning-soft);
  color: var(--warning-text);
}

.app-badge--danger {
  background: var(--danger-soft);
  color: var(--danger-text);
}

.app-badge--info {
  background: var(--info-soft);
  color: var(--info-text);
}

.app-badge__dot {
  width: 6px;
  height: 6px;
  border-radius: var(--radius-circle);
  background: currentColor;
}
</style>
