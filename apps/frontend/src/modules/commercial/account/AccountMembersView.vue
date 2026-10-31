<script setup lang="ts">
import { computed, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAccountStore } from '@/core/account/accountStore';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { useToast } from '@/shared/composables/useToast';
import type { AccountRole, CommercialMembership } from '@/shared/types';
import { assignableRoles, canManageMembership } from './accountPresentation';
import { useAccountMemberships } from './useAccountMemberships';
import { useMembershipActions } from './useMembershipActions';
import AccountPagination from './AccountPagination.vue';
import MembershipList from './MembershipList.vue';
import MemberRoleDialog from './MemberRoleDialog.vue';
import type { RoleOption } from './accountPresentation';
import MemberRemoveDialog from './MemberRemoveDialog.vue';
import ReauthenticateDialog from '@/modules/commercial/security/ReauthenticateDialog.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';

const route = useRoute();
const router = useRouter();
const store = useAccountStore();
const toast = useToast();
const { t } = useI18n();

const accountId = computed(() => String(route.params.accountId ?? ''));
const permissions = computed(() => store.accountDetail?.permissions ?? null);
const canView = computed(() => permissions.value?.view_members === true);

const {
  memberships,
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
} = useAccountMemberships(accountId, canView);

function canManageMember(member: CommercialMembership): boolean {
  return permissions.value !== null && canManageMembership(member.role, permissions.value);
}

const manageableIds = computed(() => memberships.value.filter(canManageMember).map((member) => member.id));
const removableIds = computed(() => memberships.value.filter(canManageMember).map((member) => member.id));

const roleOpen = ref(false);
const roleTarget = ref<CommercialMembership | null>(null);
const removeOpen = ref(false);
const removeTarget = ref<CommercialMembership | null>(null);

async function onChanged(): Promise<void> {
  await loadPage(offset.value);
  if (memberships.value.length === 0 && total.value > 0 && offset.value > 0) {
    await loadPage(Math.max(0, offset.value - limit.value));
  }
  await store.loadAccount(accountId.value);
  if (store.detailError !== null) {
    store.clearAccountContext();
    await router.push({ name: 'cabinet' });
    return;
  }
  if (store.accountDetail !== null && !store.accountDetail.permissions.view_members) {
    await router.push({ name: 'account-overview', params: { accountId: accountId.value } });
  }
}

const {
  changeRole,
  remove,
  resume,
  cancel,
  reauthOpen,
  busy,
  error: actionError,
  needsMfaEnrollment,
} = useMembershipActions(accountId, onChanged);

const actionErrorText = computed(() =>
  actionError.value === null ? null : resolveErrorMessage(actionError.value),
);

const roleOptions = computed<RoleOption[]>(() => {
  const target = roleTarget.value;
  if (target === null || permissions.value === null) {
    return [];
  }
  return assignableRoles(target.role, permissions.value).map((role) => ({
    value: role,
    label: t(`cabinet.role_${role}`),
  }));
});

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

function openRole(member: CommercialMembership): void {
  roleTarget.value = member;
  roleOpen.value = true;
}

function openRemove(member: CommercialMembership): void {
  removeTarget.value = member;
  removeOpen.value = true;
}

async function confirmRole(role: AccountRole): Promise<void> {
  const target = roleTarget.value;
  if (target === null) {
    return;
  }
  const completed = await changeRole(target.id, role);
  if (completed) {
    roleOpen.value = false;
    roleTarget.value = null;
    toast.show(t('account.roleUpdated'), 'success');
  }
}

async function confirmRemove(): Promise<void> {
  const target = removeTarget.value;
  if (target === null) {
    return;
  }
  const completed = await remove(target.id);
  if (completed) {
    removeOpen.value = false;
    removeTarget.value = null;
    toast.show(t('account.memberRemoved'), 'success');
  }
}
</script>

<template>
  <section class="members">
    <header class="members__header">
      <h2 class="members__title">{{ $t('account.membersTitle') }}</h2>
      <p class="members__subtitle">{{ $t('account.membersSubtitle') }}</p>
    </header>

    <div v-if="needsMfaEnrollment" class="members__notice" role="alert">
      <p>{{ $t('security.mfaRequired') }}</p>
      <RouterLink :to="{ name: 'security' }">{{ $t('security.goToSecurity') }}</RouterLink>
    </div>

    <AppErrorState v-if="!canView" :message="$t('account.accessDenied')" />
    <AppSkeleton v-else-if="loading" variant="list" :lines="4" />
    <AppErrorState v-else-if="errorMessage" :message="errorMessage">
      <AppButton variant="secondary" @click="loadPage(offset)">{{ $t('common.retry') }}</AppButton>
    </AppErrorState>
    <AppEmptyState v-else-if="memberships.length === 0" :title="$t('account.noMembers')" />
    <template v-else>
      <MembershipList
        :memberships="memberships"
        :manageable-ids="manageableIds"
        :removable-ids="removableIds"
        @change-role="openRole"
        @remove="openRemove"
      />
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

    <MemberRoleDialog
      v-model="roleOpen"
      :member-email="roleTarget?.email ?? ''"
      :roles="roleOptions"
      :busy="busy"
      :error="roleOpen ? actionErrorText : null"
      @confirm="confirmRole"
    />
    <MemberRemoveDialog
      v-model="removeOpen"
      :email="removeTarget?.email ?? ''"
      :role-label="removeTarget ? $t(`cabinet.role_${removeTarget.role}`) : ''"
      :busy="busy"
      :error="removeOpen ? actionErrorText : null"
      @confirm="confirmRemove"
    />
    <ReauthenticateDialog v-model="reauthOpen" @authenticated="resume" @cancelled="cancel" />
  </section>
</template>

<style scoped>
.members {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--space-16);
}

.members__header {
  display: grid;
  gap: var(--space-4);
}

.members__title {
  font-size: var(--type-h2-size);
  line-height: var(--type-h2-line);
  font-weight: var(--type-h2-weight);
  letter-spacing: var(--type-h2-tracking);
  color: var(--text-1);
  transition: var(--theme-transition);
}

.members__subtitle {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  color: var(--text-2);
  transition: var(--theme-transition);
}

.members__notice {
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
  line-height: var(--type-body-medium-line);
  transition: var(--theme-transition);
}

.members__notice a {
  font-weight: 500;
}
</style>
