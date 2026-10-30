<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Plus } from '@lucide/vue';
import { useAccountStore } from '@/core/account/accountStore';
import { useToast } from '@/shared/composables/useToast';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AccountCreateDialog from './AccountCreateDialog.vue';
import AccountList from './AccountList.vue';
import AccountPagination from './AccountPagination.vue';

const store = useAccountStore();
const toast = useToast();
const { t } = useI18n();

const dialogOpen = ref(false);

const rangeLabel = computed(() => {
  if (store.total === 0) {
    return '';
  }
  const from = store.offset + 1;
  const to = Math.min(store.offset + store.limit, store.total);
  return t('cabinet.pageRange', { from, to, total: store.total });
});

onMounted(() => {
  void store.loadPage(0);
});

function openCreate(): void {
  dialogOpen.value = true;
}

async function onCreated(): Promise<void> {
  toast.show(t('cabinet.createSuccess'), 'success');
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
      <AppButton @click="openCreate">
        <Plus :size="18" aria-hidden="true" />
        {{ $t('cabinet.create') }}
      </AppButton>
    </header>

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

    <AccountCreateDialog v-model="dialogOpen" @created="onCreated" />
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
}

.cabinet__subtitle {
  margin-top: var(--space-4);
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  color: var(--text-2);
}
</style>
