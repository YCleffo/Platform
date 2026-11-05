<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { RotateCw, Trash2 } from '@/shared/ui/icons';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppIconButton from '@/shared/ui/actions/AppIconButton.vue';
import { formatDateTime } from '@/shared/formatting/date';
import type { AccountInvitation } from '@/shared/types';

defineProps<{
  invitation: AccountInvitation;
  canManage: boolean;
}>();

const emit = defineEmits<{
  resend: [];
  revoke: [];
}>();

const { t, locale } = useI18n();

function statusVariant(status: string): 'neutral' | 'primary' | 'success' | 'warning' | 'danger' {
  switch (status) {
    case 'pending':
      return 'primary';
    case 'accepted':
      return 'success';
    case 'revoked':
      return 'danger';
    case 'expired':
      return 'warning';
    default:
      return 'neutral';
  }
}
</script>

<template>
  <li class="invitation">
    <div class="invitation__identity">
      <span class="invitation__email">{{ invitation.email }}</span>
      <span class="invitation__meta">
        {{ $t('invitations.invitedBy') }} {{ invitation.inviter_email ?? '—' }} ·
        {{ $t('invitations.expiresAt') }} {{ formatDateTime(invitation.expires_at, locale) }}
      </span>
    </div>
    <div class="invitation__side">
      <AppBadge :variant="statusVariant(invitation.status)">
        {{ t(`invitations.status_${invitation.status}`) }}
      </AppBadge>
      <AppBadge variant="neutral">{{ t(`cabinet.role_${invitation.role}`) }}</AppBadge>
      <div
        v-if="canManage && invitation.status === 'pending'"
        class="invitation__actions"
      >
        <AppIconButton
          :label="$t('invitations.resend')"
          @click="emit('resend')"
        >
          <RotateCw
            :size="18"
            aria-hidden="true"
          />
        </AppIconButton>
        <AppIconButton
          :label="$t('invitations.revoke')"
          @click="emit('revoke')"
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
.invitation {
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

.invitation__identity {
  flex: 1;
  min-width: 0;
  display: grid;
  gap: var(--space-2);
}

.invitation__email {
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

.invitation__meta {
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  color: var(--text-3);
  overflow-wrap: anywhere;
  transition: var(--theme-transition);
}

.invitation__side {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-8);
}

.invitation__actions {
  display: flex;
  align-items: center;
  gap: var(--space-4);
}
</style>
