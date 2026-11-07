<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { ApiResponseError } from '@/shared/api/errors';
import { useEducationI18n } from '../i18n';
const { t } = useEducationI18n();
const router = useRouter();
const code = ref('');
const busy = ref(false);
const error = ref('');
async function activate(): Promise<void> {
  busy.value = true;
  error.value = '';
  try {
    await coreAdminHttp.request('/education/student-activate', {
      method: 'POST',
      body: JSON.stringify({ code: code.value.trim() }),
    });
    await router.push('/student');
  } catch (failure) {
    if (failure instanceof ApiResponseError) {
      error.value = t('education.invalidInvite');
    } else {
      error.value = failure instanceof Error ? failure.message : 'error';
    }
  } finally {
    busy.value = false;
  }
}
</script>
<template>
  <section class="edu-activation">
    <h1>{{ t('education.activateStudentAccount') }}</h1>
    <form
      class="edu-form edu-card"
      @submit.prevent="activate"
    >
      <AppInput
        id="education-student-invite"
        v-model="code"
        :label="t('education.inviteCode')"
        maxlength="64"
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
</template>
