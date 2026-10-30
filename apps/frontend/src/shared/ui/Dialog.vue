<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

interface Props {
  title: string;
}

defineProps<Props>();

const open = defineModel<boolean>({ default: false });
const dialogEl = ref<HTMLElement | null>(null);

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    open.value = false;
  }
}

watch(open, async (value) => {
  if (value) {
    document.addEventListener('keydown', onKeydown);
    document.body.style.overflow = 'hidden';
    await nextTick();
    dialogEl.value?.focus();
  } else {
    document.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = '';
  }
});

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown);
  document.body.style.overflow = '';
});
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="dialog-overlay" @click.self="open = false">
      <div
        ref="dialogEl"
        class="dialog"
        role="dialog"
        aria-modal="true"
        :aria-label="title"
        tabindex="-1"
      >
        <header class="dialog__header">
          <h2 class="dialog__title">{{ title }}</h2>
          <button class="dialog__close" type="button" :aria-label="$t('common.close')" @click="open = false">
            &times;
          </button>
        </header>
        <div class="dialog__body">
          <slot />
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.dialog-overlay {
  position: fixed;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--space-4);
  background: rgba(16, 24, 40, 0.5);
  z-index: 1000;
}

.dialog {
  width: 100%;
  max-width: 28rem;
  background: var(--color-surface);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-lg);
  overflow: hidden;
}

.dialog__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-4) var(--space-5);
  border-bottom: 1px solid var(--color-border);
}

.dialog__title {
  font-size: var(--text-lg);
}

.dialog__close {
  border: none;
  background: transparent;
  font-size: 1.5rem;
  line-height: 1;
  cursor: pointer;
  color: var(--color-text-muted);
}

.dialog__body {
  padding: var(--space-5);
}
</style>
