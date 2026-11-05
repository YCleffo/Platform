<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import { onboardingVariant, statusVariant } from './accountPresentation';

defineProps<{
  status: string;
  onboardingState?: string;
}>();

const { t } = useI18n();
</script>

<template>
  <span class="account-status">
    <AppBadge
      :variant="statusVariant(status)"
      dot
    >
      {{ t(`cabinet.status_${status}`) }}
    </AppBadge>
    <AppBadge
      v-if="onboardingState"
      :variant="onboardingVariant(onboardingState)"
    >
      {{ t(`cabinet.onboarding_${onboardingState}`) }}
    </AppBadge>
  </span>
</template>

<style scoped>
.account-status {
  display: inline-flex;
  flex-wrap: wrap;
  gap: var(--space-8);
}
</style>
