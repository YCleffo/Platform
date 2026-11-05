<script setup lang="ts">
import { onMounted } from 'vue';
import type { RouteLocationRaw } from 'vue-router';
import { useAuthStore } from '@/core/auth/authStore';
import { useAccountStore } from '@/core/account/accountStore';
import type { Plan } from '@/shared/types';
import { usePlanCatalog } from './usePlanCatalog';
import PlanCard from './PlanCard.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';

const { plans, loading, errorMessage, load } = usePlanCatalog();
const auth = useAuthStore();
const accounts = useAccountStore();

onMounted(() => {
  if (auth.isAuthenticated && accounts.accounts.length === 0 && !accounts.loading) {
    void accounts.loadPage(0);
  }
});

function planTarget(plan: Plan): RouteLocationRaw {
  if (!auth.isAuthenticated) {
    return { name: 'login', query: { redirect: '/' } };
  }
  const accountId = accounts.accounts[0]?.id;
  if (accountId === undefined) {
    return { name: 'cabinet' };
  }
  const price = plan.prices[0];
  return {
    name: 'account-billing',
    params: { accountId },
    query: price === undefined ? {} : { planPrice: price.id },
  };
}
</script>

<template>
  <section class="catalog">
    <header class="catalog__header">
      <h1 class="catalog__title">{{ $t('catalog.title') }}</h1>
      <p class="catalog__subtitle">{{ $t('catalog.subtitle') }}</p>
    </header>

    <div
      v-if="loading"
      class="catalog__grid"
    >
      <AppCard
        v-for="i in 3"
        :key="i"
        padding="default"
      >
        <AppSkeleton
          variant="card"
          :lines="2"
        />
      </AppCard>
    </div>

    <AppErrorState
      v-else-if="errorMessage"
      :message="errorMessage"
    >
      <AppButton
        variant="secondary"
        @click="load"
      >
        {{ $t('common.retry') }}
      </AppButton>
    </AppErrorState>

    <AppEmptyState
      v-else-if="plans.length === 0"
      :title="$t('catalog.noPlans')"
    />

    <div
      v-else
      class="catalog__grid"
    >
      <PlanCard
        v-for="plan in plans"
        :key="plan.id"
        :plan="plan"
        :target="planTarget(plan)"
      />
    </div>
  </section>
</template>

<style scoped>
.catalog {
  max-width: 1120px;
  margin: 0 auto;
}

.catalog__header {
  margin-bottom: var(--space-24);
}

.catalog__title {
  font-size: var(--type-h1-size);
  line-height: var(--type-h1-line);
  font-weight: var(--type-h1-weight);
  letter-spacing: var(--type-h1-tracking);
  color: var(--text-1);
  transition: var(--theme-transition);
}

.catalog__subtitle {
  margin-top: var(--space-4);
  font-size: var(--type-body-medium-size);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  color: var(--text-2);
  transition: var(--theme-transition);
}

.catalog__grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--space-16);
}

@media (min-width: 768px) {
  .catalog__grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-20);
  }
}

@media (min-width: 1024px) {
  .catalog__grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
</style>
