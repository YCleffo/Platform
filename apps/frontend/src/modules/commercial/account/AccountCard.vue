<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import { accountInitials, onboardingVariant, roleVariant, statusVariant } from './accountPresentation';
import type { CommercialAccountSummary } from '@/shared/types';

defineProps<{ account: CommercialAccountSummary }>();

const { t } = useI18n();
</script>

<template>
  <AppCard padding="default" class="account">
    <div class="account__head">
      <span class="account__avatar" aria-hidden="true">{{ accountInitials(account) }}</span>
      <div class="account__identity">
        <p class="account__name">{{ account.display_name }}</p>
        <p class="account__code">{{ account.code }}</p>
      </div>
      <AppBadge :variant="roleVariant(account.role)">{{ t(`cabinet.role_${account.role}`) }}</AppBadge>
    </div>
    <div class="account__badges">
      <AppBadge :variant="statusVariant(account.status)" dot>
        {{ t(`cabinet.status_${account.status}`) }}
      </AppBadge>
      <AppBadge :variant="onboardingVariant(account.onboarding_state)">
        {{ t(`cabinet.onboarding_${account.onboarding_state}`) }}
      </AppBadge>
    </div>
  </AppCard>
</template>

<style scoped>
.account {
  display: grid;
  gap: var(--space-16);
}

.account__head {
  display: flex;
  align-items: center;
  gap: var(--space-12);
}

.account__avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: var(--radius-badge-module-tile);
  background: var(--accent-soft);
  color: var(--accent-strong);
  font-size: var(--type-h2-size);
  font-weight: var(--type-h2-weight);
  flex: 0 0 auto;
}

.account__identity {
  flex: 1;
  min-width: 0;
}

.account__name {
  font-size: var(--type-body-large-emphasis-size);
  line-height: var(--type-body-large-emphasis-line);
  font-weight: var(--type-body-large-emphasis-weight);
  color: var(--text-1);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.account__code {
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  color: var(--text-3);
  font-family: ui-monospace, 'Cascadia Code', 'Roboto Mono', Menlo, monospace;
}

.account__badges {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-8);
}
</style>
