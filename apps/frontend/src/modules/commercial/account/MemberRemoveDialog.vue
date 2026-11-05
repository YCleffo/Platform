<script setup lang="ts">
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';

defineProps<{
  email: string;
  roleLabel: string;
  busy: boolean;
  error: string | null;
}>();

const open = defineModel<boolean>({ default: false });

const emit = defineEmits<{
  confirm: [];
}>();
</script>

<template>
  <AppDialog
    v-model="open"
    :title="$t('account.removeDialogTitle')"
    :description="$t('account.removeDialogDescription', { email, role: roleLabel })"
    :persistent="busy"
    size="s"
  >
    <div class="remove-form">
      <p
        v-if="error"
        class="remove-form__error"
        role="alert"
      >
        {{ error }}
      </p>
      <div class="remove-form__actions">
        <AppButton
          type="button"
          variant="secondary"
          :disabled="busy"
          @click="open = false"
        >
          {{ $t('common.cancel') }}
        </AppButton>
        <AppButton
          type="button"
          variant="danger"
          :loading="busy"
          @click="emit('confirm')"
        >
          {{ $t('account.confirmRemove') }}
        </AppButton>
      </div>
    </div>
  </AppDialog>
</template>

<style scoped>
.remove-form {
  display: flex;
  flex-direction: column;
  gap: var(--space-16);
}

.remove-form__error {
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  color: var(--danger-text);
  transition: var(--theme-transition);
}

.remove-form__actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-12);
}
</style>
