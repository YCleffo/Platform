<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { CircleCheck } from '@lucide/vue';
import { ApiError } from '@/shared/api/errors';
import { useAuthStore } from '@/core/auth/authStore';
import { useAccountStore } from '@/core/account/accountStore';
import { useToast } from '@/shared/composables/useToast';
import { formatDateTime } from '@/shared/formatting/date';
import { useInvitationAcceptance } from './useInvitationAcceptance';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';

const { t, locale } = useI18n();
const router = useRouter();
const auth = useAuthStore();
const accounts = useAccountStore();
const toast = useToast();

const {
  preview,
  loading,
  error,
  errorMessage,
  actionError,
  actionErrorMessage,
  acting,
  declined,
  hasToken,
  load,
  accept,
  decline,
} = useInvitationAcceptance();

const declineOpen = ref(false);

const ACTION_KEYS: Record<string, string> = {
  invitation_expired: 'invitations.expired',
  invitation_not_available: 'invitations.alreadyAccepted',
  invitation_email_mismatch: 'invitations.emailMismatch',
  email_verification_required: 'invitations.verificationRequired',
  invitation_account_unavailable: 'invitations.accountUnavailable',
};

const previewErrorText = computed(() => {
  const failure = error.value;
  if (failure instanceof ApiError && failure.status === 404) {
    return t('invitations.invalid');
  }
  return errorMessage.value ?? t('invitations.loadFailed');
});

const actionErrorText = computed(() => {
  const failure = actionError.value;
  if (failure instanceof ApiError) {
    const key = ACTION_KEYS[failure.code];
    if (key !== undefined) {
      return t(key);
    }
  }
  return actionErrorMessage.value ?? t('common.unexpectedError');
});

const statusLabel = computed(() => (preview.value === null ? '' : t(`invitations.status_${preview.value.status}`)));

onMounted(() => {
  void load();
});

function goToLogin(): void {
  void router.push({ name: 'login', query: { redirect: '/invitation' } });
}

async function onAccept(): Promise<void> {
  if (!auth.isAuthenticated) {
    goToLogin();
    return;
  }
  const result = await accept();
  if (result === null) {
    return;
  }
  toast.show(t('invitations.accepted'), 'success');
  await accounts.loadPage(0);
  await router.push({ name: 'account-overview', params: { accountId: result.account_id } });
}

async function onDecline(): Promise<void> {
  if (!auth.isAuthenticated) {
    goToLogin();
    return;
  }
  const completed = await decline();
  if (completed) {
    declineOpen.value = false;
    toast.show(t('invitations.declined'), 'success');
  }
}

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
  <section class="invitation-accept">
    <AppSkeleton v-if="loading" variant="text" :lines="3" />

    <AppEmptyState v-else-if="!hasToken" :title="$t('invitations.missingToken')" :description="$t('invitations.missingTokenHint')">
      <RouterLink :to="{ name: 'catalog' }">{{ $t('nav.catalog') }}</RouterLink>
    </AppEmptyState>

    <AppErrorState v-else-if="error" :message="previewErrorText">
      <RouterLink :to="{ name: 'catalog' }">{{ $t('nav.catalog') }}</RouterLink>
    </AppErrorState>

    <div v-else-if="declined" class="invitation-accept__status">
      <span class="invitation-accept__icon" aria-hidden="true">
        <CircleCheck :size="28" />
      </span>
      <h1 class="invitation-accept__title">{{ $t('invitations.declined') }}</h1>
      <RouterLink :to="{ name: 'catalog' }">{{ $t('nav.catalog') }}</RouterLink>
    </div>

    <div v-else-if="preview" class="invitation-accept__content">
      <h1 class="invitation-accept__title">{{ $t('invitations.acceptTitle') }}</h1>
      <p class="invitation-accept__intro">
        {{ $t('invitations.acceptIntro', { organization: preview.organization_name, role: t(`cabinet.role_${preview.role}`) }) }}
      </p>

      <div class="invitation-accept__details">
        <AppBadge :variant="statusVariant(preview.status)">{{ statusLabel }}</AppBadge>
        <span class="invitation-accept__detail">{{ $t('invitations.maskedEmail', { email: preview.email }) }}</span>
        <span class="invitation-accept__detail">
          {{ $t('invitations.expiresAt') }} {{ formatDateTime(preview.expires_at, locale) }}
        </span>
      </div>

      <template v-if="preview.status === 'pending'">
        <template v-if="auth.isAuthenticated">
          <p v-if="actionError" class="invitation-accept__error" role="alert">{{ actionErrorText }}</p>
          <div class="invitation-accept__actions">
            <AppButton :loading="acting" @click="onAccept">{{ $t('invitations.accept') }}</AppButton>
            <AppButton variant="secondary" :disabled="acting" @click="declineOpen = true">
              {{ $t('invitations.decline') }}
            </AppButton>
          </div>
        </template>
        <template v-else>
          <p class="invitation-accept__intro">{{ $t('invitations.signInToAccept') }}</p>
          <div class="invitation-accept__actions">
            <AppButton @click="goToLogin">{{ $t('nav.login') }}</AppButton>
            <RouterLink class="invitation-accept__link" :to="{ name: 'register', query: { redirect: '/invitation' } }">
              {{ $t('invitations.registerToAccept') }}
            </RouterLink>
          </div>
        </template>
      </template>

      <div v-else class="invitation-accept__status">
        <p class="invitation-accept__intro">{{ $t(`invitations.state_${preview.status}`) }}</p>
        <RouterLink :to="{ name: 'catalog' }">{{ $t('nav.catalog') }}</RouterLink>
      </div>
    </div>

    <AppDialog
      v-model="declineOpen"
      :title="$t('invitations.declineTitle')"
      :description="$t('invitations.declineDescription')"
      :persistent="acting"
      size="s"
    >
      <div class="invitation-accept__decline">
        <p v-if="actionError" class="invitation-accept__error" role="alert">{{ actionErrorText }}</p>
        <div class="invitation-accept__actions">
          <AppButton type="button" variant="secondary" :disabled="acting" @click="declineOpen = false">
            {{ $t('common.cancel') }}
          </AppButton>
          <AppButton type="button" variant="danger" :loading="acting" @click="onDecline">
            {{ $t('invitations.decline') }}
          </AppButton>
        </div>
      </div>
    </AppDialog>
  </section>
</template>

<style scoped>
.invitation-accept {
  display: grid;
  gap: var(--space-16);
}

.invitation-accept__content {
  display: grid;
  gap: var(--space-12);
}

.invitation-accept__title {
  font-size: var(--type-h1-size);
  line-height: var(--type-h1-line);
  font-weight: var(--type-h1-weight);
  letter-spacing: var(--type-h1-tracking);
  color: var(--text-1);
}

.invitation-accept__intro {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  color: var(--text-2);
}

.invitation-accept__details {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-8);
  padding: var(--space-12);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  background: var(--surface-2);
}

.invitation-accept__detail {
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  color: var(--text-2);
}

.invitation-accept__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-12);
}

.invitation-accept__link {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
}

.invitation-accept__error {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  color: var(--danger-text);
}

.invitation-accept__status {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-8);
  text-align: center;
}

.invitation-accept__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 56px;
  height: 56px;
  border-radius: var(--radius-badge-module-tile);
  background: var(--success-soft);
  color: var(--success);
}

.invitation-accept__decline {
  display: flex;
  flex-direction: column;
  gap: var(--space-16);
}
</style>
