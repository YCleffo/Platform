<script setup lang="ts">
import { ref, watch } from 'vue';
import { useAccountStore } from '@/core/account/accountStore';
import { createIdempotencyKey } from '@/shared/api/idempotency';
import { useAsyncAction } from '@/shared/composables/useAsyncAction';
import { useForm } from '@/shared/composables/useForm';
import { label, machineCode } from '@/shared/validation/rules';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';

const open = defineModel<boolean>({ default: false });

const emit = defineEmits<{
  created: [];
}>();

const store = useAccountStore();
const idempotencyKey = ref('');

const form = useForm({
  initial: { code: '', displayName: '', legalName: '' },
  fields: {
    code: { id: 'account-code', rules: [machineCode(120)] },
    displayName: { id: 'account-name', rules: [label(200)] },
    legalName: { id: 'account-legal', rules: [label(240, { optional: true })] },
  },
});

const { submitting, message, run, clearError } = useAsyncAction(async () => {
  await store.create(
    {
      code: form.values.code.trim(),
      display_name: form.values.displayName.trim(),
      legal_name: form.values.legalName.trim() === '' ? null : form.values.legalName.trim(),
    },
    idempotencyKey.value,
  );
});

watch(open, (value) => {
  if (value) {
    idempotencyKey.value = createIdempotencyKey();
    form.reset();
    clearError();
  }
});

async function onSubmit(): Promise<void> {
  if (!form.validate()) {
    return;
  }
  const ok = await run();
  if (ok) {
    open.value = false;
    emit('created');
  }
}
</script>

<template>
  <AppDialog v-model="open" :title="$t('cabinet.createTitle')" :persistent="submitting">
    <form class="account-create" novalidate @submit.prevent="onSubmit">
      <AppInput
        id="account-code"
        v-model="form.values.code"
        :label="$t('cabinet.code')"
        :error="form.errors.code ? $t(form.errors.code) : undefined"
        placeholder="acme"
        autocomplete="off"
        required
      />
      <AppInput
        id="account-name"
        v-model="form.values.displayName"
        :label="$t('cabinet.displayName')"
        :error="form.errors.displayName ? $t(form.errors.displayName) : undefined"
        autocomplete="off"
        required
      />
      <AppInput
        id="account-legal"
        v-model="form.values.legalName"
        :label="$t('cabinet.legalName')"
        :error="form.errors.legalName ? $t(form.errors.legalName) : undefined"
        autocomplete="off"
      />
      <p v-if="message" class="account-create__error" role="alert">{{ message }}</p>
      <div class="account-create__actions">
        <AppButton type="button" variant="secondary" :disabled="submitting" @click="open = false">
          {{ $t('common.cancel') }}
        </AppButton>
        <AppButton type="submit" :loading="submitting">{{ $t('cabinet.create') }}</AppButton>
      </div>
    </form>
  </AppDialog>
</template>

<style scoped>
.account-create {
  display: flex;
  flex-direction: column;
  gap: var(--space-16);
}

.account-create__error {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  color: var(--danger-text);
  transition: var(--theme-transition);
}

.account-create__actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-12);
}
</style>
