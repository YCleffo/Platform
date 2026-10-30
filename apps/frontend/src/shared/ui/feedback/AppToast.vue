<script setup lang="ts">
import { CircleAlert, CircleCheck, Info, TriangleAlert, X } from '@lucide/vue';
import { useToast } from '@/shared/composables/useToast';
import AppIconButton from '../actions/AppIconButton.vue';

const { toasts, dismiss } = useToast();
</script>

<template>
  <Teleport to="body">
    <div class="app-toast-host" role="region" aria-live="polite" :aria-label="$t('common.notifications')">
      <TransitionGroup name="toast">
        <div
          v-for="toast in toasts"
          :key="toast.id"
          class="app-toast"
          :class="`app-toast--${toast.kind}`"
          role="status"
        >
          <span class="app-toast__icon" aria-hidden="true">
            <CircleAlert v-if="toast.kind === 'error'" :size="18" />
            <TriangleAlert v-else-if="toast.kind === 'warning'" :size="18" />
            <CircleCheck v-else-if="toast.kind === 'success'" :size="18" />
            <Info v-else :size="18" />
          </span>
          <span class="app-toast__message">{{ toast.message }}</span>
          <AppIconButton size="s" :label="$t('common.dismiss')" @click="dismiss(toast.id)">
            <X :size="16" aria-hidden="true" />
          </AppIconButton>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<style scoped>
.app-toast-host {
  position: fixed;
  left: var(--space-12);
  right: var(--space-12);
  bottom: calc(var(--bottom-nav-h) + var(--space-12) + env(safe-area-inset-bottom));
  display: flex;
  flex-direction: column;
  gap: var(--space-8);
  z-index: 1200;
}

.app-toast {
  display: flex;
  align-items: center;
  gap: var(--space-8);
  padding: var(--space-12);
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  box-shadow: var(--shadow-popover);
}

.app-toast__icon {
  display: inline-flex;
  align-items: center;
  color: var(--text-2);
}

.app-toast--success .app-toast__icon {
  color: var(--success);
}

.app-toast--warning .app-toast__icon {
  color: var(--warning);
}

.app-toast--error .app-toast__icon {
  color: var(--danger);
}

.app-toast__message {
  flex: 1;
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  color: var(--text-1);
}

.toast-enter-active,
.toast-leave-active {
  transition:
    opacity 160ms ease,
    transform 160ms ease;
}

.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateY(8px);
}

@media (min-width: 768px) {
  .app-toast-host {
    left: auto;
    right: var(--space-24);
    bottom: var(--space-24);
    width: 360px;
  }
}
</style>
