<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '@/core/auth/authStore';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppPasswordInput from '@/shared/ui/fields/AppPasswordInput.vue';
import ReauthenticateDialog from './ReauthenticateDialog.vue';
import { useSensitiveAction } from './useSensitiveAction';

const open = defineModel<boolean>({ default: false });
const emit = defineEmits<{
  completed: [];
  enrollmentRequired: [];
}>();

const { t } = useI18n();
const auth = useAuthStore();
const step = ref<1 | 2 | 3>(1);
const submitting = ref(false);
const serverError = ref('');
const error = reactive({ current: '', password: '', confirmation: '' });
const form = reactive({ current: '', password: '', confirmation: '' });

const { reauthOpen, runSensitive, onReauthenticated } = useSensitiveAction(
  (message) => {
    serverError.value = message;
  },
  () => {
    emit('enrollmentRequired');
    open.value = false;
  },
);

const stepLabel = computed(() => t('security.passwordStepOf', { current: step.value, total: 3 }));

watch(open, (value) => {
  if (!value) return;
  step.value = 1;
  submitting.value = false;
  serverError.value = '';
  error.current = '';
  error.password = '';
  error.confirmation = '';
  form.current = '';
  form.password = '';
  form.confirmation = '';
});

function back(): void {
  serverError.value = '';
  if (step.value === 1) {
    open.value = false;
    return;
  }
  step.value = (step.value - 1) as 1 | 2;
}

function next(): void {
  serverError.value = '';
  if (step.value === 1) {
    error.current = form.current === '' ? 'validation.required' : '';
    if (error.current === '') step.value = 2;
    return;
  }
  if (step.value === 2) {
    error.password =
      form.password.length < 12
        ? 'validation.passwordMin'
        : form.password === form.current
          ? 'validation.passwordReuse'
          : '';
    if (error.password === '') step.value = 3;
    return;
  }
  error.confirmation =
    form.confirmation === '' || form.confirmation !== form.password
      ? 'validation.passwordMismatch'
      : '';
  if (error.confirmation === '') void submit();
}

async function submit(): Promise<void> {
  submitting.value = true;
  try {
    const completed = await runSensitive(async () => {
      await auth.changePassword(form.current, form.password);
    });
    if (completed) {
      open.value = false;
      emit('completed');
    }
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <AppDialog
    v-model="open"
    :title="$t('security.changePassword')"
    :persistent="submitting"
    size="s"
  >
    <form
      class="password-change"
      novalidate
      @submit.prevent="next"
    >
      <p class="password-change__progress">{{ stepLabel }}</p>

      <AppPasswordInput
        v-if="step === 1"
        id="password-step-current"
        v-model="form.current"
        :label="$t('security.currentPassword')"
        :error="error.current ? $t(error.current) : undefined"
        autocomplete="current-password"
        required
      />
      <AppPasswordInput
        v-else-if="step === 2"
        id="password-step-new"
        v-model="form.password"
        :label="$t('security.newPassword')"
        :error="error.password ? $t(error.password) : undefined"
        autocomplete="new-password"
        required
      />
      <AppPasswordInput
        v-else
        id="password-step-confirm"
        v-model="form.confirmation"
        :label="$t('security.confirmPassword')"
        :error="error.confirmation ? $t(error.confirmation) : undefined"
        autocomplete="new-password"
        required
      />

      <p
        v-if="serverError"
        class="password-change__error"
        role="alert"
      >
        {{ serverError }}
      </p>

      <div class="password-change__actions">
        <AppButton
          type="button"
          variant="secondary"
          :disabled="submitting"
          @click="back"
        >
          {{ step === 1 ? $t('common.cancel') : $t('security.passwordBack') }}
        </AppButton>
        <AppButton
          type="submit"
          :loading="submitting"
        >
          {{ step === 3 ? $t('security.changePassword') : $t('security.passwordNext') }}
        </AppButton>
      </div>
    </form>

    <ReauthenticateDialog
      v-model="reauthOpen"
      @authenticated="onReauthenticated"
    />
  </AppDialog>
</template>

<style scoped>
.password-change {
  display: grid;
  gap: var(--space-16);
}
.password-change__progress {
  color: var(--text-3);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
}
.password-change__error {
  color: var(--danger-text);
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
}
.password-change__actions {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: var(--space-8);
}
</style>
