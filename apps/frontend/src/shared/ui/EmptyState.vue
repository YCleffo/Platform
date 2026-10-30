<script setup lang="ts">
interface Props {
  title?: string;
  description?: string;
}

withDefaults(defineProps<Props>(), {
  title: undefined,
  description: undefined,
});
</script>

<template>
  <div class="empty">
    <p v-if="title" class="empty__title">{{ title }}</p>
    <p v-if="description" class="empty__description">{{ description }}</p>
    <div v-if="$slots.default" class="empty__action">
      <slot />
    </div>
  </div>
</template>

<style scoped>
.empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-8);
  text-align: center;
  color: var(--color-text-muted);
}

.empty__title {
  font-size: var(--text-lg);
  font-weight: 600;
  color: var(--color-text);
}

.empty__description {
  font-size: var(--text-sm);
}

.empty__action {
  margin-top: var(--space-3);
}
</style>
