<script setup lang="ts">
import { Building2, Plus } from '@lucide/vue';
import AccountCard from './AccountCard.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import type { CommercialAccountSummary } from '@/shared/types';

interface Props {
  accounts: CommercialAccountSummary[];
  loading: boolean;
  errorMessage: string | null;
}

defineProps<Props>();

defineEmits<{
  create: [];
  retry: [];
}>();
</script>

<template>
  <div v-if="loading" class="account-list__grid">
    <AppCard v-for="i in 2" :key="i" padding="default">
      <AppSkeleton variant="card" :lines="2" />
    </AppCard>
  </div>

  <AppErrorState v-else-if="errorMessage" :message="errorMessage">
    <AppButton variant="secondary" @click="$emit('retry')">{{ $t('common.retry') }}</AppButton>
  </AppErrorState>

  <AppEmptyState v-else-if="accounts.length === 0" :title="$t('cabinet.noAccounts')" :icon="Building2">
    <AppButton @click="$emit('create')">
      <Plus :size="18" aria-hidden="true" />
      {{ $t('cabinet.create') }}
    </AppButton>
  </AppEmptyState>

  <div v-else class="account-list__grid">
    <RouterLink
      v-for="account in accounts"
      :key="account.id"
      class="account-list__link"
      :to="{ name: 'account-overview', params: { accountId: account.id } }"
    >
      <AccountCard :account="account" />
    </RouterLink>
  </div>
</template>

<style scoped>
.account-list__grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--space-16);
}

.account-list__link {
  display: block;
  color: inherit;
  border-radius: var(--radius-card-m);
}

.account-list__link:hover {
  text-decoration: none;
}

.account-list__link:focus-visible {
  outline: none;
  box-shadow: var(--focus-ring);
}

@media (min-width: 768px) {
  .account-list__grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-20);
  }
}
</style>
