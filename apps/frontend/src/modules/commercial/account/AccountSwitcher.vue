<script setup lang="ts">
import { computed } from 'vue';
import type { CommercialAccountSummary } from '@/shared/types';

const props = defineProps<{
  accounts: CommercialAccountSummary[];
  currentId: string;
  currentName: string;
}>();

const emit = defineEmits<{
  select: [accountId: string];
}>();

const options = computed(() => {
  const list = props.accounts.map((account) => ({ id: account.id, name: account.display_name }));
  if (!list.some((account) => account.id === props.currentId)) {
    list.unshift({ id: props.currentId, name: props.currentName });
  }
  return list;
});

function onChange(event: Event): void {
  const value = (event.target as HTMLSelectElement).value;
  if (value !== props.currentId) {
    emit('select', value);
  }
}
</script>

<template>
  <label class="switcher">
    <span class="visually-hidden">{{ $t('account.switchAccount') }}</span>
    <select class="switcher__select" :value="currentId" @change="onChange">
      <option v-for="option in options" :key="option.id" :value="option.id">{{ option.name }}</option>
    </select>
  </label>
</template>

<style scoped>
.switcher {
  display: inline-flex;
  min-width: 0;
}

.switcher__select {
  height: var(--control-h-l);
  min-width: 0;
  max-width: 100%;
  padding: 0 var(--space-12);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-control-m);
  background: var(--surface);
  color: var(--text-1);
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  cursor: pointer;
}

.switcher__select:focus-visible {
  border-color: var(--focus-border);
  box-shadow: var(--focus-ring);
  outline: none;
}
</style>
