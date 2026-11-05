<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { draftValue, valueKinds, type ValueDraft } from './valueDraft';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppSwitch from '@/shared/ui/fields/AppSwitch.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
const model = defineModel<ValueDraft>({ required: true });
withDefaults(defineProps<{ disabled?: boolean; depth?: number; objectOnly?: boolean }>(), {
  disabled: false,
  depth: 0,
  objectOnly: false,
});
const { t } = useI18n();
const kinds = computed(() =>
  valueKinds.map((value) => ({ value, label: t('configuration.type_' + value) })),
);
</script>
<template>
  <div class="config-value">
    <AppDropdown
      v-if="!objectOnly"
      :id="`value-type-${model.id}`"
      v-model="model.kind"
      :label="$t('configuration.valueType')"
      :options="kinds"
      :disabled="disabled"
    />
    <AppInput
      v-if="model.kind === 'string' || model.kind === 'number'"
      :id="`value-text-${model.id}`"
      v-model="model.text"
      :label="$t('configuration.value')"
      :inputmode="model.kind === 'number' ? 'decimal' : undefined"
      :disabled="disabled"
    />
    <AppSwitch
      v-else-if="model.kind === 'boolean'"
      :id="`value-bool-${model.id}`"
      v-model="model.checked"
      :label="$t('configuration.type_boolean')"
      :disabled="disabled"
    />
    <p
      v-else-if="model.kind === 'null'"
      class="config-note"
    >
      {{ $t('configuration.nullHint') }}
    </p>
    <template v-else>
      <div
        v-for="(child, index) in model.children"
        :key="child.node.id"
        class="config-value__child"
      >
        <AppInput
          v-if="model.kind === 'object'"
          :id="`value-key-${child.node.id}`"
          v-model="child.key"
          :label="$t('configuration.property')"
          :disabled="disabled"
        />
        <p v-else>{{ $t('configuration.item', { index: index + 1 }) }}</p>
        <ConfigValueEditor
          v-model="child.node"
          :depth="depth + 1"
          :disabled="disabled"
        />
        <AppButton
          variant="secondary"
          size="s"
          :disabled="disabled"
          @click="model.children.splice(index, 1)"
        >
          {{ $t('configuration.removeItem') }}
        </AppButton>
      </div>
      <AppButton
        variant="secondary"
        size="s"
        :disabled="disabled || depth >= 16 || model.children.length >= 100"
        @click="model.children.push({ key: '', node: draftValue() })"
      >
        {{ $t('configuration.addItem') }}
      </AppButton>
    </template>
  </div>
</template>
<style scoped>
.config-value {
  display: grid;
  gap: var(--space-12);
  min-width: 0;
}
.config-value__child {
  display: grid;
  gap: var(--space-8);
  border-inline-start: 2px solid var(--border);
  padding-inline-start: var(--space-12);
  min-width: 0;
}
</style>
