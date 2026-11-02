<script setup lang="ts">
import AppDialog from './AppDialog.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';

withDefaults(defineProps<{
  title: string;
  description?: string;
  confirmLabel: string;
  cancelLabel?: string;
  busy?: boolean;
  danger?: boolean;
}>(), {
  description: undefined,
  cancelLabel: undefined,
  busy: false,
  danger: false,
});

const open = defineModel<boolean>({ default: false });
const emit = defineEmits<{ confirm: [] }>();
</script>

<template>
  <AppDialog v-model="open" :title="title" :description="description" :persistent="busy" size="s">
    <div class="confirm">
      <slot />
      <div class="confirm__actions">
        <AppButton variant="secondary" :disabled="busy" @click="open = false">
          {{ cancelLabel ?? $t('common.cancel') }}
        </AppButton>
        <AppButton :variant="danger ? 'danger' : 'primary'" :loading="busy" @click="emit('confirm')">
          {{ confirmLabel }}
        </AppButton>
      </div>
    </div>
  </AppDialog>
</template>

<style scoped>
.confirm {
  display: grid;
  gap: var(--space-16);
}

.confirm__actions {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: var(--space-8);
}
</style>
