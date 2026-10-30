<script setup lang="ts">
interface Props {
  title?: string;
  message?: string;
}

withDefaults(defineProps<Props>(), {
  title: undefined,
  message: undefined,
});

defineEmits<{
  retry: [];
}>();
</script>

<template>
  <div class="error" role="alert">
    <p v-if="title" class="error__title">{{ title }}</p>
    <p v-if="message" class="error__message">{{ message }}</p>
    <slot />
  </div>
</template>

<style scoped>
.error {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-6);
  text-align: center;
  color: var(--color-danger);
}

.error__title {
  font-size: var(--text-lg);
  font-weight: 600;
}

.error__message {
  font-size: var(--text-sm);
}
</style>
