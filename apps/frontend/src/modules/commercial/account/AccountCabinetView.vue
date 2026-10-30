<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAccountStore } from '@/core/account/accountStore';
import { errorCodeKey, toErrorMessage } from '@/shared/errors/humanize';
import { useToast } from '@/shared/composables/useToast';
import Button from '@/shared/ui/Button.vue';
import Dialog from '@/shared/ui/Dialog.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';
import FormField from '@/shared/ui/FormField.vue';
import LoadingState from '@/shared/ui/LoadingState.vue';

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
</script>

<template>
  <section>
    <header class="cabinet-header">
      <div>
        <h1>{{ $t('cabinet.title') }}</h1>
        <p class="cabinet-header__subtitle">{{ $t('cabinet.subtitle') }}</p>
      </div>
      <Button @click="openDialog">{{ $t('cabinet.create') }}</Button>
    </header>

    <LoadingState v-if="store.loading" :label="$t('common.loading')" />
    <ErrorState v-else-if="store.error" :message="store.error">
      <Button @click="store.loadAccounts()">{{ $t('common.retry') }}</Button>
    </ErrorState>
    <EmptyState v-else-if="store.accounts.length === 0" :title="$t('cabinet.noAccounts')" />
    <div v-else class="account-table-wrap">
      <table class="account-table">
        <thead>
          <tr>
            <th>{{ $t('cabinet.displayName') }}</th>
            <th>{{ $t('cabinet.code') }}</th>
            <th>{{ $t('cabinet.role') }}</th>
            <th>{{ $t('cabinet.status') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="account in store.accounts" :key="account.id">
            <td>{{ account.display_name }}</td>
            <td class="account-table__code">{{ account.code }}</td>
            <td>{{ roleLabel(account.role) }}</td>
            <td>{{ statusLabel(account.status) }} · {{ onboardingLabel(account.onboarding_state) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <Dialog v-model="dialogOpen" :title="$t('cabinet.createTitle')">
      <form class="create-form" @submit.prevent="onCreate">
        <FormField id="account-code" v-model="code" :label="$t('cabinet.code')" required />
        <FormField id="account-name" v-model="displayName" :label="$t('cabinet.displayName')" required />
        <FormField id="account-legal" v-model="legalName" :label="$t('cabinet.legalName')" />
        <p v-if="createError" class="create-form__error">{{ createError }}</p>
        <div class="create-form__actions">
          <Button type="button" variant="secondary" @click="dialogOpen = false">
            {{ $t('common.cancel') }}
          </Button>
          <Button type="submit" :loading="creating">{{ $t('cabinet.create') }}</Button>
        </div>
      </form>
    </Dialog>
  </section>
</template>

<style scoped>
.cabinet-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-4);
  margin-bottom: var(--space-6);
}

.cabinet-header h1 {
  font-size: var(--text-2xl);
}

.cabinet-header__subtitle {
  color: var(--color-text-muted);
  margin-top: var(--space-1);
}

.account-table-wrap {
  overflow-x: auto;
}

.account-table {
  width: 100%;
  border-collapse: collapse;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  overflow: hidden;
}

.account-table th,
.account-table td {
  text-align: left;
  padding: var(--space-3) var(--space-4);
  border-bottom: 1px solid var(--color-border);
}

.account-table th {
  font-size: var(--text-sm);
  color: var(--color-text-muted);
  background: var(--color-surface-alt);
}

.account-table tbody tr:last-child td {
  border-bottom: none;
}

.account-table__code {
  font-family: var(--font-mono);
  font-size: var(--text-sm);
}

.create-form {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.create-form__error {
  color: var(--color-danger);
  font-size: var(--text-sm);
}

.create-form__actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-3);
}
</style>
