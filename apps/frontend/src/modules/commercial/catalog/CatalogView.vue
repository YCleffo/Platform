<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Check, Tag } from '@lucide/vue';
import { commercialCatalog } from '@/shared/api/commercial';
import { toErrorMessage } from '@/shared/errors/humanize';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppDivider from '@/shared/ui/surfaces/AppDivider.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import type { Plan } from '@/shared/types';

const { t } = useI18n();

const plans = ref<Plan[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    const result = await commercialCatalog.listPlans();
    plans.value = result.plans;
  } catch (err) {
    error.value = toErrorMessage(err);
  } finally {
    loading.value = false;
  }
}

function formatPrice(amountMinor: number, currency: string): string {
  return new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency,
    maximumFractionDigits: 2,
  }).format(amountMinor / 100);
}

function periodLabel(period: string): string {
  return t(`catalog.period_${period}`);
}

function fromPrice(plan: Plan): string {
  const first = plan.prices[0];
  if (first === undefined) {
    return '';
  }
  return `${formatPrice(first.amount_minor, first.currency)} / ${periodLabel(first.billing_period)}`;
}

function featureLabel(code: string): string {
  const words = code.replace(/[._-]+/g, ' ').trim();
  return words === '' ? code : words.charAt(0).toUpperCase() + words.slice(1);
}

function featureEntries(plan: Plan): Array<{ code: string; value: unknown }> {
  return Object.entries(plan.features ?? {}).map(([code, value]) => ({ code, value }));
}

function featureIncluded(value: unknown): boolean {
  return value === true;
}

function formatFeatureValue(value: unknown): string {
  if (typeof value === 'number') {
    return String(value);
  }
  if (typeof value === 'string') {
    return value;
  }
  return '';
}

onMounted(load);
</script>

<template>
  <section class="catalog">
    <header class="catalog__header">
      <h1 class="catalog__title">{{ $t('catalog.title') }}</h1>
      <p class="catalog__subtitle">{{ $t('catalog.subtitle') }}</p>
    </header>

    <div v-if="loading" class="catalog__grid">
      <AppCard v-for="i in 3" :key="i" padding="default">
        <AppSkeleton variant="card" :lines="2" />
      </AppCard>
    </div>

    <AppErrorState v-else-if="error" :message="error">
      <AppButton variant="secondary" @click="load">{{ $t('common.retry') }}</AppButton>
    </AppErrorState>

    <AppEmptyState v-else-if="plans.length === 0" :title="$t('catalog.noPlans')" />

    <div v-else class="catalog__grid">
      <AppCard v-for="plan in plans" :key="plan.id" padding="default" class="plan">
        <div class="plan__header">
          <h2 class="plan__name">{{ plan.name }}</h2>
          <span v-if="plan.prices.length > 0" class="plan__price">{{ fromPrice(plan) }}</span>
        </div>
        <p class="plan__code">{{ plan.code }}</p>
        <AppDivider class="plan__divider" />
        <ul v-if="featureEntries(plan).length > 0" class="plan__features">
          <li v-for="entry in featureEntries(plan)" :key="entry.code" class="plan__feature">
            <span v-if="featureIncluded(entry.value)" class="plan__feature-check" aria-hidden="true">
              <Check :size="14" />
            </span>
            <span v-else class="plan__feature-dot" aria-hidden="true"></span>
            <span class="plan__feature-label">{{ featureLabel(entry.code) }}</span>
            <span v-if="!featureIncluded(entry.value) && formatFeatureValue(entry.value) !== ''" class="plan__feature-value">
              {{ formatFeatureValue(entry.value) }}
            </span>
          </li>
        </ul>
        <p v-else class="plan__empty">
          <Tag :size="14" aria-hidden="true" />
          {{ $t('catalog.noFeatures') }}
        </p>
      </AppCard>
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
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  letter-spacing: -0.005em;
  color: var(--text-1);
}

.catalog__subtitle {
  margin-top: var(--space-4);
  font-size: 14px;
  line-height: 20px;
  color: var(--text-2);
}

.catalog__grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-16);
}

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
  font-size: 18px;
  line-height: 24px;
  font-weight: 600;
  color: var(--text-1);
}

.plan__price {
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  color: var(--accent-strong);
  white-space: nowrap;
}

.plan__code {
  margin-top: var(--space-4);
  font-size: 12px;
  line-height: 16px;
  color: var(--text-3);
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
  font-size: 14px;
  line-height: 20px;
  color: var(--text-2);
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
}

.plan__feature-dot {
  width: 20px;
  height: 20px;
  flex: 0 0 auto;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.plan__feature-label {
  flex: 1;
  min-width: 0;
}

.plan__feature-value {
  color: var(--text-1);
  font-weight: 500;
}

.plan__empty {
  display: flex;
  align-items: center;
  gap: var(--space-8);
  font-size: 14px;
  line-height: 20px;
  color: var(--text-3);
}

@media (min-width: 768px) {
  .catalog__title {
    font-size: 24px;
    line-height: 32px;
  }

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
