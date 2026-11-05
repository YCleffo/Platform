<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Pencil, Trash2 } from '@/shared/ui/icons';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppIconButton from '@/shared/ui/actions/AppIconButton.vue';
import { formatDateTime } from '@/shared/formatting/date';
import { roleVariant } from './accountPresentation';
import type { CommercialMembership } from '@/shared/types';

defineProps<{
  membership: CommercialMembership;
  canManage: boolean;
  canRemove: boolean;
}>();

const emit = defineEmits<{
  'change-role': [];
  remove: [];
}>();

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
    <div class="member__side">
      <AppBadge :variant="roleVariant(membership.role)">
        {{ t(`cabinet.role_${membership.role}`) }}
      </AppBadge>
      <div
        v-if="canManage || canRemove"
        class="member__actions"
      >
        <AppIconButton
          v-if="canManage"
          :label="$t('account.changeRole')"
          @click="emit('change-role')"
        >
          <Pencil
            :size="18"
            aria-hidden="true"
          />
        </AppIconButton>
        <AppIconButton
          v-if="canRemove"
          :label="$t('account.removeMember')"
          @click="emit('remove')"
        >
          <Trash2
            :size="18"
            aria-hidden="true"
          />
        </AppIconButton>
      </div>
    </div>
  </li>
</template>

<style scoped>
.member {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-12);
  padding: var(--space-16);
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: var(--radius-card-s);
  transition: var(--theme-transition);
}

.member__identity {
  flex: 1;
  min-width: 0;
  display: grid;
  gap: var(--space-2);
}

.member__email {
  font-size: var(--type-body-medium-emphasis-size);
  letter-spacing: var(--type-body-medium-emphasis-tracking);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  color: var(--text-1);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  transition: var(--theme-transition);
}

.member__since {
  font-size: var(--type-body-small-size);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  color: var(--text-3);
  transition: var(--theme-transition);
}

.member__side {
  display: flex;
  align-items: center;
  gap: var(--space-8);
}

.member__actions {
  display: flex;
  align-items: center;
  gap: var(--space-4);
}
</style>
