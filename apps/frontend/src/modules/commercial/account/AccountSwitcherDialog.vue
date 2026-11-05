<script setup lang="ts">
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAccountPicker } from './useAccountPicker';
import AccountPagination from './AccountPagination.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';

defineProps<{
  currentId: string;
  currentName: string;
}>();

const open = defineModel<boolean>({ default: false });

const emit = defineEmits<{
  select: [accountId: string];
}>();

const { t } = useI18n();
const {
  accounts,
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
} = useAccountPicker();

watch(open, (value) => {
  if (value) {
    void loadPage(0);
  }
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

function choose(accountId: string): void {
  emit('select', accountId);
  open.value = false;
}
</script>

<template>
  <AppDialog
    v-model="open"
    :title="$t('account.switchAccount')"
  >
    <div class="picker">
      <div class="picker__current">
        <span class="picker__current-label">{{ $t('account.currentAccount') }}</span>
        <span class="picker__current-name">{{ currentName }}</span>
      </div>

      <AppSkeleton
        v-if="loading"
        variant="list"
        :lines="3"
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
        v-else-if="accounts.length === 0"
        :title="$t('cabinet.noAccounts')"
      />
      <template v-else>
        <ul
          class="picker__list"
          role="list"
        >
          <li
            v-for="account in accounts"
            :key="account.id"
          >
            <button
              type="button"
              class="picker__item"
              :class="{ 'picker__item--current': account.id === currentId }"
              :aria-current="account.id === currentId ? 'true' : undefined"
              @click="choose(account.id)"
            >
              <span class="picker__name">{{ account.display_name }}</span>
              <span class="picker__code">{{ account.code }}</span>
              <span class="picker__role">{{ $t(`cabinet.role_${account.role}`) }}</span>
            </button>
          </li>
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
    </div>
  </AppDialog>
</template>

<style scoped>
.picker {
  display: grid;
  gap: var(--space-12);
}

.picker__current {
  display: flex;
  justify-content: space-between;
  gap: var(--space-8);
  padding: var(--space-12);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  background: var(--surface-2);
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  transition: var(--theme-transition);
}

.picker__current-label {
  color: var(--text-3);
  transition: var(--theme-transition);
}

.picker__current-name {
  color: var(--text-1);
  font-weight: var(--type-body-medium-emphasis-weight);
  overflow-wrap: anywhere;
  transition: var(--theme-transition);
}

.picker__list {
  display: grid;
  gap: var(--space-8);
  max-height: 50dvh;
  overflow-y: auto;
}

.picker__item {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  grid-template-rows: auto auto;
  gap: 2px var(--space-8);
  width: 100%;
  padding: var(--space-12);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  background: var(--surface);
  text-align: left;
  cursor: pointer;
  transition: var(--theme-transition);
}

.picker__item:hover {
  border-color: var(--border-strong);
  background: var(--surface-hover);
  transition: var(--theme-transition);
}

.picker__item--current {
  border-color: var(--accent);
  background: var(--accent-soft);
  transition: var(--theme-transition);
}

.picker__name {
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

.picker__code {
  grid-row: 2;
  font-family: var(--font-mono);
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  color: var(--text-3);
  transition: var(--theme-transition);
}

.picker__role {
  grid-column: 2;
  grid-row: 1 / span 2;
  align-self: center;
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  color: var(--text-2);
  transition: var(--theme-transition);
}
</style>
