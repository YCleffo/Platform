<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { coreAdminAuth } from '@/shared/api/core/adminAuth';
import { RequestAbortedError } from '@/shared/api/errors';
import { useAsyncAction } from '@/shared/composables/useAsyncAction';
import { useForm } from '@/shared/composables/useForm';
import { email } from '@/shared/validation/rules';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppFormShell from '@/shared/ui/surfaces/AppFormShell.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import './core-auth.css';

const props = defineProps<{ kind: 'password' | 'mfa' }>();
const accepted = ref(false);
let controller: AbortController | null = null;
const form = useForm({
  initial: { email: '' },
  fields: { email: { id: 'core-recovery-email', rules: [email] } },
});
const { submitting, message, run, clearError } = useAsyncAction(async () => {
  const request = new AbortController();
  controller = request;
  const action =
    props.kind === 'password'
      ? coreAdminAuth.requestPasswordReset
      : coreAdminAuth.requestMfaRecovery;
  await action(form.values.email.trim(), request.signal);
  if (request.signal.aborted) throw new RequestAbortedError();
  accepted.value = true;
  form.reset();
});

watch(
  () => props.kind,
  () => {
    controller?.abort();
    accepted.value = false;
    form.reset();
    clearError();
  },
);
onBeforeUnmount(() => {
  controller?.abort();
  form.reset();
});

async function onSubmit(): Promise<void> {
  if (form.validate()) await run();
}
</script>

<template>
  <AppFormShell
    :title="$t(accepted ? 'coreAuth.requestAcceptedTitle' : `coreAuth.${kind}RequestTitle`)"
    :subtitle="$t(accepted ? 'coreAuth.requestAccepted' : `coreAuth.${kind}RequestSubtitle`)"
    :error="message"
  >
    <form
      v-if="!accepted"
      class="core-auth-form"
      novalidate
      @submit.prevent="onSubmit"
    >
      <AppInput
        id="core-recovery-email"
        v-model="form.values.email"
        :label="$t('admin.email')"
        type="email"
        autocomplete="username"
        :error="form.errors.email ? $t(form.errors.email) : undefined"
        required
        :disabled="submitting"
      />
      <AppButton
        type="submit"
        block
        :loading="submitting"
      >
        {{ $t('coreAuth.sendLink') }}
      </AppButton>
    </form>
    <p
      v-else
      class="core-auth-notice"
      role="status"
    >
      {{ $t('coreAuth.checkSpam') }}
    </p>
    <p class="core-auth-links">
      <RouterLink :to="{ name: 'admin-login' }">{{ $t('coreAuth.backToLogin') }}</RouterLink>
      <RouterLink
        v-if="accepted"
        :to="{
          name: kind === 'password' ? 'core-mfa-recovery-request' : 'core-password-reset-request',
        }"
      >
        {{ $t(kind === 'password' ? 'coreAuth.mfaRequestTitle' : 'coreAuth.passwordRequestTitle') }}
      </RouterLink>
    </p>
  </AppFormShell>
</template>
