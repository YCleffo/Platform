<script setup lang="ts">
import { onScopeDispose, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import { coreAdminSession, coreAdminSessionEpoch } from '@/core/admin/session';
import { ApiError } from '@/shared/api/errors';
import { educationApi } from '../api';
import { useEducationI18n } from '../i18n';
import { useEducationError } from '../resources';
import '../education.css';
const { t } = useEducationI18n();
const issue = useEducationError();
const router = useRouter();
const code = ref('');
const busy = ref(false);
const error = ref('');
let controller: AbortController | null = null;
function reset(): void {
  controller?.abort();
  controller = null;
  code.value = '';
  busy.value = false;
  error.value = '';
}
watch(coreAdminSessionEpoch, reset, { flush: 'sync' });
onScopeDispose(reset);
async function activate(): Promise<void> {
  if (busy.value || !code.value.trim()) return;
  const request = new AbortController();
  const epoch = coreAdminSession.epoch();
  const current = () => !request.signal.aborted && epoch === coreAdminSession.epoch();
  controller = request;
  busy.value = true;
  error.value = '';
  try {
    await educationApi.activateStudent(code.value.trim(), request.signal);
    if (current()) await router.replace('/student/home');
  } catch (failure) {
    if (current())
      error.value =
        failure instanceof ApiError && [404, 409, 410, 422].includes(failure.status)
          ? t('education.invalidInvite')
          : issue(failure);
  } finally {
    if (controller === request) {
      controller = null;
      busy.value = false;
    }
  }
}
</script>
<template>
  <main class="education-login">
    <section class="edu-login-card">
      <h1>{{ t('education.activateStudentAccount') }}</h1>
      <p>{{ t('education.studentActivationHint') }}</p>
      <form
        class="edu-form edu-card"
        @submit.prevent="activate"
      >
        <AppInput
          id="education-student-invite"
          v-model="code"
          :label="t('education.inviteCode')"
          maxlength="64"
          autocomplete="off"
          required
          :disabled="busy"
        />
        <p
          v-if="error"
          class="edu-error"
          role="alert"
        >
          {{ error }}
        </p>
        <div class="edu-form-actions">
          <button
            type="submit"
            class="edu-button edu-button--primary"
            :disabled="busy"
          >
            {{ t('education.activate') }}
          </button>
          <RouterLink
            to="/student"
            class="edu-link"
          >
            {{ t('education.studentCabinet') }}
          </RouterLink>
        </div>
      </form>
    </section>
  </main>
</template>
