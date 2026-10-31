<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import type { RoleOption } from './accountPresentation';
import type { AccountRole } from '@/shared/types';

const props = defineProps<{
  memberEmail: string;
  roles: RoleOption[];
  busy: boolean;
  error: string | null;
}>();

const open = defineModel<boolean>({ default: false });

const emit = defineEmits<{
  confirm: [role: AccountRole];
}>();

const selected = ref<AccountRole | ''>('');

watch(open, (value) => {
  if (value) {
    selected.value = props.roles[0]?.value ?? '';
  }
});

const canConfirm = computed(() => selected.value !== '' && !props.busy);

function submit(): void {
  if (selected.value !== '' && !props.busy) {
    emit('confirm', selected.value);
  }
}
</script>

<template>
  <AppDialog
    v-model="open"
    :title="$t('account.roleDialogTitle')"
    :description="$t('account.roleDialogDescription', { email: memberEmail })"
    :persistent="busy"
    size="s"
  >
    <form class="role-form" @submit.prevent="submit">
      <label class="role-form__label" for="member-role">{{ $t('account.selectRole') }}</label>
      <AppDropdown id="member-role" v-model="selected" :label="$t('account.selectRole')" :options="roles" :disabled="busy" />
      <p v-if="error" class="role-form__error" role="alert">{{ error }}</p>
      <div class="role-form__actions">
        <AppButton type="button" variant="secondary" :disabled="busy" @click="open = false">
          {{ $t('common.cancel') }}
        </AppButton>
        <AppButton type="submit" :loading="busy" :disabled="!canConfirm">
          {{ $t('common.save') }}
        </AppButton>
      </div>
    </form>
  </AppDialog>
</template>

<style scoped>
.role-form {
  display: flex;
  flex-direction: column;
  gap: var(--space-16);
}

.role-form__label {
  font-size: var(--type-body-medium-emphasis-size);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  color: var(--text-2);
  transition: var(--theme-transition);
}

.role-form__error {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  color: var(--danger-text);
  transition: var(--theme-transition);
}

.role-form__actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-12);
}
</style>
