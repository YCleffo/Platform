<script setup lang="ts">
import { ref } from 'vue';
import { ChevronDown } from '@/shared/ui/icons';
import AccountSwitcherDialog from './AccountSwitcherDialog.vue';

defineProps<{
  currentId: string;
  currentName: string;
}>();

const emit = defineEmits<{
  select: [accountId: string];
}>();

const open = ref(false);
</script>

<template>
  <div class="switcher">
    <button
      type="button"
      class="switcher__button"
      aria-haspopup="dialog"
      :aria-expanded="open"
      @click="open = true"
    >
      <span class="switcher__text">
        <span class="switcher__label">{{ $t('account.switchAccount') }}</span>
        <span class="switcher__value">{{ currentName }}</span>
      </span>
      <ChevronDown
        :size="16"
        aria-hidden="true"
      />
    </button>
    <AccountSwitcherDialog
      v-model="open"
      :current-id="currentId"
      :current-name="currentName"
      @select="emit('select', $event)"
    />
  </div>
</template>

<style scoped>
.switcher {
  display: inline-flex;
  max-width: 100%;
}

.switcher__button {
  display: inline-flex;
  align-items: center;
  gap: var(--space-8);
  min-height: var(--control-h-l);
  max-width: 100%;
  padding: var(--space-8) var(--space-12);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-control-m);
  background: var(--surface);
  color: var(--text-1);
  cursor: pointer;
  transition: var(--theme-transition);
}

.switcher__button:hover {
  background: var(--surface-hover);
  transition: var(--theme-transition);
}

.switcher__button:focus-visible {
  border-color: var(--focus-border);
  box-shadow: var(--focus-ring);
  outline: none;
  transition: var(--theme-transition);
}

.switcher__text {
  display: grid;
  min-width: 0;
  text-align: left;
}

.switcher__label {
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  color: var(--text-3);
  transition: var(--theme-transition);
}

.switcher__value {
  font-size: var(--type-body-medium-emphasis-size);
  letter-spacing: var(--type-body-medium-emphasis-tracking);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
