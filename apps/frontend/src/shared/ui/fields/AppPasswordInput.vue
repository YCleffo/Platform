<script setup lang="ts">
import { ref } from 'vue';
import { Eye, EyeOff } from '@lucide/vue';
import AppInput from './AppInput.vue';
import AppIconButton from '../actions/AppIconButton.vue';

interface Props {
  id: string;
  label?: string;
  placeholder?: string;
  autocomplete?: string;
  error?: string;
  hint?: string;
  required?: boolean;
  disabled?: boolean;
}

withDefaults(defineProps<Props>(), {
  label: undefined,
  placeholder: undefined,
  autocomplete: undefined,
  error: undefined,
  hint: undefined,
  required: false,
  disabled: false,
});

const model = defineModel<string>({ default: '' });

const visible = ref(false);
</script>

<template>
  <AppInput
    :id="id"
    v-model="model"
    :type="visible ? 'text' : 'password'"
    :label="label"
    :placeholder="placeholder"
    :autocomplete="autocomplete"
    :error="error"
    :hint="hint"
    :required="required"
    :disabled="disabled"
    size="l"
  >
    <template #trailing>
      <AppIconButton
        size="s"
        :label="visible ? $t('common.hidePassword') : $t('common.showPassword')"
        @click="visible = !visible"
      >
        <EyeOff v-if="visible" :size="18" aria-hidden="true" />
        <Eye v-else :size="18" aria-hidden="true" />
      </AppIconButton>
    </template>
  </AppInput>
</template>
