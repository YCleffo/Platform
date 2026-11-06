<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { Check } from '@/shared/ui/icons';
import type { Plan, PlanPrice } from '@/shared/types';
import { formatMoney } from '@/shared/formatting/money';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import { comparisonRows, latestPlans } from './comparison';
import { usePlanCatalog } from './usePlanCatalog';
import { usePlanTarget } from './planTarget';

const { plans, loading, errorMessage, load } = usePlanCatalog();
const target = usePlanTarget();
const { t, locale } = useI18n();

const comparedPlans = computed(() => latestPlans(plans.value));
const rows = computed(() => comparisonRows(comparedPlans.value));

function periodLabel(period: string): string {
  const key = `catalog.period_${period}`;
  const translated = t(key);
  return translated === key ? period : translated;
}

function priceText(price: PlanPrice): string {
  return `${formatMoney(price.amount_minor, price.currency, locale.value)} / ${periodLabel(price.billing_period)}`;
}

function detailTarget(plan: Plan) {
  return { name: 'plan-detail', params: { code: plan.code } };
}
</script>

<template>
  <section class="plans">
    <header class="plans__header">
      <div>
        <h1 class="plans__title">{{ $t('plans.compareTitle') }}</h1>
        <p class="plans__subtitle">{{ $t('plans.compareSubtitle') }}</p>
      </div>
      <RouterLink
        class="plans__back"
        :to="{ name: 'catalog' }"
      >
        {{ $t('plans.backToCatalog') }}
      </RouterLink>
    </header>

    <div
      v-if="loading"
      class="plans__grid"
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
      v-else-if="comparedPlans.length === 0"
      :title="$t('catalog.noPlans')"
    />

    <div
      v-else
      class="plans__scroll"
      role="region"
      :aria-label="$t('plans.compareTitle')"
      tabindex="0"
    >
      <table class="plans__table">
        <caption class="plans__caption">{{ $t('plans.compareTitle') }}</caption>
        <thead>
          <tr>
            <th
              class="plans__feature-head"
              scope="col"
            >
              {{ $t('plans.feature') }}
            </th>
            <th
              v-for="plan in comparedPlans"
              :key="plan.id"
              class="plans__plan-head"
              scope="col"
            >
              <div class="plans__plan-name">{{ plan.name }}</div>
              <div class="plans__plan-meta">v{{ plan.version }} В· {{ plan.code }}</div>
              <ul
                v-if="plan.prices.length > 0"
                class="plans__prices"
              >
                <li
                  v-for="price in plan.prices"
                  :key="price.id"
                >
                  {{ priceText(price) }}
                </li>
              </ul>
              <RouterLink
                class="plans__details"
                :to="detailTarget(plan)"
              >
                {{ $t('plans.viewDetails') }}
              </RouterLink>
              <RouterLink
                class="plans__cta"
                :to="target(plan)"
              >
                {{ $t('catalog.choosePlan') }}
              </RouterLink>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="row in rows"
            :key="row.code"
          >
            <th
              class="plans__feature"
              scope="row"
            >
              {{ row.label }}
            </th>
            <td
              v-for="(value, index) in row.values"
              :key="(comparedPlans[index]?.id ?? '') + row.code"
            >
              <span
                v-if="value?.kind === 'included'"
                class="plans__included"
              >
                <Check
                  :size="14"
                  aria-hidden="true"
                />
                {{ $t('plans.included') }}
              </span>
              <span
                v-else-if="value?.kind === 'value'"
                class="plans__value"
              >
                {{ value.value }}
              </span>
              <span
                v-else
                class="plans__na"
                :aria-label="$t('plans.notIncluded')"
              >
                вЂ”
              </span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
.plans {
  max-width: 1180px;
  margin: 0 auto;
}

.plans__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-16);
  margin-bottom: var(--space-20);
}

.plans__title {
  font-size: var(--type-h1-size);
  line-height: var(--type-h1-line);
  font-weight: var(--type-h1-weight);
  letter-spacing: var(--type-h1-tracking);
  color: var(--text-1);
  transition: var(--theme-transition);
}

.plans__subtitle {
  margin-top: var(--space-4);
  font-size: var(--type-body-medium-size);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  color: var(--text-2);
  transition: var(--theme-transition);
}

.plans__back,
.plans__details {
  color: var(--accent-strong);
  font-size: var(--type-body-medium-emphasis-size);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  text-decoration: underline;
  text-underline-offset: 3px;
}

.plans__back {
  flex: 0 0 auto;
}

.plans__scroll {
  overflow-x: auto;
  border: 1px solid var(--border);
  border-radius: var(--radius-card-m);
  background: var(--card);
  transition: var(--theme-transition);
}

.plans__scroll:focus-visible {
  outline: 2px solid var(--accent);
  outline-offset: 2px;
}

.plans__table {
  width: 100%;
  min-width: 760px;
  border-collapse: collapse;
  text-align: left;
}

.plans__caption {
  padding: var(--space-12) var(--space-16);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  color: var(--text-2);
  text-align: left;
}

.plans__feature-head,
.plans__feature {
  position: sticky;
  left: 0;
  z-index: 1;
  min-width: 180px;
  padding: var(--space-12) var(--space-16);
  background: var(--card);
  border-bottom: 1px solid var(--border);
  font-size: var(--type-body-medium-emphasis-size);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  color: var(--text-1);
}

.plans__feature-head {
  background: var(--surface-2);
}

.plans__plan-head {
  min-width: 210px;
  padding: var(--space-16);
  border-bottom: 1px solid var(--border);
  border-left: 1px solid var(--border);
  vertical-align: top;
}

.plans__plan-name {
  font-size: var(--type-h3-size);
  line-height: var(--type-h3-line);
  font-weight: var(--type-h3-weight);
  letter-spacing: var(--type-h3-tracking);
  color: var(--text-1);
}

.plans__plan-meta,
.plans__prices {
  margin-top: var(--space-4);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  color: var(--text-3);
}

.plans__prices {
  display: grid;
  gap: 2px;
  color: var(--accent-strong);
}

.plans__details {
  display: inline-block;
  margin-top: var(--space-12);
}

.plans__cta {
  display: block;
  margin-top: var(--space-8);
  padding: var(--space-10) var(--space-12);
  border-radius: var(--radius-control-m);
  background: var(--accent);
  color: var(--color-white);
  font-size: var(--type-body-medium-emphasis-size);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  text-align: center;
  text-decoration: none;
  transition: var(--theme-transition);
}

.plans__cta:hover {
  background: var(--accent-strong);
}

.plans__table tbody th,
.plans__table td {
  padding: var(--space-12) var(--space-16);
  border-bottom: 1px solid var(--border);
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  color: var(--text-2);
  vertical-align: top;
}

.plans__table tbody th {
  font-weight: var(--type-body-medium-emphasis-weight);
  color: var(--text-1);
}

.plans__table tbody tr:last-child th,
.plans__table tbody tr:last-child td {
  border-bottom: 0;
}

.plans__included {
  display: inline-flex;
  align-items: center;
  gap: var(--space-4);
  color: var(--success-text);
}

.plans__value {
  color: var(--text-1);
  font-weight: var(--type-body-medium-emphasis-weight);
}

.plans__na {
  color: var(--text-3);
}

.plans__grid {
  display: grid;
  gap: var(--space-16);
}

@media (max-width: 767px) {
  .plans__header {
    flex-direction: column;
  }

  .plans__table {
    min-width: 680px;
  }

  .plans__feature-head,
  .plans__feature {
    min-width: 140px;
    padding: var(--space-8) var(--space-12);
    font-size: var(--type-body-small-size);
    line-height: var(--type-body-small-line);
    font-weight: var(--type-body-small-weight);
  }

  .plans__plan-head {
    min-width: 190px;
    padding: var(--space-12);
  }

  .plans__table tbody th,
  .plans__table td {
    padding: var(--space-8) var(--space-12);
    font-size: var(--type-body-small-size);
    line-height: var(--type-body-small-line);
    font-weight: var(--type-body-small-weight);
  }
}
</style>
