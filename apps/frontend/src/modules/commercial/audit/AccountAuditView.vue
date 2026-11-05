<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import AppDisclosure from '@/shared/ui/surfaces/AppDisclosure.vue';
import { commercialAudit } from '@/core/api';
import { sessionEpoch } from '@/core/auth/session';
import { RequestAbortedError } from '@/shared/api/errors';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { formatDateTime } from '@/shared/formatting/date';
import type { AuditEvent } from '@/shared/types';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';

const route = useRoute();
const { t, locale } = useI18n();
const accountId = computed(() => String(route.params.accountId ?? ''));
const items = ref<AuditEvent[]>([]);
const nextCursor = ref<string | null>(null);
const loading = ref(true);
const loadingMore = ref(false);
const errorMessage = ref<string | null>(null);
const moreError = ref<string | null>(null);
const actionFilter = ref('');
const outcomeFilter = ref<'' | 'success' | 'failure' | 'denied'>('');
let appliedFilters = { action: '', outcome: outcomeFilter.value };
let generation = 0;
let active = true;
onBeforeUnmount(() => {
  active = false;
  generation += 1;
});

const outcomeOptions = computed(() => [
  { value: '', label: t('audit.allOutcomes') },
  { value: 'success', label: t('audit.success') },
  { value: 'failure', label: t('audit.failure') },
  { value: 'denied', label: t('audit.denied') },
]);

async function load(reset: boolean): Promise<void> {
  if (!reset && (loading.value || loadingMore.value || nextCursor.value === null)) return;
  const request = ++generation;
  const id = accountId.value;
  const epoch = sessionEpoch.value;
  const current = () => active && request === generation && epoch === sessionEpoch.value;
  if (reset) {
    appliedFilters = { action: actionFilter.value.trim(), outcome: outcomeFilter.value };
    loading.value = true;
    loadingMore.value = false;
    items.value = [];
    nextCursor.value = null;
  } else {
    loadingMore.value = true;
  }
  if (id === '') {
    loading.value = false;
    return;
  }
  errorMessage.value = null;
  moreError.value = null;
  const query = { limit: 25, cursor: reset ? null : nextCursor.value, ...appliedFilters };
  try {
    await Promise.resolve();
    if (!current()) return;
    const page = await commercialAudit.events(id, query);
    if (!current()) return;
    // Cursor retries may overlap at the boundary; never render an event twice.
    const seen = new Set(reset ? [] : items.value.map((item) => item.id));
    items.value = [
      ...(reset ? [] : items.value),
      ...page.items.filter((item) => {
        if (seen.has(item.id)) return false;
        seen.add(item.id);
        return true;
      }),
    ];
    nextCursor.value = page.next_cursor;
  } catch (error) {
    if (error instanceof RequestAbortedError || !current()) return;
    if (reset) errorMessage.value = resolveErrorMessage(error);
    else moreError.value = resolveErrorMessage(error);
  } finally {
    if (current()) {
      loading.value = false;
      loadingMore.value = false;
    }
  }
}

function applyFilters(): void {
  void load(true);
}

function resetFilters(): void {
  actionFilter.value = '';
  outcomeFilter.value = '';
  void load(true);
}

function outcomeVariant(outcome: AuditEvent['outcome']): 'success' | 'danger' | 'warning' {
  return outcome === 'success' ? 'success' : outcome === 'denied' ? 'warning' : 'danger';
}

function metadata(event: AuditEvent): string {
  return Object.keys(event.metadata).length === 0 ? '{}' : JSON.stringify(event.metadata, null, 2);
}

watch(
  [accountId, sessionEpoch],
  () => {
    void load(true);
  },
  { immediate: true, flush: 'sync' },
);
</script>

<template>
  <section class="audit">
    <header class="audit__header">
      <h2 class="audit__title">{{ $t('audit.title') }}</h2>
      <p class="audit__subtitle">{{ $t('audit.subtitle') }}</p>
    </header>

    <AppCard>
      <div class="audit__filters">
        <AppInput
          id="audit-action"
          v-model="actionFilter"
          :label="$t('audit.actionFilter')"
          :placeholder="$t('audit.actionPlaceholder')"
        />
        <AppDropdown
          id="audit-outcome"
          v-model="outcomeFilter"
          :label="$t('audit.outcome')"
          :options="outcomeOptions"
        />
        <div class="audit__filter-actions">
          <AppButton @click="applyFilters">{{ $t('audit.apply') }}</AppButton>
          <AppButton
            variant="secondary"
            @click="resetFilters"
          >
            {{ $t('audit.reset') }}
          </AppButton>
        </div>
      </div>
    </AppCard>

    <AppSkeleton
      v-if="loading"
      variant="card"
      :lines="6"
    />
    <AppErrorState
      v-else-if="errorMessage"
      :message="errorMessage"
    >
      <AppButton
        variant="secondary"
        @click="load(true)"
      >
        {{ $t('common.retry') }}
      </AppButton>
    </AppErrorState>

    <AppCard v-else-if="items.length > 0">
      <ol class="audit__list">
        <li
          v-for="event in items"
          :key="event.id"
          class="audit__event"
        >
          <div class="audit__event-head">
            <div class="audit__event-title">
              <AppBadge
                :variant="outcomeVariant(event.outcome)"
                dot
              >
                {{ $t(`audit.${event.outcome}`) }}
              </AppBadge>
              <strong>{{ event.action }}</strong>
            </div>
            <time
              class="audit__time"
              :datetime="event.occurred_at"
            >
              {{ formatDateTime(event.occurred_at, locale) }}
            </time>
          </div>
          <p class="audit__meta">
            {{ event.actor_type }}
            <span v-if="event.actor_id">· {{ event.actor_id }}</span>
            · {{ event.resource_type }} · {{ event.resource_id }}
          </p>
          <AppDisclosure
            v-if="Object.keys(event.metadata).length > 0"
            class="audit__details"
            :label="$t('audit.metadata')"
          >
            <pre>{{ metadata(event) }}</pre>
          </AppDisclosure>
        </li>
      </ol>
      <div
        v-if="nextCursor"
        class="audit__more"
      >
        <p
          v-if="moreError"
          role="alert"
        >
          {{ moreError }}
        </p>
        <AppButton
          variant="secondary"
          :loading="loadingMore"
          @click="load(false)"
        >
          {{ $t(moreError ? 'common.retry' : 'audit.loadMore') }}
        </AppButton>
      </div>
    </AppCard>

    <AppEmptyState
      v-else
      :title="$t('audit.emptyTitle')"
      :description="$t('audit.emptyText')"
    />
  </section>
</template>

<style scoped>
.audit {
  display: grid;
  gap: var(--space-16);
}
.audit__header {
  display: grid;
  gap: var(--space-4);
}
.audit__title {
  font-size: var(--type-h2-size);
  letter-spacing: var(--type-h2-tracking);
  line-height: var(--type-h2-line);
  font-weight: var(--type-h2-weight);
  color: var(--text-1);
}
.audit__subtitle {
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  color: var(--text-2);
}
.audit__filters {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr));
  gap: var(--space-12);
  align-items: end;
}
.audit__filter-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-8);
  padding-bottom: var(--space-2);
}
.audit__list {
  display: grid;
  gap: var(--space-8);
}
.audit__event {
  display: grid;
  gap: var(--space-8);
  padding: var(--space-12);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
}
.audit__event-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-8);
}
.audit__event-title {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-8);
  min-width: 0;
}
.audit__event-title strong {
  color: var(--text-1);
  overflow-wrap: anywhere;
}
.audit__time,
.audit__meta {
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  color: var(--text-2);
}
.audit__meta {
  overflow-wrap: anywhere;
}
.audit__details summary {
  cursor: pointer;
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  color: var(--text-2);
}
.audit__details pre {
  margin-top: var(--space-8);
  padding: var(--space-8);
  overflow: auto;
  border-radius: var(--radius-control-s);
  background: var(--surface-2);
  color: var(--text-1);
  font-family: var(--font-mono);
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
}
.audit__more {
  display: flex;
  justify-content: center;
  padding-top: var(--space-12);
}
</style>
