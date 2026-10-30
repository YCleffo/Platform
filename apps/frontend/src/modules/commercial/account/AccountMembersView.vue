<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAccountStore } from '@/core/account/accountStore';
import { useAccountMemberships } from './useAccountMemberships';
import AccountPagination from './AccountPagination.vue';
import MembershipList from './MembershipList.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';

const route = useRoute();
const store = useAccountStore();
const { t } = useI18n();

const accountId = computed(() => String(route.params.accountId ?? ''));
const canView = computed(() => store.accountDetail?.permissions.view_members === true);

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
</script>

<template>
  <section class="members">
    <header class="members__header">
      <h2 class="members__title">{{ $t('account.membersTitle') }}</h2>
      <p class="members__subtitle">{{ $t('account.membersSubtitle') }}</p>
    </header>

    <AppErrorState v-if="!canView" :message="$t('account.accessDenied')" />
    <AppSkeleton v-else-if="loading" variant="list" :lines="4" />
    <AppErrorState v-else-if="errorMessage" :message="errorMessage">
      <AppButton variant="secondary" @click="loadPage(offset)">{{ $t('common.retry') }}</AppButton>
    </AppErrorState>
    <AppEmptyState v-else-if="memberships.length === 0" :title="$t('account.noMembers')" />
    <template v-else>
      <MembershipList :memberships="memberships" />
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
}

.members__subtitle {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  color: var(--text-2);
}
</style>
