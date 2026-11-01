<script setup lang="ts">
import { useRouter } from 'vue-router';
import { CircleAlert } from '@/shared/ui/icons';
import AppButton from '@/shared/ui/actions/AppButton.vue';

const router = useRouter();
</script>

<template>
  <section class="not-found">
    <span class="not-found__icon" aria-hidden="true">
      <CircleAlert :size="28" />
    </span>
    <h1 class="not-found__title">{{ $t('notFound.title') }}</h1>
    <p class="not-found__text">{{ $t('notFound.text') }}</p>
    <AppButton @click="router.push({ name: 'catalog' })">{{ $t('notFound.back') }}</AppButton>
  </section>
</template>

<style scoped>
.not-found {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: var(--space-12);
  min-height: 50vh;
  padding: var(--space-24);
  text-align: center;
}

.not-found__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 56px;
  height: 56px;
  border-radius: var(--radius-badge-module-tile);
  background: var(--danger-soft);
  color: var(--danger);
  transition: var(--theme-transition);
}

.not-found__title {
  font-size: var(--type-h1-size);
  line-height: var(--type-h1-line);
  font-weight: var(--type-h1-weight);
  letter-spacing: var(--type-h1-tracking);
  color: var(--text-1);
  transition: var(--theme-transition);
}

.not-found__text {
  max-width: 360px;
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  color: var(--text-2);
  transition: var(--theme-transition);
}
</style>
