<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Building2, Plus } from '@lucide/vue';
import { useAccountStore } from '@/core/account/accountStore';
import { errorCodeKey, toErrorMessage } from '@/shared/errors/humanize';
import { useToast } from '@/shared/composables/useToast';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import type { CommercialAccountSummary } from '@/shared/types';

const store = useAccountStore();
const toast = useToast();
const { t } = useI18n();

const dialogOpen = ref(false);
const code = ref('');
const displayName = ref('');
const legalName = ref('');
const idempotencyKey = ref('');
const creating = ref(false);
const createError = ref<string | null>(null);

onMounted(() => {
  void store.loadAccounts();
});

function openDialog(): void {
  code.value = '';
  displayName.value = '';
  legalName.value = '';
  idempotencyKey.value = crypto.randomUUID();
  createError.value = null;
  dialogOpen.value = true;
}

async function onCreate(): Promise<void> {
  if (creating.value) {
    return;
  }
  creating.value = true;
  createError.value = null;
  try {
    await store.createAccount(
      {
        code: code.value.trim(),
        display_name: displayName.value.trim(),
        legal_name: legalName.value.trim() === '' ? null : legalName.value.trim(),
      },
      idempotencyKey.value,
    );
    dialogOpen.value = false;
    toast.show(t('cabinet.createSuccess'), 'success');
  } catch (err) {
    const key = errorCodeKey(err);
    createError.value = key !== null ? t(key) : toErrorMessage(err);
  } finally {
    creating.value = false;
  }
}

function roleLabel(role: string): string {
  return t(`cabinet.role_${role}`);
}

function statusLabel(status: string): string {
  return t(`cabinet.status_${status}`);
}

function onboardingLabel(state: string): string {
  return t(`cabinet.onboarding_${state}`);
}

function statusVariant(status: string): 'neutral' | 'success' | 'warning' | 'danger' {
  if (status === 'active') {
    return 'success';
  }
  if (status === 'suspended') {
    return 'warning';
  }
  if (status === 'closed') {
    return 'danger';
  }
  return 'neutral';
}

function onboardingVariant(state: string): 'neutral' | 'success' | 'warning' | 'primary' {
  if (state === 'ready_for_subscription') {
    return 'success';
  }
  if (state === 'catalog_selection_pending') {
    return 'warning';
  }
  return 'neutral';
}

function roleVariant(role: string): 'primary' | 'neutral' {
  return role === 'owner' ? 'primary' : 'neutral';
}

function accountInitials(account: CommercialAccountSummary): string {
  const name = account.display_name.trim();
  return name === '' ? '?' : name.charAt(0).toUpperCase();
}
</script>

<template>
  <section class="cabinet">
    <header class="cabinet__header">
      <div>
        <h1 class="cabinet__title">{{ $t('cabinet.title') }}</h1>
        <p class="cabinet__subtitle">{{ $t('cabinet.subtitle') }}</p>
      </div>
      <AppButton @click="openDialog">
        <Plus :size="18" aria-hidden="true" />
        {{ $t('cabinet.create') }}
      </AppButton>
    </header>

    <div v-if="store.loading" class="cabinet__grid">
      <AppCard v-for="i in 2" :key="i" padding="default">
        <AppSkeleton variant="card" :lines="2" />
      </AppCard>
    </div>

    <AppErrorState v-else-if="store.error" :message="store.error">
      <AppButton variant="secondary" @click="store.loadAccounts()">
        {{ $t('common.retry') }}
      </AppButton>
    </AppErrorState>

    <AppEmptyState v-else-if="store.accounts.length === 0" :title="$t('cabinet.noAccounts')" :icon="Building2">
      <AppButton @click="openDialog">{{ $t('cabinet.create') }}</AppButton>
    </AppEmptyState>

    <div v-else class="cabinet__grid">
      <AppCard v-for="account in store.accounts" :key="account.id" padding="default" class="account">
        <div class="account__head">
          <span class="account__avatar" aria-hidden="true">{{ accountInitials(account) }}</span>
          <div class="account__identity">
            <p class="account__name">{{ account.display_name }}</p>
            <p class="account__code">{{ account.code }}</p>
          </div>
          <AppBadge :variant="roleVariant(account.role)">{{ roleLabel(account.role) }}</AppBadge>
        </div>
        <div class="account__badges">
          <AppBadge :variant="statusVariant(account.status)" dot>
            {{ statusLabel(account.status) }}
          </AppBadge>
          <AppBadge :variant="onboardingVariant(account.onboarding_state)">
            {{ onboardingLabel(account.onboarding_state) }}
          </AppBadge>
        </div>
      </AppCard>
    </div>

    <AppDialog v-model="dialogOpen" :title="$t('cabinet.createTitle')" :persistent="creating">
      <form class="create-form" novalidate @submit.prevent="onCreate">
        <AppInput
          id="account-code"
          v-model="code"
          :label="$t('cabinet.code')"
          placeholder="acme"
          autocomplete="off"
          required
        />
        <AppInput
          id="account-name"
          v-model="displayName"
          :label="$t('cabinet.displayName')"
          autocomplete="off"
          required
        />
        <AppInput
          id="account-legal"
          v-model="legalName"
          :label="$t('cabinet.legalName')"
          autocomplete="off"
        />
        <p v-if="createError" class="create-form__error" role="alert">{{ createError }}</p>
        <div class="create-form__actions">
          <AppButton type="button" variant="secondary" :disabled="creating" @click="dialogOpen = false">
            {{ $t('common.cancel') }}
          </AppButton>
          <AppButton type="submit" :loading="creating">{{ $t('cabinet.create') }}</AppButton>
        </div>
      </form>
    </AppDialog>
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
  font-size: 22px;
  line-height: 28px;
  font-weight: 600;
  letter-spacing: -0.005em;
  color: var(--text-1);
}

.cabinet__subtitle {
  margin-top: var(--space-4);
  font-size: 14px;
  line-height: 20px;
  color: var(--text-2);
}

.cabinet__grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: var(--space-16);
}

.account {
  display: grid;
  gap: var(--space-16);
}

.account__head {
  display: flex;
  align-items: center;
  gap: var(--space-12);
}

.account__avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: var(--radius-badge-module-tile);
  background: var(--accent-soft);
  color: var(--accent-strong);
  font-size: 18px;
  font-weight: 600;
  flex: 0 0 auto;
}

.account__identity {
  flex: 1;
  min-width: 0;
}

.account__name {
  font-size: 16px;
  line-height: 24px;
  font-weight: 600;
  color: var(--text-1);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.account__code {
  font-size: 12px;
  line-height: 16px;
  color: var(--text-3);
  font-family: ui-monospace, 'Cascadia Code', 'Roboto Mono', Menlo, monospace;
}

.account__badges {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-8);
}

.create-form {
  display: flex;
  flex-direction: column;
  gap: var(--space-16);
}

.create-form__error {
  font-size: 14px;
  line-height: 20px;
  color: var(--danger-text);
}

.create-form__actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-12);
}

@media (min-width: 768px) {
  .cabinet__title {
    font-size: 24px;
    line-height: 32px;
  }

  .cabinet__grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-20);
  }

  .create-form__actions {
    justify-content: flex-start;
  }
}
</style>
