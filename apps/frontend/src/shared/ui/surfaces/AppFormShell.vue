<script setup lang="ts">
interface Props {
  title: string;
  subtitle?: string;
  error?: string | null;
  centered?: boolean;
}

withDefaults(defineProps<Props>(), {
  subtitle: undefined,
  error: null,
  centered: false,
});
</script>

<template>
  <section class="app-form-shell" :class="{ 'app-form-shell--centered': centered }">
    <div v-if="$slots.icon" class="app-form-shell__icon">
      <slot name="icon" />
    </div>
    <h1 class="app-form-shell__title">{{ title }}</h1>
    <p v-if="subtitle" class="app-form-shell__subtitle">{{ subtitle }}</p>
    <p v-if="error" class="app-form-shell__error" role="alert">{{ error }}</p>
    <div class="app-form-shell__body">
      <slot />
    </div>
  </section>
</template>

<style scoped>
.app-form-shell {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--space-8);
}

.app-form-shell--centered {
  text-align: center;
  justify-items: center;
}

.app-form-shell__icon {
  display: flex;
  justify-content: center;
  margin-bottom: var(--space-4);
}

.app-form-shell__title {
  font-size: var(--type-h1-size);
  line-height: var(--type-h1-line);
  font-weight: var(--type-h1-weight);
  letter-spacing: var(--type-h1-tracking);
  color: var(--text-1);
  transition: var(--theme-transition);
}

.app-form-shell__subtitle {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  color: var(--text-2);
  transition: var(--theme-transition);
}

.app-form-shell__error {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  color: var(--danger-text);
  transition: var(--theme-transition);
}

.app-form-shell__body {
  margin-top: var(--space-8);
  width: 100%;
}
</style>
