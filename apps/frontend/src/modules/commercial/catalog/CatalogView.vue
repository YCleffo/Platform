<script setup lang="ts">
import { RouterLink } from 'vue-router';
import { usePlanCatalog } from './usePlanCatalog';
import { usePlanTarget } from './planTarget';
import PlanCard from './PlanCard.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';

const { plans, loading, errorMessage, load } = usePlanCatalog();
const planTarget = usePlanTarget();
</script>

<template>
  <section class="catalog">
    <header class="catalog__header">
      <div>
        <h1 class="catalog__title">{{ $t('catalog.title') }}</h1>
        <p class="catalog__subtitle">{{ $t('catalog.subtitle') }}</p>
      </div>
      <RouterLink
        class="catalog__compare"
        :to="{ name: 'plans' }"
      >
        {{ $t('plans.compareTitle') }}
      </RouterLink>
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
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-16);
  margin-bottom: var(--space-24);
}

.catalog__compare {
  flex: 0 0 auto;
  color: var(--accent-strong);
  font-size: var(--type-body-medium-emphasis-size);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  text-decoration: underline;
  text-underline-offset: 3px;
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

@media (max-width: 767px) {
  .catalog__header {
    flex-direction: column;
  }
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
