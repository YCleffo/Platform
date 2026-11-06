<script setup lang="ts">
import { RouterLink, type RouteLocationRaw } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { Check, Tag } from '@/shared/ui/icons';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppDivider from '@/shared/ui/surfaces/AppDivider.vue';
import { formatMoney } from '@/shared/formatting/money';
import { planFeatureEntries } from './features';
import type { Plan, PlanPrice } from '@/shared/types';

defineProps<{ plan: Plan; target: RouteLocationRaw }>();

const { t, locale } = useI18n();

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
  <AppCard
    padding="default"
    class="plan"
  >
    <div class="plan__header">
      <h2 class="plan__name">{{ plan.name }}</h2>
      <span class="plan__version">v{{ plan.version }}</span>
    </div>
    <p class="plan__code">{{ plan.code }}</p>

    <ul
      v-if="plan.prices.length > 0"
      class="plan__prices"
    >
      <li
        v-for="price in plan.prices"
        :key="price.id"
        class="plan__price"
      >
        {{ priceText(price) }}
      </li>
    </ul>

    <AppDivider class="plan__divider" />

    <ul
      v-if="planFeatureEntries(plan.features).length > 0"
      class="plan__features"
    >
      <li
        v-for="entry in planFeatureEntries(plan.features)"
        :key="entry.code"
        class="plan__feature"
      >
        <span
          v-if="entry.kind === 'included'"
          class="plan__feature-check"
          aria-hidden="true"
        >
          <Check :size="14" />
        </span>
        <span
          v-else
          class="plan__feature-dot"
          aria-hidden="true"
        ></span>
        <span class="plan__feature-label">{{ entry.label }}</span>
        <span
          v-if="entry.kind === 'value' && entry.value !== ''"
          class="plan__feature-value"
        >
          {{ entry.value }}
        </span>
      </li>
    </ul>
    <p
      v-else
      class="plan__empty"
    >
      <Tag
        :size="14"
        aria-hidden="true"
      />
      {{ $t('catalog.noFeatures') }}
    </p>
    <RouterLink
      class="plan__cta"
      :to="target"
    >
      {{ $t('catalog.choosePlan') }}
    </RouterLink>
    <RouterLink
      class="plan__details"
      :to="{ name: 'plan-detail', params: { code: plan.code } }"
    >
      {{ $t('plans.viewDetails') }}
    </RouterLink>
  </AppCard>
</template>

<style scoped>
.plan {
  display: flex;
  flex-direction: column;
}

.plan__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-12);
}

.plan__name {
  font-size: var(--type-h2-size);
  line-height: var(--type-h2-line);
  font-weight: var(--type-h2-weight);
  letter-spacing: var(--type-h2-tracking);
  color: var(--text-1);
  transition: var(--theme-transition);
}

.plan__version {
  font-size: var(--type-body-small-size);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  color: var(--text-3);
  white-space: nowrap;
  transition: var(--theme-transition);
}

.plan__code {
  margin-top: var(--space-4);
  font-size: var(--type-body-small-size);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  color: var(--text-3);
  transition: var(--theme-transition);
}

.plan__prices {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-8);
  margin-top: var(--space-12);
}

.plan__price {
  padding: 4px 10px;
  border: 1px solid var(--border);
  border-radius: var(--radius-badge-pill);
  font-size: var(--type-body-medium-emphasis-size);
  letter-spacing: var(--type-body-medium-emphasis-tracking);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  color: var(--accent-strong);
  transition: var(--theme-transition);
}

.plan__divider {
  margin: var(--space-16) 0;
}

.plan__features {
  display: grid;
  gap: var(--space-10);
}

.plan__feature {
  display: flex;
  align-items: center;
  gap: var(--space-8);
  font-size: var(--type-body-medium-size);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  color: var(--text-2);
  transition: var(--theme-transition);
}

.plan__feature-check {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  border-radius: var(--radius-circle);
  background: var(--success-soft);
  color: var(--success);
  flex: 0 0 auto;
  transition: var(--theme-transition);
}

.plan__feature-dot {
  width: 20px;
  height: 20px;
  flex: 0 0 auto;
}

.plan__feature-label {
  flex: 1;
  min-width: 0;
}

.plan__feature-value {
  color: var(--text-1);
  font-weight: var(--type-body-medium-emphasis-weight);
  transition: var(--theme-transition);
}

.plan__details {
  margin-top: var(--space-8);
  color: var(--accent-strong);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  text-align: center;
  text-decoration: underline;
  text-underline-offset: 3px;
}

.plan__cta {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 42px;
  margin-top: var(--space-16);
  padding: var(--space-8) var(--space-16);
  border-radius: var(--radius-control-m);
  background: var(--accent);
  color: var(--color-white);
  font-size: var(--type-body-medium-emphasis-size);
  letter-spacing: var(--type-body-medium-emphasis-tracking);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  text-decoration: none;
  transition: var(--theme-transition);
}
.plan__cta:hover {
  background: var(--accent-hover);
  text-decoration: none;
}
.plan__empty {
  display: flex;
  align-items: center;
  gap: var(--space-8);
  font-size: var(--type-body-medium-size);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  color: var(--text-3);
  transition: var(--theme-transition);
}
</style>
