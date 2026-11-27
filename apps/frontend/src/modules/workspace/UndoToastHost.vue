<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Undo2, X } from '@/shared/ui/icons';
import { useToast } from '@/shared/composables/useToast';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppIconButton from '@/shared/ui/actions/AppIconButton.vue';
import { useOperationsCenter } from './useOperationsCenter';

const { t } = useI18n();
const operations = useOperationsCenter();
const toast = useToast();

async function handleUndo(operationId: string): Promise<void> {
  const res = await operations.undoOperation(operationId);
  if (res.ok) {
    toast.success(t('workspace.opsUndoneLabel'));
  } else {
    toast.error(res.message ?? t('workspace.opsUndoExpired'));
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="operations.undoQueue.length > 0"
      class="undo-toast-host"
      aria-live="polite"
      data-testid="undo-toast-host"
    >
      <div
        v-for="entry in operations.undoQueue"
        :key="entry.operationId"
        class="undo-toast"
        :class="{ 'undo-toast--error': Boolean(entry.error) || entry.expired }"
        role="status"
        data-testid="undo-toast-item"
      >
        <div class="undo-toast__copy">
          <strong class="undo-toast__title">{{ entry.title }}</strong>
          <span
            v-if="entry.error"
            class="undo-toast__error"
            data-testid="undo-toast-error"
          >
            {{ entry.error }}
          </span>
          <span
            v-else-if="entry.expired || entry.remainingSeconds <= 0"
            class="undo-toast__meta"
          >
            {{ $t('workspace.opsUndoExpired') }}
          </span>
          <span
            v-else
            class="undo-toast__meta"
          >
            {{ $t('workspace.opsUndoRemaining', { seconds: entry.remainingSeconds }) }}
          </span>
        </div>

        <div class="undo-toast__actions">
          <AppButton
            v-if="!entry.expired && entry.remainingSeconds > 0"
            size="s"
            variant="primary"
            :loading="entry.busy"
            data-testid="undo-toast-action"
            @click="handleUndo(entry.operationId)"
          >
            <Undo2
              :size="14"
              aria-hidden="true"
            />
            {{ entry.busy ? $t('workspace.opsUndoing') : $t('workspace.opsUndo') }}
          </AppButton>
          <AppIconButton
            :label="$t('common.close')"
            @click="operations.dismissUndo(entry.operationId)"
          >
            <X
              :size="16"
              aria-hidden="true"
            />
          </AppIconButton>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.undo-toast-host {
  position: fixed;
  right: var(--space-16);
  bottom: calc(var(--space-24) + 64px);
  z-index: 1300;
  display: flex;
  flex-direction: column;
  gap: var(--space-8);
  width: min(calc(100vw - 32px), 420px);
  pointer-events: none;
}

@media (min-width: 768px) {
  .undo-toast-host {
    bottom: var(--space-24);
  }
}

.undo-toast {
  pointer-events: auto;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-12);
  padding: var(--space-12) var(--space-16);
  border-radius: var(--radius-control-l);
  background: var(--overlay-surface);
  color: var(--text-1);
  border: 1px solid var(--border-strong);
  box-shadow: var(--shadow-modal);
  transition: var(--theme-transition);
}

.undo-toast--error {
  border-color: var(--danger);
}

.undo-toast__copy {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.undo-toast__title {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.undo-toast__meta {
  color: var(--text-2);
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
}

.undo-toast__error {
  color: var(--danger-text);
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
}

.undo-toast__actions {
  display: flex;
  align-items: center;
  gap: var(--space-4);
  flex: 0 0 auto;
}
</style>
