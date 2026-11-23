<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { Plus } from '@/shared/ui/icons';
import { useAccountStore } from '@/core/account/accountStore';
import { useToast } from '@/shared/composables/useToast';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AccountCreateDialog from './AccountCreateDialog.vue';
import AccountList from './AccountList.vue';
import AccountPagination from './AccountPagination.vue';

const store = useAccountStore();
const toast = useToast();
const route = useRoute();
const router = useRouter();
const { t } = useI18n();

const dialogOpen = ref(false);
const pendingPlanPrice = computed(() =>
  typeof route.query.planPrice === 'string' ? route.query.planPrice : '',
);

const rangeLabel = computed(() => {
  if (store.total === 0) {
    return '';
  }
  const from = store.offset + 1;
  const to = Math.min(store.offset + store.limit, store.total);
  return t('cabinet.pageRange', { from, to, total: store.total });
});

onMounted(async () => {
  await store.loadPage(0);
  if (pendingPlanPrice.value === '' || store.errorMessage !== null) {
    return;
  }
  const accountId = store.accounts[0]?.id;
  if (accountId !== undefined) {
    await router.replace({
      name: 'account-billing',
      params: { accountId },
      query: { planPrice: pendingPlanPrice.value },
    });
    return;
  }
  if (store.total === 0) {
    dialogOpen.value = true;
  }
});

function openCreate(): void {
  dialogOpen.value = true;
}

async function onCreated(accountId: string): Promise<void> {
  toast.show(t('cabinet.createSuccess'), 'success');
  if (pendingPlanPrice.value !== '') {
    await router.push({
      name: 'account-billing',
      params: { accountId },
      query: { planPrice: pendingPlanPrice.value },
    });
    return;
  }
  await store.loadPage(0);
}
</script>

<template>
  <section class="cabinet">
    <header class="cabinet__header">
      <div>
        <h1 class="cabinet__title">{{ $t('cabinet.title') }}</h1>
        <p class="cabinet__subtitle">{{ $t('cabinet.subtitle') }}</p>
      </div>
      <AppButton
        data-testid="cabinet-create-account"
        @click="openCreate"
      >
        <Plus
          :size="18"
          aria-hidden="true"
        />
        {{ $t('cabinet.create') }}
      </AppButton>
    </header>

    <p
      v-if="pendingPlanPrice !== ''"
      class="cabinet__intent"
      role="status"
    >
      {{ $t('cabinet.planIntent') }}
    </p>

    <AccountList
      :accounts="store.accounts"
      :loading="store.loading"
      :error-message="store.errorMessage"
      @create="openCreate"
      @retry="store.loadPage(store.offset)"
    />

    <AccountPagination
      v-if="store.total > store.limit"
      :page="store.page"
      :page-count="store.pageCount"
      :has-prev="store.hasPrev"
      :has-next="store.hasNext"
      :range-label="rangeLabel"
      @prev="store.prevPage()"
      @next="store.nextPage()"
    />

    <AccountCreateDialog
      v-model="dialogOpen"
      @created="onCreated"
    />
  </section>
</template>

<style scoped>
.cabinet {
  max-width: 1120px;
  margin: 0 auto;
}

.cabinet__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-12);
  margin-bottom: var(--space-24);
}

.cabinet__title {
  font-size: var(--type-h1-size);
  line-height: var(--type-h1-line);
  font-weight: var(--type-h1-weight);
  letter-spacing: var(--type-h1-tracking);
  color: var(--text-1);
  transition: var(--theme-transition);
}

.cabinet__subtitle {
  margin-top: var(--space-4);
  font-size: var(--type-body-medium-size);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  color: var(--text-2);
  transition: var(--theme-transition);
}

.cabinet__intent {
  margin-bottom: var(--space-16);
  padding: var(--space-12) var(--space-16);
  border-radius: var(--radius-control-m);
  background: var(--accent-soft);
  color: var(--accent-text);
  font-size: var(--type-body-medium-size);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  transition: var(--theme-transition);
}
</style>
