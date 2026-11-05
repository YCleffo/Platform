<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { commercialAnalytics } from '@/core/api';
import { sessionEpoch } from '@/core/auth/session';
import { RequestAbortedError } from '@/shared/api/errors';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { formatDateTime } from '@/shared/formatting/date';
import { formatMoney } from '@/shared/formatting/money';
import type { AccountAnalytics, AccountAnalyticsAuditDay, UsageCounter } from '@/shared/types';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';

const route = useRoute();
const { t, locale } = useI18n();
const accountId = computed(() => String(route.params.accountId ?? ''));
const days = ref(30);
const data = ref<AccountAnalytics | null>(null);
const loading = ref(true);
const errorMessage = ref<string | null>(null);
let generation = 0;
let active = true;
onBeforeUnmount(() => {
  active = false;
  generation += 1;
});

const dayOptions = computed(() => [
  { value: '7', label: t('analytics.days7') },
  { value: '30', label: t('analytics.days30') },
  { value: '90', label: t('analytics.days90') },
]);
const maxDaily = computed(() =>
  Math.max(1, ...(data.value?.audit.by_day.map((item) => item.count) ?? [0])),
);
const maxAction = computed(() =>
  Math.max(1, ...(data.value?.audit.by_action.map((item) => item.count) ?? [0])),
);
const outcomeEntries = computed(() => Object.entries(data.value?.audit.by_outcome ?? {}));
const usageEntries = computed(() => Object.entries(data.value?.usage.counters ?? {}));
const usageMax = computed(() =>
  Math.max(1, ...usageEntries.value.map(([, counter]) => counter.limit)),
);
const currency = computed(() => data.value?.billing.currency ?? 'RUB');

function barHeight(count: number): string {
  return `${Math.max(2, Math.round((count / maxDaily.value) * 100))}%`;
}

function actionWidth(count: number): string {
  return `${Math.max(2, Math.round((count / maxAction.value) * 100))}%`;
}

function usageWidth(counter: UsageCounter): string {
  return `${Math.min(100, Math.max(0, Math.round((counter.used / usageMax.value) * 100)))}%`;
}

function shortDate(item: AccountAnalyticsAuditDay | undefined): string {
  if (item === undefined) return '';
  return new Intl.DateTimeFormat(locale.value, {
    day: '2-digit',
    month: 'short',
    timeZone: 'UTC',
  }).format(new Date(`${item.date}T00:00:00Z`));
}

function money(value: number): string {
  return data.value?.billing.currency === null
    ? '—'
    : formatMoney(value, currency.value, locale.value);
}

function onDaysChange(value: string | number | string[]): void {
  days.value = Number(Array.isArray(value) ? value[0] : value);
}

async function load(): Promise<void> {
  const request = ++generation;
  const id = accountId.value;
  const period = days.value;
  const epoch = sessionEpoch.value;
  const current = () => active && request === generation && epoch === sessionEpoch.value;
  data.value = null;
  if (id === '') {
    loading.value = false;
    return;
  }
  loading.value = true;
  errorMessage.value = null;
  try {
    // Session invalidation is synchronous; the new token owner finishes before HTTP starts.
    await Promise.resolve();
    if (!current()) return;
    const result = await commercialAnalytics.get(id, period);
    if (!current()) return;
    data.value = result;
  } catch (error) {
    if (error instanceof RequestAbortedError || !current()) return;
    errorMessage.value = resolveErrorMessage(error);
  } finally {
    if (current()) loading.value = false;
  }
}

watch(
  [accountId, days, sessionEpoch],
  () => {
    void load();
  },
  { immediate: true, flush: 'sync' },
);
</script>

<template>
  <section class="analytics">
    <header class="analytics__header">
      <div>
        <h2 class="analytics__title">{{ $t('analytics.title') }}</h2>
        <p class="analytics__subtitle">{{ $t('analytics.subtitle') }}</p>
      </div>
      <AppDropdown
        id="analytics-days"
        :model-value="String(days)"
        :label="$t('analytics.period')"
        :options="dayOptions"
        @update:model-value="onDaysChange"
      />
    </header>

    <AppSkeleton
      v-if="loading"
      variant="card"
      :lines="8"
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

    <template v-else-if="data">
      <div class="analytics__kpis">
        <AppCard>
          <p class="analytics__kpi-label">{{ $t('analytics.members') }}</p>
          <strong class="analytics__kpi-value">{{ data.members.total }}</strong>
          <p class="analytics__muted">
            {{ $t('analytics.pendingInvitations', { count: data.members.pending_invitations }) }}
          </p>
        </AppCard>
        <AppCard>
          <p class="analytics__kpi-label">{{ $t('analytics.mrr') }}</p>
          <strong class="analytics__kpi-value">{{ money(data.billing.mrr_minor) }}</strong>
          <p class="analytics__muted">
            {{ data.billing.subscription_status ?? $t('analytics.noSubscription') }}
          </p>
        </AppCard>
        <AppCard>
          <p class="analytics__kpi-label">{{ $t('analytics.paid') }}</p>
          <strong class="analytics__kpi-value">{{ money(data.billing.paid_minor) }}</strong>
          <p class="analytics__muted">
            {{ $t('analytics.paymentsCount', { count: data.billing.payments_count }) }}
          </p>
        </AppCard>
        <AppCard>
          <p class="analytics__kpi-label">{{ $t('analytics.outstanding') }}</p>
          <strong class="analytics__kpi-value">{{ money(data.billing.outstanding_minor) }}</strong>
          <p class="analytics__muted">
            {{ $t('analytics.invoicesCount', { count: data.billing.invoices_count }) }}
          </p>
        </AppCard>
      </div>

      <AppCard>
        <div class="analytics__card-head">
          <h3>{{ $t('analytics.activity') }}</h3>
          <AppBadge variant="neutral">
            {{ $t('analytics.events', { count: data.audit.events }) }}
          </AppBadge>
        </div>
        <p class="analytics__muted">{{ $t('analytics.activityHint', { days: data.days }) }}</p>
        <div
          class="analytics__bars"
          role="img"
          :aria-label="
            $t('analytics.activitySummary', { count: data.audit.events, days: data.days })
          "
        >
          <span
            v-for="item in data.audit.by_day"
            :key="item.date"
            class="analytics__bar"
            :style="{ height: barHeight(item.count) }"
            :title="`${item.date}: ${item.count}`"
          />
        </div>
        <div class="analytics__axis">
          <span>{{ shortDate(data.audit.by_day[0]) }}</span>
          <span>{{ shortDate(data.audit.by_day[data.audit.by_day.length - 1]) }}</span>
        </div>
      </AppCard>

      <div class="analytics__grid">
        <AppCard>
          <h3>{{ $t('analytics.outcomes') }}</h3>
          <p
            v-if="outcomeEntries.length === 0"
            class="analytics__muted"
          >
            {{ $t('analytics.noActivity') }}
          </p>
          <div
            v-for="[outcome, count] in outcomeEntries"
            :key="outcome"
            class="analytics__row"
          >
            <span>{{ outcome }}</span>
            <span class="analytics__row-value">{{ count }}</span>
            <span class="analytics__row-track">
              <span
                class="analytics__row-fill"
                :style="{
                  width: `${Math.round((count / Math.max(1, data.audit.events)) * 100)}%`,
                }"
              />
            </span>
          </div>
        </AppCard>
        <AppCard>
          <h3>{{ $t('analytics.topActions') }}</h3>
          <p
            v-if="data.audit.by_action.length === 0"
            class="analytics__muted"
          >
            {{ $t('analytics.noActivity') }}
          </p>
          <div
            v-for="item in data.audit.by_action"
            :key="item.action"
            class="analytics__row"
          >
            <span class="analytics__row-label">{{ item.action }}</span>
            <span class="analytics__row-value">{{ item.count }}</span>
            <span class="analytics__row-track">
              <span
                class="analytics__row-fill"
                :style="{ width: actionWidth(item.count) }"
              />
            </span>
          </div>
        </AppCard>
      </div>

      <AppCard>
        <div class="analytics__card-head">
          <h3>{{ $t('analytics.billing') }}</h3>
          <AppBadge :variant="data.billing.billing_status === 'past_due' ? 'danger' : 'success'">
            {{ data.billing.billing_status ?? '—' }}
          </AppBadge>
        </div>
        <p class="analytics__muted">
          {{
            data.billing.current_period_start && data.billing.current_period_end
              ? `${formatDateTime(data.billing.current_period_start, locale)} — ${formatDateTime(data.billing.current_period_end, locale)}`
              : $t('analytics.noSubscription')
          }}
        </p>
      </AppCard>

      <AppCard v-if="usageEntries.length > 0">
        <h3>{{ $t('analytics.usage') }}</h3>
        <div
          v-for="[code, counter] in usageEntries"
          :key="code"
          class="analytics__usage"
        >
          <div class="analytics__row">
            <span class="analytics__row-label">{{ code }}</span>
            <span class="analytics__row-value">{{ counter.used }} / {{ counter.limit }}</span>
            <span class="analytics__row-track">
              <span
                class="analytics__row-fill"
                :class="{ 'analytics__row-fill--warn': counter.exhausted }"
                :style="{ width: usageWidth(counter) }"
              />
            </span>
          </div>
        </div>
      </AppCard>
    </template>
  </section>
</template>

<style scoped>
.analytics {
  display: grid;
  gap: var(--space-16);
}
.analytics__header {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-12);
}
.analytics__title {
  font-size: var(--type-h2-size);
  letter-spacing: var(--type-h2-tracking);
  line-height: var(--type-h2-line);
  font-weight: var(--type-h2-weight);
  color: var(--text-1);
}
.analytics__subtitle,
.analytics__muted {
  margin-top: var(--space-4);
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  color: var(--text-2);
}
.analytics__kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 180px), 1fr));
  gap: var(--space-12);
}
.analytics__kpi-label {
  font-size: var(--type-body-small-emphasis-size);
  line-height: var(--type-body-small-emphasis-line);
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--text-3);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.analytics__kpi-value {
  display: block;
  margin-top: var(--space-4);
  font-size: var(--type-h2-size);
  letter-spacing: var(--type-h2-tracking);
  line-height: var(--type-h2-line);
  font-weight: var(--type-h2-weight);
  color: var(--text-1);
}
.analytics__card-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-8);
}
.analytics h3 {
  font-size: var(--type-h3-size);
  letter-spacing: var(--type-h3-tracking);
  line-height: var(--type-h3-line);
  font-weight: var(--type-h3-weight);
  color: var(--text-1);
}
.analytics__bars {
  display: flex;
  align-items: flex-end;
  gap: 2px;
  height: 140px;
  margin-top: var(--space-16);
  padding: var(--space-4);
  border-bottom: 1px solid var(--border);
}
.analytics__bar {
  flex: 1;
  min-width: 2px;
  border-radius: 2px 2px 0 0;
  background: var(--accent);
  transition: var(--theme-transition);
}
.analytics__bar:hover {
  background: var(--accent-hover);
}
.analytics__axis {
  display: flex;
  justify-content: space-between;
  margin-top: var(--space-4);
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  color: var(--text-3);
}
.analytics__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr));
  gap: var(--space-16);
}
.analytics__row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: var(--space-8);
  margin-top: var(--space-12);
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  color: var(--text-1);
}
.analytics__row-label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.analytics__row-value {
  color: var(--text-2);
  font-variant-numeric: tabular-nums;
}
.analytics__row-track {
  grid-column: 1 / -1;
  height: 8px;
  overflow: hidden;
  border-radius: 999px;
  background: var(--surface-hover);
}
.analytics__row-fill {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: var(--accent);
}
.analytics__row-fill--warn {
  background: var(--warning);
}
.analytics__usage {
  margin-top: var(--space-8);
}
</style>
