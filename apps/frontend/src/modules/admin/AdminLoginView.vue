<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppFormShell from '@/shared/ui/surfaces/AppFormShell.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppPasswordInput from '@/shared/ui/fields/AppPasswordInput.vue';

const auth = useAdminAuthStore();
const router = useRouter();
const route = useRoute();
const identifier = ref('');
const password = ref('');
const code = ref('');
const recoveryCode = ref('');
const mode = ref<'totp' | 'recovery'>('totp');
const submitting = ref(false);
const errorMessage = ref('');

const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/admin';

function finish(): void {
  void router.replace(redirect.startsWith('/admin') ? redirect : '/admin');
}

async function submitLogin(): Promise<void> {
  errorMessage.value = '';
  submitting.value = true;
  try {
    await auth.login(identifier.value, password.value);
    if (auth.isAuthenticated) {
      finish();
    }
  } catch (error) {
    errorMessage.value = resolveErrorMessage(error);
  } finally {
    submitting.value = false;
  }
}

async function submitMfa(): Promise<void> {
  errorMessage.value = '';
  submitting.value = true;
  try {
    await auth.verifyMfa(
      mode.value === 'totp' ? code.value.trim() : null,
      mode.value === 'recovery' ? recoveryCode.value.trim() : null,
    );
    finish();
  } catch (error) {
    errorMessage.value = resolveErrorMessage(error);
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <AppFormShell
    :title="auth.status === 'mfa_required' ? $t('admin.mfaTitle') : $t('admin.loginTitle')"
    :subtitle="auth.status === 'mfa_required' ? $t('admin.mfaSubtitle') : $t('admin.loginSubtitle')"
    :error="errorMessage || undefined"
  >
    <form v-if="auth.status !== 'mfa_required'" class="admin-login__form" @submit.prevent="submitLogin">
      <AppInput id="admin-identifier" v-model="identifier" :label="$t('admin.email')" type="email" autocomplete="username" required />
      <AppPasswordInput id="admin-password" v-model="password" :label="$t('admin.password')" autocomplete="current-password" required />
      <AppButton type="submit" block :loading="submitting">{{ $t('admin.signIn') }}</AppButton>
      <p class="admin-login__note"><RouterLink :to="{ name: 'catalog' }">{{ $t('admin.backToSite') }}</RouterLink></p>
    </form>

    <form v-else class="admin-login__form" @submit.prevent="submitMfa">
      <div class="admin-login__mode" role="tablist">
        <button type="button" role="tab" :aria-selected="mode === 'totp'" :class="{ 'admin-login__mode--active': mode === 'totp' }" @click="mode = 'totp'">{{ $t('admin.totp') }}</button>
        <button type="button" role="tab" :aria-selected="mode === 'recovery'" :class="{ 'admin-login__mode--active': mode === 'recovery' }" @click="mode = 'recovery'">{{ $t('admin.recoveryCode') }}</button>
      </div>
      <AppInput v-if="mode === 'totp'" id="admin-totp" v-model="code" :label="$t('admin.totpCode')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required />
      <AppInput v-else id="admin-recovery" v-model="recoveryCode" :label="$t('admin.recoveryCode')" autocomplete="off" required />
      <AppButton type="submit" block :loading="submitting">{{ $t('admin.verify') }}</AppButton>
    </form>
  </AppFormShell>
</template>

<style scoped>
.admin-login__form {
  display: grid;
  gap: var(--space-16);
}

.admin-login__note {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  color: var(--text-2);
  text-align: center;
}

.admin-login__mode {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-4);
  padding: var(--space-4);
  border-radius: var(--radius-control-m);
  background: var(--surface-hover);
}

.admin-login__mode button {
  min-height: 36px;
  border-radius: var(--radius-control-s);
  color: var(--text-2);
  font-size: var(--type-body-small-emphasis-size);
  font-weight: var(--type-body-small-emphasis-weight);
}

.admin-login__mode button.admin-login__mode--active {
  background: var(--surface);
  color: var(--text-1);
}
</style>
