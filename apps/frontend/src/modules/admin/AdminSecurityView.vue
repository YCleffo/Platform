<script setup lang="ts">
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppPasswordInput from '@/shared/ui/fields/AppPasswordInput.vue';

const { t } = useI18n();
const form = reactive({ current: '', password: '', confirmation: '' });
const busy = ref(false);
const error = ref('');
const success = ref('');

async function submit(): Promise<void> {
  error.value = '';
  success.value = '';
  if (form.password.length < 12) {
    error.value = t('validation.passwordMin');
    return;
  }
  if (form.password !== form.confirmation) {
    error.value = t('validation.passwordMismatch');
    return;
  }
  busy.value = true;
  try {
    await coreAdminHttp.post('/auth/me/password/change', {
      currentPassword: form.current,
      newPassword: form.password,
    });
    form.current = '';
    form.password = '';
    form.confirmation = '';
    success.value = t('admin.passwordChanged');
  } catch (caught) {
    error.value = resolveErrorMessage(caught);
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <section class="admin-security">
    <header>
      <h2>{{ $t('admin.security') }}</h2>
      <p>{{ $t('admin.securityHint') }}</p>
    </header>
    <AppCard>
      <form class="admin-security__form" @submit.prevent="submit">
        <AppPasswordInput id="admin-current-password" v-model="form.current" :label="$t('admin.currentPassword')" autocomplete="current-password" required />
        <AppPasswordInput id="admin-new-password" v-model="form.password" :label="$t('admin.newPassword')" autocomplete="new-password" required />
        <AppPasswordInput id="admin-confirm-password" v-model="form.confirmation" :label="$t('admin.confirmPassword')" autocomplete="new-password" required />
        <p v-if="error" class="admin-security__error" role="alert">{{ error }}</p>
        <p v-if="success" class="admin-security__success" role="status">{{ success }}</p>
        <AppButton type="submit" :loading="busy">{{ $t('admin.changePassword') }}</AppButton>
      </form>
    </AppCard>
  </section>
</template>

<style scoped>
.admin-security { display: grid; gap: var(--space-16); max-width: 640px; }
.admin-security h2 { font-size: var(--type-h2-size); line-height: var(--type-h2-line); font-weight: var(--type-h2-weight); color: var(--text-1); }
.admin-security header p { margin-top: var(--space-4); font-size: var(--type-body-medium-size); line-height: var(--type-body-medium-line); color: var(--text-2); }
.admin-security__form { display: grid; gap: var(--space-12); }
.admin-security__error { color: var(--danger); font-size: var(--type-body-medium-size); }
.admin-security__success { color: var(--success); font-size: var(--type-body-medium-size); }
</style>
