<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { formatDateTime } from '@/shared/formatting/date';
import type { CommercialAccountDetail } from '@/shared/types';

defineProps<{ account: CommercialAccountDetail }>();

const { t, locale } = useI18n();
</script>

<template>
  <dl class="details">
    <div class="details__row">
      <dt class="details__label">{{ $t('account.code') }}</dt>
      <dd class="details__value details__value--mono">{{ account.code }}</dd>
    </div>
    <div v-if="account.legal_name" class="details__row">
      <dt class="details__label">{{ $t('account.legalName') }}</dt>
      <dd class="details__value">{{ account.legal_name }}</dd>
    </div>
    <div class="details__row">
      <dt class="details__label">{{ $t('account.yourRole') }}</dt>
      <dd class="details__value">{{ t(`cabinet.role_${account.role}`) }}</dd>
    </div>
    <div class="details__row">
      <dt class="details__label">{{ $t('account.createdAt') }}</dt>
      <dd class="details__value">{{ formatDateTime(account.created_at, locale) }}</dd>
    </div>
    <div class="details__row">
      <dt class="details__label">{{ $t('account.updatedAt') }}</dt>
      <dd class="details__value">{{ formatDateTime(account.updated_at, locale) }}</dd>
    </div>
  </dl>
</template>

<style scoped>
.details {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  margin: 0;
}

.details__row {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--space-2);
  padding: var(--space-12) 0;
  border-bottom: 1px solid var(--border);
}

.details__row:last-child {
  border-bottom: none;
}

.details__label {
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--text-3);
}

.details__value {
  margin: 0;
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  color: var(--text-1);
  overflow-wrap: anywhere;
}

.details__value--mono {
  font-family: ui-monospace, 'Cascadia Code', 'Roboto Mono', Menlo, monospace;
}

@media (min-width: 768px) {
  .details__row {
    grid-template-columns: 180px minmax(0, 1fr);
    gap: var(--space-16);
    align-items: baseline;
  }
}
</style>
