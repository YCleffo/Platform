<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { X } from '@/shared/ui/icons';
import { useFocusTrap } from '@/shared/composables/useFocusTrap';
import { lockScroll, unlockScroll } from '@/shared/composables/useScrollLock';
import AppIconButton from '../actions/AppIconButton.vue';

interface Props {
  title: string;
  description?: string;
  size?: 's' | 'm' | 'l';
  closeOnBackdrop?: boolean;
  closeOnEscape?: boolean;
  persistent?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  description: undefined,
  size: 'm',
  closeOnBackdrop: true,
  closeOnEscape: true,
  persistent: false,
});

const open = defineModel<boolean>({ default: false });

const uid = useId();
const titleId = `${uid}-title`;
const descriptionId = `${uid}-description`;

const dialogEl = ref<HTMLElement | null>(null);
const { activate, deactivate } = useFocusTrap(dialogEl);

let overlayActive = false;
let scrollLocked = false;

function requestClose(): void {
  if (props.persistent) {
    return;
  }
  open.value = false;
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape' && props.closeOnEscape) {
    requestClose();
  }
}

function activateOverlay(): void {
  if (overlayActive) {
    return;
  }
  overlayActive = true;
  document.addEventListener('keydown', onKeydown);
  lockScroll();
  scrollLocked = true;
  void nextTick(() => {
    if (overlayActive) {
      activate();
    }
  });
}

function deactivateOverlay(): void {
  document.removeEventListener('keydown', onKeydown);
  if (overlayActive) {
    deactivate();
    overlayActive = false;
  }
  if (scrollLocked) {
    unlockScroll();
    scrollLocked = false;
  }
}

watch(
  open,
  (value) => {
    if (value) {
      activateOverlay();
    } else {
      deactivateOverlay();
    }
  },
  { immediate: true },
);

onBeforeUnmount(deactivateOverlay);

function markLeaving(element: Element): void {
  element.setAttribute('inert', '');
  element.setAttribute('aria-hidden', 'true');
}
function markEntering(element: Element): void {
  element.removeAttribute('inert');
  element.removeAttribute('aria-hidden');
}
</script>

<template>
  <Teleport to="body">
    <Transition name="dialog" @before-leave="markLeaving" @before-enter="markEntering">
    <div v-if="open" class="app-dialog" @mousedown.self="closeOnBackdrop ? requestClose() : undefined">
      <div
        ref="dialogEl"
        class="app-dialog__panel"
        :class="`app-dialog__panel--${size}`"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        :aria-describedby="description ? descriptionId : undefined"
        tabindex="-1"
      >
        <header class="app-dialog__header">
          <h2 :id="titleId" class="app-dialog__title">{{ title }}</h2>
          <AppIconButton v-if="!persistent" :label="$t('common.close')" @click="requestClose">
            <X :size="18" aria-hidden="true" />
          </AppIconButton>
        </header>
        <p v-if="description" :id="descriptionId" class="app-dialog__description">
          {{ description }}
        </p>
        <div class="app-dialog__body">
          <slot />
        </div>
      </div>
    </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.dialog-enter-active { transition: var(--theme-transition), opacity var(--motion-enter-duration) var(--motion-ease); }
.dialog-leave-active { transition: var(--theme-transition), opacity var(--motion-leave-duration) var(--motion-ease); }
.dialog-enter-active .app-dialog__panel { transition: var(--theme-transition), transform var(--motion-enter-duration) var(--motion-ease); }
.dialog-leave-active .app-dialog__panel { transition: var(--theme-transition), transform var(--motion-leave-duration) var(--motion-ease); }
.dialog-enter-from, .dialog-leave-to { opacity: 0; }
.dialog-enter-from .app-dialog__panel, .dialog-leave-to .app-dialog__panel { transform: translateY(var(--space-12)); }
.app-dialog {
  position: fixed;
  inset: 0;
  z-index: 1000;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  background: var(--backdrop);
  transition: var(--theme-transition);
}

.app-dialog__panel {
  width: 100%;
  max-height: 92dvh;
  display: flex;
  flex-direction: column;
  background: var(--overlay-surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-overlay-modal) var(--radius-overlay-modal) 0 0;
  box-shadow: var(--shadow-modal);
  overflow: hidden;
  padding-bottom: env(safe-area-inset-bottom);
  transition: var(--theme-transition);
}

.app-dialog__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--gap-l);
  padding: var(--pad-modal-default) var(--pad-modal-default) var(--space-8);
}

.app-dialog__title {
  font-size: var(--type-h2-size);
  line-height: var(--type-h2-line);
  font-weight: var(--type-h2-weight);
  letter-spacing: var(--type-h2-tracking);
  color: var(--text-1);
  transition: var(--theme-transition);
}

.app-dialog__description {
  padding: 0 var(--pad-modal-default);
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  color: var(--text-2);
  transition: var(--theme-transition);
}

.app-dialog__body {
  padding: var(--space-16) var(--pad-modal-default) var(--pad-modal-default);
  overflow-y: auto;
}

@media (min-width: 768px) {
  .app-dialog {
    align-items: center;
    padding: var(--pad-modal-large);
  }

  .app-dialog__panel {
    max-width: 480px;
    max-height: calc(100dvh - 48px);
    border-radius: var(--radius-overlay-modal);
    padding-bottom: 0;
  }

  .app-dialog__panel--s {
    max-width: 400px;
  }

  .app-dialog__panel--l {
    max-width: 640px;
  }

  .app-dialog__header {
    padding: var(--pad-modal-large) var(--pad-modal-large) var(--space-8);
  }

  .app-dialog__description {
    padding: 0 var(--pad-modal-large);
  }

  .app-dialog__body {
    padding: var(--space-16) var(--pad-modal-large) var(--pad-modal-large);
  }
}
</style>
