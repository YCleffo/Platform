<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { Plus } from '@/shared/ui/icons';
import { useAccountStore } from '@/core/account/accountStore';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { useToast } from '@/shared/composables/useToast';
import { useAccountInvitations } from './useAccountInvitations';
import { useInvitationActions } from './useInvitationActions';
import InvitationCard from './InvitationCard.vue';
import InvitationCreateDialog from './InvitationCreateDialog.vue';
import ReauthenticateDialog from '@/modules/commercial/security/ReauthenticateDialog.vue';
import AccountPagination from '@/modules/commercial/account/AccountPagination.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import type { AccountInvitation, CreateInvitationPayload, InvitationRole } from '@/shared/types';

const route = useRoute();
const store = useAccountStore();
const toast = useToast();
const { t } = useI18n();

const accountId = computed(() => String(route.params.accountId ?? ''));
const canManage = computed(() => store.accountDetail?.permissions.manage_members === true);

const {
  invitations,
  total,
  limit,
  offset,
  loading,
  errorMessage,
  page,
  pageCount,
  hasPrev,
  hasNext,
  loadPage,
  nextPage,
  prevPage,
} = useAccountInvitations(accountId, canManage);

const createOpen = ref(false);
const revokeOpen = ref(false);
const revokeTarget = ref<AccountInvitation | null>(null);

async function onChanged(): Promise<void> {
  await loadPage(offset.value);
}

const {
  create,
  resend,
  revoke,
  resume,
  cancel,
  reauthOpen,
  busy,
  error: actionError,
  needsMfaEnrollment,
} = useInvitationActions(accountId, onChanged);

const actionErrorText = computed(() =>
  actionError.value === null ? null : resolveErrorMessage(actionError.value),
);

const rangeLabel = computed(() => {
  if (total.value === 0) {
    return '';
  }
  return t('cabinet.pageRange', {
    from: offset.value + 1,
    to: Math.min(offset.value + limit.value, total.value),
    total: total.value,
  });
});

async function onCreate(payload: {
  email: string;
  role: InvitationRole;
  idempotencyKey: string;
}): Promise<void> {
  const body: CreateInvitationPayload = { email: payload.email, role: payload.role };
  const completed = await create(body, payload.idempotencyKey);
  if (completed) {
    createOpen.value = false;
    toast.show(t('invitations.queued'), 'success');
  }
}

async function onResend(invitation: AccountInvitation): Promise<void> {
  const completed = await resend(invitation.id);
  if (completed) {
    toast.show(t('invitations.resent'), 'success');
  } else if (actionErrorText.value !== null) {
    toast.show(actionErrorText.value, 'error');
  }
}

function openRevoke(invitation: AccountInvitation): void {
  revokeTarget.value = invitation;
  revokeOpen.value = true;
}

async function confirmRevoke(): Promise<void> {
  const target = revokeTarget.value;
  if (target === null) {
    return;
  }
  const completed = await revoke(target.id);
  if (completed) {
    revokeOpen.value = false;
    revokeTarget.value = null;
    toast.show(t('invitations.revoked'), 'success');
  }
}
</script>

<template>
  <section class="invitations">
    <header class="invitations__header">
      <div>
        <h2 class="invitations__title">{{ $t('invitations.title') }}</h2>
        <p class="invitations__subtitle">{{ $t('invitations.subtitle') }}</p>
      </div>
      <AppButton
        v-if="canManage"
        @click="createOpen = true"
      >
        <Plus
          :size="18"
          aria-hidden="true"
        />
        {{ $t('invitations.invite') }}
      </AppButton>
    </header>

    <div
      v-if="needsMfaEnrollment"
      class="invitations__notice"
      role="alert"
    >
      <p>{{ $t('security.mfaRequired') }}</p>
      <RouterLink :to="{ name: 'security' }">{{ $t('security.goToSecurity') }}</RouterLink>
    </div>

    <AppErrorState
      v-if="!canManage"
      :message="$t('invitations.accessDenied')"
    />
    <AppSkeleton
      v-else-if="loading"
      variant="list"
      :lines="4"
    />
    <AppErrorState
      v-else-if="errorMessage"
      :message="errorMessage"
    >
      <AppButton
        variant="secondary"
        @click="loadPage(offset)"
      >
        {{ $t('common.retry') }}
      </AppButton>
    </AppErrorState>
    <AppEmptyState
      v-else-if="invitations.length === 0"
      :title="$t('invitations.empty')"
    />
    <template v-else>
      <ul
        class="invitations__list"
        role="list"
      >
        <InvitationCard
          v-for="invitation in invitations"
          :key="invitation.id"
          :invitation="invitation"
          :can-manage="canManage"
          @resend="onResend(invitation)"
          @revoke="openRevoke(invitation)"
        />
      </ul>
      <AccountPagination
        v-if="total > limit"
        :page="page"
        :page-count="pageCount"
        :has-prev="hasPrev"
        :has-next="hasNext"
        :range-label="rangeLabel"
        @prev="prevPage()"
        @next="nextPage()"
      />
    </template>

    <InvitationCreateDialog
      v-model="createOpen"
      :busy="busy"
      :error="createOpen ? actionErrorText : null"
      @confirm="onCreate"
    />
    <AppDialog
      v-model="revokeOpen"
      :title="$t('invitations.revokeTitle')"
      :description="$t('invitations.revokeDescription', { email: revokeTarget?.email ?? '' })"
      :persistent="busy"
      size="s"
    >
      <div class="revoke">
        <p
          v-if="revokeOpen && actionErrorText"
          class="revoke__error"
          role="alert"
        >
          {{ actionErrorText }}
        </p>
        <div class="revoke__actions">
          <AppButton
            type="button"
            variant="secondary"
            :disabled="busy"
            @click="revokeOpen = false"
          >
            {{ $t('common.cancel') }}
          </AppButton>
          <AppButton
            type="button"
            variant="danger"
            :loading="busy"
            @click="confirmRevoke"
          >
            {{ $t('invitations.revoke') }}
          </AppButton>
        </div>
      </div>
    </AppDialog>
    <ReauthenticateDialog
      v-model="reauthOpen"
      @authenticated="resume"
      @cancelled="cancel"
    />
  </section>
</template>

<style scoped>
.invitations {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--space-16);
}

.invitations__header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-12);
}

.invitations__title {
  font-size: var(--type-h2-size);
  line-height: var(--type-h2-line);
  font-weight: var(--type-h2-weight);
  letter-spacing: var(--type-h2-tracking);
  color: var(--text-1);
  transition: var(--theme-transition);
}

.invitations__subtitle {
  margin-top: var(--space-4);
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  color: var(--text-2);
  transition: var(--theme-transition);
}

.invitations__list {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--space-12);
}

.invitations__notice {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-8);
  padding: var(--space-12);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  background: var(--warning-soft);
  color: var(--warning-text);
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  transition: var(--theme-transition);
}

.revoke {
  display: flex;
  flex-direction: column;
  gap: var(--space-16);
}

.revoke__error {
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  color: var(--danger-text);
  transition: var(--theme-transition);
}

.revoke__actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-12);
}

@media (min-width: 768px) {
  .invitations__list {
    gap: var(--space-16);
  }
}
</style>
