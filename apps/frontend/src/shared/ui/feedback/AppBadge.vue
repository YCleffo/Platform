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
  <span
    class="app-badge"
    :class="[`app-badge--${variant}`, `app-badge--${size}`]"
  >
    <span
      v-if="dot"
      class="app-badge__dot"
      aria-hidden="true"
    ></span>
    <slot />
  </span>
</template>

<style scoped>
.app-badge {
  display: inline-flex;
  align-items: center;
  gap: var(--gap-xs);
  border-radius: var(--radius-badge-pill);
  max-width: 100%;
  min-width: 0;
  white-space: normal;
  overflow-wrap: anywhere;
}

.app-badge--s {
  padding: var(--pad-badge-compact-vertical) var(--pad-badge-compact-horizontal);
  font-size: var(--type-body-caption-size);
  letter-spacing: var(--type-body-caption-tracking);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
}

.app-badge--m {
  padding: var(--pad-badge-default-vertical) var(--pad-badge-default-horizontal);
  font-size: var(--type-body-small-size);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
}

.app-badge--neutral {
  background: var(--surface-2);
  color: var(--text-2);
  transition: var(--theme-transition);
}

.app-badge--primary {
  background: var(--accent-soft);
  color: var(--accent-strong);
  transition: var(--theme-transition);
}

.app-badge--success {
  background: var(--success-soft);
  color: var(--success-text);
  transition: var(--theme-transition);
}

.app-badge--warning {
  background: var(--warning-soft);
  color: var(--warning-text);
  transition: var(--theme-transition);
}

.app-badge--danger {
  background: var(--danger-soft);
  color: var(--danger-text);
  transition: var(--theme-transition);
}

.app-badge--info {
  background: var(--info-soft);
  color: var(--info-text);
  transition: var(--theme-transition);
}

.app-badge__dot {
  flex-shrink: 0;
  width: 6px;
  height: 6px;
  border-radius: var(--radius-circle);
  background: currentColor;
  transition: var(--theme-transition);
}
</style>
