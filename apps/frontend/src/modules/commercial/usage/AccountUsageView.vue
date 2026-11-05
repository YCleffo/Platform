<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { commercialUsage } from '@/core/api';
import { useAccountStore } from '@/core/account/accountStore';
import { RequestAbortedError } from '@/shared/api/errors';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { formatDateTime } from '@/shared/formatting/date';
import type { AccountUsage, EffectiveEntitlements, UsageCounter } from '@/shared/types';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';

const route = useRoute();
const store = useAccountStore();
const { t, locale } = useI18n();
const accountId = computed(() => String(route.params.accountId ?? ''));
const canManageBilling = computed(() => store.accountDetail?.permissions.manage_billing === true);

const entitlements = ref<EffectiveEntitlements | null>(null);
const usage = ref<AccountUsage | null>(null);
const loading = ref(true);
const errorMessage = ref<string | null>(null);

const counterEntries = computed(() => Object.entries(usage.value?.counters ?? {}));
const featureEntries = computed(() => Object.entries(entitlements.value?.features ?? {}));

function counterPercent(counter: UsageCounter): number {
  if (counter.limit <= 0) {
    return 0;
  }
  return Math.min(100, Math.max(0, (counter.used / counter.limit) * 100));
}

function featureValue(value: unknown): string {
  if (typeof value === 'boolean') {
    return value ? t('common.enabled') : t('common.disabled');
  }
  if (typeof value === 'string' || typeof value === 'number') {
    return String(value);
  }
  return JSON.stringify(value);
}

async function load(): Promise<void> {
  const id = accountId.value;
  if (id === '') {
    return;
  }
  loading.value = true;
  errorMessage.value = null;
  try {
    const [nextEntitlements, nextUsage] = await Promise.all([
      commercialUsage.entitlements(id),
      commercialUsage.usage(id),
    ]);
    if (id !== accountId.value) {
      return;
    }
    entitlements.value = nextEntitlements;
    usage.value = nextUsage;
  } catch (error) {
    if (error instanceof RequestAbortedError || id !== accountId.value) {
      return;
    }
    errorMessage.value = resolveErrorMessage(error);
  } finally {
    loading.value = false;
  }
}

onMounted(load);
watch(accountId, load);
</script>

<template>
  <section class="usage">
    <header class="usage__header">
      <h2 class="usage__title">{{ $t('usage.title') }}</h2>
      <p class="usage__subtitle">{{ $t('usage.subtitle') }}</p>
    </header>

    <AppSkeleton
      v-if="loading"
      variant="card"
      :lines="4"
    />
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

    <template v-else>
      <AppCard
        v-if="usage?.subscription"
        class="usage__period"
      >
        <div>
          <p class="usage__eyebrow">{{ $t('usage.currentPeriod') }}</p>
          <p class="usage__period-value">
            {{ formatDateTime(usage.subscription.current_period_start, locale) }}
            —
            {{ formatDateTime(usage.subscription.current_period_end, locale) }}
          </p>
        </div>
        <RouterLink
          v-if="canManageBilling"
          :to="{ name: 'account-billing', params: { accountId } }"
        >
          {{ $t('usage.openBilling') }}
        </RouterLink>
      </AppCard>

      <AppCard v-if="counterEntries.length > 0">
        <h3 class="usage__card-title">{{ $t('usage.counters') }}</h3>
        <div class="usage__counters">
          <div
            v-for="[code, counter] in counterEntries"
            :key="code"
            class="usage__counter"
          >
            <div class="usage__counter-head">
              <span class="usage__counter-name">{{ code }}</span>
              <span class="usage__counter-value">{{ counter.used }} / {{ counter.limit }}</span>
            </div>
            <div
              class="usage__bar"
              role="progressbar"
              :aria-valuenow="counter.used"
              :aria-valuemin="0"
              :aria-valuemax="counter.limit"
              :aria-label="code"
            >
              <span
                class="usage__bar-fill"
                :class="{ 'usage__bar-fill--warn': counter.exhausted }"
                :style="{ width: `${counterPercent(counter)}%` }"
              />
            </div>
            <p class="usage__counter-meta">
              {{ $t('usage.remaining', { count: counter.remaining }) }}
              <span v-if="counter.exhausted">· {{ $t('usage.exhausted') }}</span>
            </p>
          </div>
        </div>
      </AppCard>

      <AppCard v-if="featureEntries.length > 0">
        <h3 class="usage__card-title">{{ $t('usage.entitlements') }}</h3>
        <dl class="usage__features">
          <div
            v-for="[code, value] in featureEntries"
            :key="code"
            class="usage__feature"
          >
            <dt>{{ code }}</dt>
            <dd>{{ featureValue(value) }}</dd>
          </div>
        </dl>
      </AppCard>

      <AppEmptyState
        v-if="counterEntries.length === 0 && featureEntries.length === 0"
        :title="$t('usage.emptyTitle')"
        :description="$t('usage.emptyText')"
      />
    </template>
  </section>
</template>

<style scoped>
.usage {
  display: grid;
  gap: var(--space-16);
}

.usage__header {
  display: grid;
  gap: var(--space-4);
}

.usage__title {
  font-size: var(--type-h2-size);
  letter-spacing: var(--type-h2-tracking);
  line-height: var(--type-h2-line);
  font-weight: var(--type-h2-weight);
  color: var(--text-1);
  transition: var(--theme-transition);
}

.usage__subtitle {
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  color: var(--text-2);
  transition: var(--theme-transition);
}

.usage__period {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-12);
}

.usage__eyebrow {
  font-size: var(--type-body-small-emphasis-size);
  line-height: var(--type-body-small-emphasis-line);
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--text-3);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.usage__period-value {
  margin-top: var(--space-4);
  font-size: var(--type-body-medium-emphasis-size);
  letter-spacing: var(--type-body-medium-emphasis-tracking);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  color: var(--text-1);
  transition: var(--theme-transition);
}

.usage__card-title {
  font-size: var(--type-h3-size);
  letter-spacing: var(--type-h3-tracking);
  line-height: var(--type-h3-line);
  font-weight: var(--type-h3-weight);
  color: var(--text-1);
  transition: var(--theme-transition);
}

.usage__counters {
  display: grid;
  gap: var(--space-16);
  margin-top: var(--space-12);
}

.usage__counter-head {
  display: flex;
  justify-content: space-between;
  gap: var(--space-12);
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
}

.usage__counter-name {
  color: var(--text-1);
  font-weight: var(--type-body-medium-emphasis-weight);
}

.usage__counter-value {
  color: var(--text-2);
  font-variant-numeric: tabular-nums;
}

.usage__bar {
  height: 8px;
  margin-top: var(--space-8);
  border-radius: 999px;
  background: var(--surface-hover);
  overflow: hidden;
}

.usage__bar-fill {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: var(--accent);
  transition:
    width var(--motion-enter-duration) var(--motion-ease),
    var(--theme-transition);
}

.usage__bar-fill--warn {
  background: var(--warning);
}

.usage__counter-meta {
  margin-top: var(--space-4);
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  color: var(--text-3);
}

.usage__features {
  display: grid;
  gap: var(--space-8);
  margin-top: var(--space-12);
}

.usage__feature {
  display: flex;
  justify-content: space-between;
  gap: var(--space-12);
  padding-bottom: var(--space-8);
  border-bottom: 1px solid var(--border);
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
}

.usage__feature dt {
  color: var(--text-2);
}

.usage__feature dd {
  color: var(--text-1);
  text-align: end;
  overflow-wrap: anywhere;
}
</style>
