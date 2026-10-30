<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { commercialCatalog } from '@/shared/api/commercial';
import { toErrorMessage } from '@/shared/errors/humanize';
import LoadingState from '@/shared/ui/LoadingState.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import Button from '@/shared/ui/Button.vue';
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
  return `${t('catalog.from')} ${formatPrice(first.amount_minor, first.currency)} / ${periodLabel(first.billing_period)}`;
}

onMounted(load);
</script>

<template>
  <section>
    <header class="catalog-header">
      <h1>{{ $t('catalog.title') }}</h1>
      <p class="catalog-header__subtitle">{{ $t('catalog.subtitle') }}</p>
    </header>

    <LoadingState v-if="loading" :label="$t('common.loading')" />
    <ErrorState v-else-if="error" :message="error">
      <Button @click="load">{{ $t('common.retry') }}</Button>
    </ErrorState>
    <EmptyState v-else-if="plans.length === 0" :title="$t('catalog.noPlans')" />
    <div v-else class="plans">
      <article v-for="plan in plans" :key="plan.id" class="plan">
        <div class="plan__header">
          <h2 class="plan__name">{{ plan.name }}</h2>
          <span v-if="plan.prices.length > 0" class="plan__price">
            {{ fromPrice(plan) }}
          </span>
        </div>
        <p class="plan__code">{{ plan.code }}</p>
        <ul v-if="plan.features && Object.keys(plan.features).length > 0" class="plan__features">
          <li v-for="(value, key) in plan.features" :key="key">{{ key }}: {{ String(value) }}</li>
        </ul>
      </article>
    </div>
  </section>
</template>

<style scoped>
.catalog-header {
  margin-bottom: var(--space-6);
}

.catalog-header h1 {
  font-size: var(--text-2xl);
}

.catalog-header__subtitle {
  color: var(--color-text-muted);
  margin-top: var(--space-1);
}

.plans {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(16rem, 1fr));
  gap: var(--space-4);
}

.plan {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: var(--space-5);
  box-shadow: var(--shadow-sm);
}

.plan__header {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: var(--space-3);
}

.plan__name {
  font-size: var(--text-lg);
}

.plan__price {
  font-weight: 700;
  color: var(--color-primary);
  white-space: nowrap;
}

.plan__code {
  color: var(--color-text-muted);
  font-size: var(--text-sm);
  margin: var(--space-1) 0 var(--space-3);
}

.plan__features {
  margin: 0;
  padding-left: var(--space-4);
  font-size: var(--text-sm);
}
</style>
