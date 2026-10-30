<script setup lang="ts">
import { RouterLink } from 'vue-router';
</script>

<template>
  <div class="auth-layout">
    <main class="auth-card">
      <RouterLink class="auth-card__brand" :to="{ name: 'catalog' }">
        {{ $t('app.name') }}
      </RouterLink>
      <RouterView />
    </main>
  </div>
</template>

<style scoped>
.auth-layout {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--space-4);
}

.auth-card {
  width: 100%;
  max-width: 26rem;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-sm);
  padding: var(--space-6);
  display: flex;
  flex-direction: column;
  gap: var(--space-5);
}

.auth-card__brand {
  text-align: center;
  font-weight: 700;
  font-size: var(--text-xl);
  color: var(--color-text);
}
</style>
