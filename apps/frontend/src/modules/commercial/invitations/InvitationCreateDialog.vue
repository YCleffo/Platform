<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { createIdempotencyKey } from '@/shared/api/idempotency';
import { useForm } from '@/shared/composables/useForm';
import { email as emailRule } from '@/shared/validation/rules';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import type { InvitationRole } from '@/shared/types';

const props = defineProps<{
  busy: boolean;
  error: string | null;
}>();

const open = defineModel<boolean>({ default: false });

const emit = defineEmits<{
  confirm: [payload: { email: string; role: InvitationRole; idempotencyKey: string }];
}>();

const { t } = useI18n();
const roles: InvitationRole[] = ['admin', 'billing', 'technical'];
const roleOptions = computed(() => roles.map(value => ({ value, label: t(`cabinet.role_${value}`) })));
const role = ref<InvitationRole>('admin');
const idempotencyKey = ref('');

const form = useForm({
  initial: { email: '' },
  fields: {
    email: { id: 'invitation-email', rules: [emailRule] },
  },
});

watch(open, (value) => {
  if (value) {
    form.reset();
    role.value = 'admin';
    idempotencyKey.value = createIdempotencyKey();
  }
}, { immediate: true });

function submit(): void {
  if (props.busy || !form.validate()) {
    return;
  }
  emit('confirm', {
    email: form.values.email.trim(),
    role: role.value,
    idempotencyKey: idempotencyKey.value,
  });
}
</script>

<template>
  <AppDialog
    v-model="open"
    :title="$t('invitations.createTitle')"
    :description="$t('invitations.createDescription')"
    :persistent="busy"
    size="s"
  >
    <form class="invite-form" novalidate @submit.prevent="submit">
      <AppInput
        id="invitation-email"
        v-model="form.values.email"
        :label="$t('invitations.email')"
        :error="form.errors.email ? $t(form.errors.email) : undefined"
        type="email"
        autocomplete="off"
        required
      />
      <div class="invite-form__role">
        <label class="invite-form__label" for="invitation-role">{{ $t('invitations.role') }}</label>
        <AppDropdown id="invitation-role" v-model="role" :label="$t('invitations.role')" :options="roleOptions" :disabled="busy" />
      </div>
      <p v-if="error" class="invite-form__error" role="alert">{{ error }}</p>
      <div class="invite-form__actions">
        <AppButton type="button" variant="secondary" :disabled="busy" @click="open = false">
          {{ $t('common.cancel') }}
        </AppButton>
        <AppButton type="submit" :loading="busy">{{ $t('invitations.send') }}</AppButton>
      </div>
    </form>
  </AppDialog>
</template>

<style scoped>
.invite-form {
  display: flex;
  flex-direction: column;
  gap: var(--space-16);
}

.invite-form__role {
  display: grid;
  gap: var(--space-6);
}

.invite-form__label {
  font-size: var(--type-body-medium-emphasis-size);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  color: var(--text-2);
  transition: var(--theme-transition);
}

.invite-form__error {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  color: var(--danger-text);
  transition: var(--theme-transition);
}

.invite-form__actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-12);
}
</style>
