<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAccountStore } from '@/core/account/accountStore';
import { ApiError } from '@/shared/api/errors';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import AccountStatus from './AccountStatus.vue';
import AccountSwitcher from './AccountSwitcher.vue';

const store = useAccountStore();
const route = useRoute();
const router = useRouter();
const { t } = useI18n();

const accountId = computed(() => String(route.params.accountId ?? ''));
const detail = computed(() => store.accountDetail);
const errorText = computed(() => {
  const error = store.detailError;
  if (error instanceof ApiError) {
    if (error.status === 404) {
      return t('account.notFoundOrNoAccess');
    }
    if (error.status === 403) {
      return t('errors.accessDenied');
    }
  }
  return store.detailErrorMessage ?? t('common.unexpectedError');
});
const canViewMembers = computed(() => detail.value?.permissions.view_members === true);

watch(
  accountId,
  (id) => {
    if (id !== '') {
      void store.loadAccount(id);
    }
  },
  { immediate: true },
);

onMounted(() => {
  if (store.accounts.length === 0 && !store.loading) {
    void store.loadPage(0);
  }
});

onBeforeUnmount(() => {
  store.invalidateAccount();
});

function switchAccount(id: string): void {
  void router.push({ name: 'account-overview', params: { accountId: id } });
}
</script>

<template>
  <section class="account-layout">
    <nav class="account-layout__breadcrumbs" :aria-label="$t('account.sections')">
      <RouterLink :to="{ name: 'cabinet' }">{{ $t('account.backToCabinet') }}</RouterLink>
      <span v-if="detail" class="account-layout__crumb-current">{{ detail.display_name }}</span>
    </nav>

    <AppSkeleton
      v-if="store.detailLoading || (detail === null && store.detailError === null)"
      variant="card"
      :lines="3"
    />

    <AppErrorState v-else-if="store.detailError" :message="errorText">
      <AppButton variant="secondary" @click="store.loadAccount(accountId)">
        {{ $t('common.retry') }}
      </AppButton>
    </AppErrorState>

    <template v-else-if="detail">
      <header class="account-layout__header">
        <div class="account-layout__identity">
          <h1 class="account-layout__name">{{ detail.display_name }}</h1>
          <p class="account-layout__code">{{ detail.code }}</p>
          <div class="account-layout__badges">
            <AccountStatus :status="detail.status" :onboarding-state="detail.onboarding_state" />
            <AppBadge variant="primary">{{ $t(`cabinet.role_${detail.role}`) }}</AppBadge>
          </div>
        </div>
        <AccountSwitcher
          v-if="store.total > 1"
          :current-id="detail.id"
          :current-name="detail.display_name"
          @select="switchAccount"
        />
      </header>

      <nav class="account-layout__tabs" :aria-label="$t('account.sections')">
        <RouterLink :to="{ name: 'account-overview', params: { accountId: detail.id } }">
          {{ $t('account.overview') }}
        </RouterLink>
        <RouterLink
          v-if="canViewMembers"
          :to="{ name: 'account-members', params: { accountId: detail.id } }"
        >
          {{ $t('account.members') }}
        </RouterLink>
      </nav>

      <RouterView />
    </template>
  </section>
</template>

<style scoped>
.account-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--space-16);
  max-width: 1120px;
  margin: 0 auto;
}

.account-layout__breadcrumbs {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-8);
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  color: var(--text-3);
}

.account-layout__crumb-current {
  color: var(--text-2);
}

.account-layout__header {
  display: flex;
  flex-direction: column;
  gap: var(--space-12);
  padding: var(--space-20);
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: var(--radius-card-m);
}

.account-layout__identity {
  display: grid;
  gap: var(--space-4);
  min-width: 0;
}

.account-layout__name {
  font-size: var(--type-h1-size);
  line-height: var(--type-h1-line);
  font-weight: var(--type-h1-weight);
  letter-spacing: var(--type-h1-tracking);
  color: var(--text-1);
  overflow-wrap: anywhere;
}

.account-layout__code {
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  color: var(--text-3);
  font-family: ui-monospace, 'Cascadia Code', 'Roboto Mono', Menlo, monospace;
}

.account-layout__badges {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-8);
  margin-top: var(--space-4);
}

.account-layout__tabs {
  display: flex;
  gap: var(--space-4);
  border-bottom: 1px solid var(--border);
  overflow-x: auto;
}

.account-layout__tabs a {
  padding: var(--space-12) var(--space-12);
  border-bottom: 2px solid transparent;
  font-size: var(--type-body-medium-emphasis-size);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  color: var(--text-2);
  white-space: nowrap;
}

.account-layout__tabs a:hover {
  color: var(--text-1);
  text-decoration: none;
}

.account-layout__tabs a.router-link-active {
  color: var(--accent-strong);
  border-bottom-color: var(--accent);
}

@media (min-width: 768px) {
  .account-layout {
    gap: var(--space-20);
  }

  .account-layout__header {
    flex-direction: row;
    align-items: flex-start;
    justify-content: space-between;
    padding: var(--space-24);
  }
}
</style>
