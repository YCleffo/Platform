<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import { formatDateTime } from '@/shared/formatting/date';
import { roleVariant } from './accountPresentation';
import type { CommercialMembership } from '@/shared/types';

defineProps<{ membership: CommercialMembership }>();

const { t, locale } = useI18n();
</script>

<template>
  <li class="member">
    <div class="member__identity">
      <span class="member__email">{{ membership.email }}</span>
      <span class="member__since">
        {{ $t('account.memberSince') }} {{ formatDateTime(membership.created_at, locale) }}
      </span>
    </div>
    <AppBadge :variant="roleVariant(membership.role)">{{ t(`cabinet.role_${membership.role}`) }}</AppBadge>
  </li>
</template>

<style scoped>
.member {
  display: flex;
  align-items: center;
  gap: var(--space-12);
  padding: var(--space-16);
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: var(--radius-card-s);
}

.member__identity {
  flex: 1;
  min-width: 0;
  display: grid;
  gap: var(--space-2);
}

.member__email {
  font-size: var(--type-body-medium-emphasis-size);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  color: var(--text-1);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.member__since {
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  color: var(--text-3);
}
</style>
