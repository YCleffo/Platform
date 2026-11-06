<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { Check, Tag } from '@/shared/ui/icons';
import type { PlanPrice } from '@/shared/types';
import { formatMoney } from '@/shared/formatting/money';
import { planFeatureEntries } from './features';
import { latestPlans } from './comparison';
import { usePlanCatalog } from './usePlanCatalog';
import { usePlanTarget } from './planTarget';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';

const route = useRoute();
const { plans, loading, errorMessage, load } = usePlanCatalog();
const target = usePlanTarget();
const { t, locale } = useI18n();

const code = computed(() => String(route.params.code ?? ''));
const plan = computed(() => latestPlans(plans.value).find((item) => item.code === code.value));
const features = computed(() =>
  plan.value === undefined ? [] : planFeatureEntries(plan.value.features),
);

function periodLabel(period: string): string {
  const key = `catalog.period_${period}`;
  const translated = t(key);
  return translated === key ? period : translated;
}

function priceText(price: PlanPrice): string {
  return `${formatMoney(price.amount_minor, price.currency, locale.value)} / ${periodLabel(price.billing_period)}`;
}
</script>

<template>
  <section class="plan-detail">
    <RouterLink
      class="plan-detail__back"
      :to="{ name: 'plans' }"
    >
      {{ $t('plans.backToComparison') }}
    </RouterLink>

    <div v-if="loading">
      <AppCard padding="large">
        <AppSkeleton
          variant="card"
          :lines="5"
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
      v-else-if="plan === undefined"
      :title="$t('plans.notFound')"
      :description="$t('plans.notFoundText')"
    >
      <RouterLink
        class="plan-detail__link"
        :to="{ name: 'catalog' }"
      >
        {{ $t('plans.backToCatalog') }}
      </RouterLink>
    </AppEmptyState>

    <AppCard
      v-else
      padding="large"
    >
      <header class="plan-detail__header">
        <div>
          <p class="plan-detail__meta">{{ plan.code }} В· v{{ plan.version }}</p>
          <h1 class="plan-detail__title">{{ plan.name }}</h1>
          <p class="plan-detail__subtitle">{{ $t('plans.detailSubtitle') }}</p>
        </div>
        <RouterLink
          class="plan-detail__cta"
          :to="target(plan)"
        >
          {{ $t('catalog.choosePlan') }}
        </RouterLink>
      </header>

      <section
        class="plan-detail__section"
        :aria-labelledby="'plan-prices'"
      >
        <h2
          id="plan-prices"
          class="plan-detail__heading"
        >
          {{ $t('plans.prices') }}
        </h2>
        <ul class="plan-detail__prices">
          <li
            v-for="price in plan.prices"
            :key="price.id"
          >
            {{ priceText(price) }}
          </li>
        </ul>
      </section>

      <section
        class="plan-detail__section"
        :aria-labelledby="'plan-features'"
      >
        <h2
          id="plan-features"
          class="plan-detail__heading"
        >
          {{ $t('catalog.features') }}
        </h2>
        <ul
          v-if="features.length > 0"
          class="plan-detail__features"
        >
          <li
            v-for="feature in features"
            :key="feature.code"
            class="plan-detail__feature"
          >
            <span
              v-if="feature.kind === 'included'"
              class="plan-detail__check"
              aria-hidden="true"
            >
              <Check :size="14" />
            </span>
            <span
              v-else
              class="plan-detail__dot"
              aria-hidden="true"
            ></span>
            <span>{{ feature.label }}</span>
            <span
              v-if="feature.kind === 'value' && feature.value !== ''"
              class="plan-detail__value"
            >
              {{ feature.value }}
            </span>
          </li>
        </ul>
        <p
          v-else
          class="plan-detail__empty"
        >
          <Tag
            :size="14"
            aria-hidden="true"
          />
          {{ $t('catalog.noFeatures') }}
        </p>
      </section>
    </AppCard>
  </section>
</template>

<style scoped>
.plan-detail {
  max-width: 880px;
  margin: 0 auto;
}

.plan-detail__back {
  display: inline-block;
  margin-bottom: var(--space-16);
  color: var(--accent-strong);
  font-size: var(--type-body-medium-emphasis-size);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  text-decoration: underline;
  text-underline-offset: 3px;
}

.plan-detail__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-20);
}

.plan-detail__meta {
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  color: var(--text-3);
}

.plan-detail__title {
  margin-top: var(--space-4);
  font-size: var(--type-h1-size);
  line-height: var(--type-h1-line);
  font-weight: var(--type-h1-weight);
  letter-spacing: var(--type-h1-tracking);
  color: var(--text-1);
}

.plan-detail__subtitle {
  margin-top: var(--space-4);
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  color: var(--text-2);
}

.plan-detail__cta,
.plan-detail__link {
  display: inline-block;
  padding: var(--space-10) var(--space-16);
  border-radius: var(--radius-control-m);
  background: var(--accent);
  color: var(--color-white);
  font-size: var(--type-body-medium-emphasis-size);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  text-decoration: none;
  white-space: nowrap;
}

.plan-detail__cta:hover,
.plan-detail__link:hover {
  background: var(--accent-strong);
}

.plan-detail__section {
  margin-top: var(--space-24);
  padding-top: var(--space-20);
  border-top: 1px solid var(--border);
}

.plan-detail__heading {
  font-size: var(--type-h2-size);
  line-height: var(--type-h2-line);
  font-weight: var(--type-h2-weight);
  letter-spacing: var(--type-h2-tracking);
  color: var(--text-1);
}

.plan-detail__prices {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-8);
  margin-top: var(--space-12);
}

.plan-detail__prices li {
  padding: 4px 10px;
  border: 1px solid var(--border);
  border-radius: var(--radius-badge-pill);
  font-size: var(--type-body-medium-emphasis-size);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  color: var(--accent-strong);
}

.plan-detail__features {
  display: grid;
  gap: var(--space-10);
  margin-top: var(--space-12);
}

.plan-detail__feature {
  display: flex;
  align-items: center;
  gap: var(--space-8);
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  color: var(--text-2);
}

.plan-detail__check {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  border-radius: var(--radius-circle);
  background: var(--success-soft);
  color: var(--success);
  flex: 0 0 auto;
}

.plan-detail__dot {
  width: 20px;
  height: 20px;
  flex: 0 0 auto;
}

.plan-detail__value {
  margin-left: auto;
  color: var(--text-1);
  font-weight: var(--type-body-medium-emphasis-weight);
}

.plan-detail__empty {
  display: flex;
  align-items: center;
  gap: var(--space-6);
  margin-top: var(--space-12);
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  color: var(--text-3);
}

@media (max-width: 767px) {
  .plan-detail__header {
    flex-direction: column;
  }

  .plan-detail__cta {
    width: 100%;
    text-align: center;
  }
}
</style>
