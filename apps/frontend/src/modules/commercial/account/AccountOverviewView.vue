<script setup lang="ts">
import { useAccountStore } from '@/core/account/accountStore';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AccountDetailsPanel from './AccountDetailsPanel.vue';
import AccountStatus from './AccountStatus.vue';

const store = useAccountStore();
</script>

<template>
  <div
    v-if="store.accountDetail"
    class="overview"
  >
    <AppCard padding="default">
      <h2 class="overview__title">{{ $t('account.details') }}</h2>
      <AccountDetailsPanel :account="store.accountDetail" />
    </AppCard>
    <AppCard padding="default">
      <h2 class="overview__title">{{ $t('cabinet.status') }}</h2>
      <AccountStatus
        :status="store.accountDetail.status"
        :onboarding-state="store.accountDetail.onboarding_state"
      />
    </AppCard>
  </div>
</template>

<style scoped>
.overview {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--space-16);
}

.overview__title {
  margin-bottom: var(--space-12);
  font-size: var(--type-h3-size);
  letter-spacing: var(--type-h3-tracking);
  line-height: var(--type-h3-line);
  font-weight: var(--type-h3-weight);
  color: var(--text-1);
  transition: var(--theme-transition);
}

@media (min-width: 768px) {
  .overview {
    grid-template-columns: minmax(0, 3fr) minmax(0, 2fr);
    gap: var(--space-20);
    align-items: start;
  }
}
</style>
