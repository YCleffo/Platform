<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Clock, RotateCw, Undo2, X } from '@/shared/ui/icons';
import { useFocusTrap } from '@/shared/composables/useFocusTrap';
import { lockScroll, unlockScroll } from '@/shared/composables/useScrollLock';
import { useToast } from '@/shared/composables/useToast';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppIconButton from '@/shared/ui/actions/AppIconButton.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import { useOperationsCenter } from './useOperationsCenter';

const { t } = useI18n();
const operations = useOperationsCenter();
const toast = useToast();

const drawerEl = ref<HTMLElement | null>(null);
const { activate, deactivate, isActive } = useFocusTrap(drawerEl);
let overlayLocked = false;
let previousFocus: HTMLElement | null = null;
const busyId = ref<string | null>(null);

const open = computed({
  get: () => operations.drawerOpen,
  set: (val: boolean) => {
    operations.drawerOpen = val;
  },
});

const hasAnyItems = computed(
  () =>
    operations.runningUploads.length > 0 ||
    operations.runningOperations.length > 0 ||
    operations.undoableOperations.length > 0 ||
    operations.scheduledOperations.length > 0 ||
    operations.failedOperations.length > 0 ||
    operations.failedUploads.length > 0 ||
    operations.completedOperations.length > 0 ||
    operations.finishedUploads.length > 0,
);

function undoRemainingFor(opId: string, deadlineIso?: string | null): number {
  const fromQueue = operations.undoQueue.find((u) => u.operationId === opId);
  if (fromQueue) return fromQueue.remainingSeconds;
  if (!deadlineIso) return 0;
  return Math.max(0, Math.ceil((Date.parse(deadlineIso) - Date.now()) / 1000));
}

async function handleUndo(opId: string): Promise<void> {
  busyId.value = opId;
  try {
    const res = await operations.undoOperation(opId);
    if (res.ok) {
      toast.success(t('workspace.opsUndoneLabel'));
    } else {
      toast.error(res.message ?? t('workspace.opsUndoExpired'));
    }
  } finally {
    busyId.value = null;
  }
}

async function handleCancelOp(opId: string): Promise<void> {
  busyId.value = opId;
  try {
    await operations.cancelOperation(opId);
    toast.info(t('workspace.opsCancelledLabel'));
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    busyId.value = null;
  }
}

async function handleRetryOp(opId: string): Promise<void> {
  busyId.value = opId;
  try {
    await operations.retryOperation(opId);
    toast.info(t('workspace.opsRunning'));
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    busyId.value = null;
  }
}

async function handleCancelUpload(uploadId: string): Promise<void> {
  await operations.cancelUpload(uploadId);
  toast.info(t('workspace.uploadCancelled'));
}

function close(): void {
  open.value = false;
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape' && open.value && isActive()) {
    event.preventDefault();
    close();
  }
}

watch(
  open,
  async (isOpen) => {
    if (isOpen) {
      previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
      document.addEventListener('keydown', onKeydown);
      if (!overlayLocked) {
        overlayLocked = true;
        lockScroll();
      }
      await nextTick();
      activate();
      void operations.syncOperations().catch(() => undefined);
    } else {
      document.removeEventListener('keydown', onKeydown);
      deactivate();
      if (overlayLocked) {
        overlayLocked = false;
        unlockScroll();
      }
      if (previousFocus && typeof previousFocus.focus === 'function') {
        previousFocus.focus();
        previousFocus = null;
      }
    }
  },
  { flush: 'post' },
);

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown);
  deactivate();
  if (overlayLocked) {
    overlayLocked = false;
    unlockScroll();
  }
});
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="ops-drawer__backdrop"
      @mousedown.self="close"
    >
      <aside
        ref="drawerEl"
        class="ops-drawer"
        role="dialog"
        aria-modal="true"
        :aria-label="$t('workspace.operationsTitle')"
        data-testid="operations-drawer"
      >
        <header class="ops-drawer__header">
          <div class="ops-drawer__title-wrap">
            <h2 class="ops-drawer__title">{{ $t('workspace.operationsTitle') }}</h2>
            <p class="ops-drawer__subtitle">{{ $t('workspace.operationsSubtitle') }}</p>
          </div>
          <div class="ops-drawer__header-actions">
            <AppIconButton
              :label="$t('workspace.opsRefresh')"
              @click="operations.syncOperations()"
            >
              <RotateCw
                :size="18"
                aria-hidden="true"
              />
            </AppIconButton>
            <AppIconButton
              :label="$t('common.close')"
              @click="close"
            >
              <X
                :size="18"
                aria-hidden="true"
              />
            </AppIconButton>
          </div>
        </header>

        <div class="ops-drawer__body">
          <p
            v-if="operations.error"
            class="ops-drawer__error"
            role="alert"
          >
            {{ operations.error }}
          </p>

          <AppEmptyState
            v-if="!hasAnyItems && !operations.loading"
            :title="$t('workspace.operationsEmpty')"
          />

          <!-- Running uploads & active operations -->
          <section
            v-if="operations.runningUploads.length > 0 || operations.runningOperations.length > 0"
            class="ops-drawer__section"
          >
            <h3 class="ops-drawer__section-title">{{ $t('workspace.opsRunning') }}</h3>

            <article
              v-for="upload in operations.runningUploads"
              :key="upload.id"
              class="ops-drawer__card"
              data-testid="operations-upload-item"
            >
              <div class="ops-drawer__card-head">
                <strong class="ops-drawer__card-title">{{ upload.label }}</strong>
                <span class="ops-drawer__pct">
                  {{
                    upload.percent !== null
                      ? `${upload.percent}%`
                      : $t('workspace.opsIndeterminate')
                  }}
                </span>
              </div>
              <div
                class="ops-drawer__track"
                role="progressbar"
                :aria-valuemin="0"
                :aria-valuemax="100"
                :aria-valuenow="upload.percent ?? undefined"
              >
                <div
                  class="ops-drawer__bar"
                  :class="{ 'ops-drawer__bar--indeterminate': upload.indeterminate }"
                  :style="upload.percent !== null ? { width: `${upload.percent}%` } : undefined"
                />
              </div>
              <div class="ops-drawer__card-actions">
                <AppButton
                  size="s"
                  variant="secondary"
                  data-testid="upload-cancel-btn"
                  @click="handleCancelUpload(upload.id)"
                >
                  {{ $t('workspace.opsCancel') }}
                </AppButton>
              </div>
            </article>

            <article
              v-for="op in operations.runningOperations"
              :key="op.id"
              class="ops-drawer__card"
              data-testid="operations-running-item"
            >
              <div class="ops-drawer__card-head">
                <strong class="ops-drawer__card-title">{{ op.title }}</strong>
                <AppBadge variant="primary">{{ op.status }}</AppBadge>
              </div>
              <p
                v-if="op.description"
                class="ops-drawer__card-desc"
              >
                {{ op.description }}
              </p>
              <div
                v-if="typeof op.progress === 'number'"
                class="ops-drawer__track"
                role="progressbar"
                :aria-valuemin="0"
                :aria-valuemax="100"
                :aria-valuenow="op.progress"
              >
                <div
                  class="ops-drawer__bar"
                  :style="{ width: `${Math.max(0, Math.min(100, op.progress))}%` }"
                />
              </div>
              <div
                v-if="op.canCancel !== false"
                class="ops-drawer__card-actions"
              >
                <AppButton
                  size="s"
                  variant="secondary"
                  :loading="busyId === op.id"
                  data-testid="operation-cancel-btn"
                  @click="handleCancelOp(op.id)"
                >
                  {{ $t('workspace.opsCancel') }}
                </AppButton>
              </div>
            </article>
          </section>

          <!-- Undoable -->
          <section
            v-if="operations.undoableOperations.length > 0"
            class="ops-drawer__section"
          >
            <h3 class="ops-drawer__section-title">{{ $t('workspace.opsUndoable') }}</h3>
            <article
              v-for="op in operations.undoableOperations"
              :key="op.id"
              class="ops-drawer__card ops-drawer__card--undoable"
              data-testid="operations-undoable-item"
            >
              <div class="ops-drawer__card-head">
                <strong class="ops-drawer__card-title">{{ op.title }}</strong>
                <AppBadge variant="warning">
                  <Clock
                    :size="12"
                    aria-hidden="true"
                  />
                  {{
                    $t('workspace.opsUndoRemaining', {
                      seconds: undoRemainingFor(op.id, op.undoDeadline),
                    })
                  }}
                </AppBadge>
              </div>
              <p
                v-if="op.description"
                class="ops-drawer__card-desc"
              >
                {{ op.description }}
              </p>
              <div class="ops-drawer__card-actions">
                <AppButton
                  size="s"
                  variant="primary"
                  :loading="busyId === op.id"
                  data-testid="operation-undo-btn"
                  @click="handleUndo(op.id)"
                >
                  <Undo2
                    :size="14"
                    aria-hidden="true"
                  />
                  {{ $t('workspace.opsUndo') }}
                </AppButton>
              </div>
            </article>
          </section>

          <!-- Scheduled / Deferred -->
          <section
            v-if="operations.scheduledOperations.length > 0"
            class="ops-drawer__section"
          >
            <h3 class="ops-drawer__section-title">{{ $t('workspace.opsScheduled') }}</h3>
            <article
              v-for="op in operations.scheduledOperations"
              :key="op.id"
              class="ops-drawer__card"
              data-testid="operations-scheduled-item"
            >
              <div class="ops-drawer__card-head">
                <strong class="ops-drawer__card-title">{{ op.title }}</strong>
                <AppBadge variant="neutral">{{ op.status }}</AppBadge>
              </div>
              <p
                v-if="op.scheduledFor"
                class="ops-drawer__card-desc"
              >
                {{ new Date(op.scheduledFor).toLocaleString() }}
              </p>
              <div class="ops-drawer__card-actions">
                <AppButton
                  size="s"
                  variant="secondary"
                  :loading="busyId === op.id"
                  data-testid="operation-cancel-btn"
                  @click="handleCancelOp(op.id)"
                >
                  {{ $t('workspace.opsCancel') }}
                </AppButton>
              </div>
            </article>
          </section>

          <!-- Failed -->
          <section
            v-if="operations.failedOperations.length > 0 || operations.failedUploads.length > 0"
            class="ops-drawer__section"
          >
            <h3 class="ops-drawer__section-title">{{ $t('workspace.opsFailed') }}</h3>
            <article
              v-for="upload in operations.failedUploads"
              :key="upload.id"
              class="ops-drawer__card ops-drawer__card--failed"
            >
              <div class="ops-drawer__card-head">
                <strong class="ops-drawer__card-title">{{ upload.label }}</strong>
                <AppBadge variant="danger">{{ $t('workspace.opsFailedLabel') }}</AppBadge>
              </div>
              <p
                v-if="upload.error"
                class="ops-drawer__error"
              >
                {{ upload.error }}
              </p>
            </article>
            <article
              v-for="op in operations.failedOperations"
              :key="op.id"
              class="ops-drawer__card ops-drawer__card--failed"
              data-testid="operations-failed-item"
            >
              <div class="ops-drawer__card-head">
                <strong class="ops-drawer__card-title">{{ op.title }}</strong>
                <AppBadge variant="danger">{{ $t('workspace.opsFailedLabel') }}</AppBadge>
              </div>
              <p
                v-if="op.errorMessage"
                class="ops-drawer__error"
              >
                {{ op.errorMessage }}
              </p>
              <div
                v-if="op.canRetry !== false"
                class="ops-drawer__card-actions"
              >
                <AppButton
                  size="s"
                  variant="secondary"
                  :loading="busyId === op.id"
                  data-testid="operation-retry-btn"
                  @click="handleRetryOp(op.id)"
                >
                  <RotateCw
                    :size="14"
                    aria-hidden="true"
                  />
                  {{ $t('workspace.opsRetry') }}
                </AppButton>
              </div>
            </article>
          </section>

          <!-- Completed & History -->
          <section
            v-if="
              operations.completedOperations.length > 0 || operations.finishedUploads.length > 0
            "
            class="ops-drawer__section"
          >
            <div class="ops-drawer__section-head">
              <h3 class="ops-drawer__section-title">{{ $t('workspace.opsCompleted') }}</h3>
              <AppButton
                size="s"
                variant="ghost"
                @click="operations.clearCompleted()"
              >
                {{ $t('workspace.opsClearCompleted') }}
              </AppButton>
            </div>
            <article
              v-for="upload in operations.finishedUploads"
              :key="upload.id"
              class="ops-drawer__card"
            >
              <div class="ops-drawer__card-head">
                <strong class="ops-drawer__card-title">{{ upload.label }}</strong>
                <AppBadge :variant="upload.status === 'cancelled' ? 'neutral' : 'success'">
                  {{
                    upload.status === 'cancelled'
                      ? $t('workspace.opsCancelledLabel')
                      : $t('workspace.opsCompletedLabel')
                  }}
                </AppBadge>
              </div>
            </article>
            <article
              v-for="op in operations.completedOperations"
              :key="op.id"
              class="ops-drawer__card"
            >
              <div class="ops-drawer__card-head">
                <strong class="ops-drawer__card-title">{{ op.title }}</strong>
                <AppBadge :variant="op.status === 'completed' ? 'success' : 'neutral'">
                  {{
                    op.status === 'undone'
                      ? $t('workspace.opsUndoneLabel')
                      : op.status === 'cancelled'
                        ? $t('workspace.opsCancelledLabel')
                        : $t('workspace.opsCompletedLabel')
                  }}
                </AppBadge>
              </div>
            </article>
          </section>
        </div>
      </aside>
    </div>
  </Teleport>
</template>

<style scoped>
.ops-drawer__backdrop {
  position: fixed;
  inset: 0;
  z-index: 960;
  display: flex;
  justify-content: flex-end;
  background: var(--backdrop);
}

.ops-drawer {
  width: min(100vw, 440px);
  height: 100dvh;
  display: flex;
  flex-direction: column;
  background: var(--overlay-surface);
  color: var(--text-1);
  border-left: 1px solid var(--border);
  box-shadow: var(--shadow-modal);
  transition: var(--theme-transition);
}

.ops-drawer__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-12);
  padding: var(--space-16);
  border-bottom: 1px solid var(--border);
}

.ops-drawer__title-wrap {
  min-width: 0;
}

.ops-drawer__title {
  font-size: var(--type-h3-size);
  line-height: var(--type-h3-line);
  font-weight: var(--type-h3-weight);
}

.ops-drawer__subtitle {
  margin-top: var(--space-4);
  color: var(--text-2);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
}

.ops-drawer__header-actions {
  display: flex;
  align-items: center;
  gap: var(--space-4);
  flex: 0 0 auto;
}

.ops-drawer__body {
  flex: 1;
  overflow-y: auto;
  padding: var(--space-16);
  display: flex;
  flex-direction: column;
  gap: var(--space-16);
}

.ops-drawer__section {
  display: flex;
  flex-direction: column;
  gap: var(--space-8);
}

.ops-drawer__section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-8);
}

.ops-drawer__section-title {
  color: var(--text-3);
  font-size: var(--type-overline-label-size);
  line-height: var(--type-overline-label-line);
  font-weight: var(--type-overline-label-weight);
  text-transform: uppercase;
}

.ops-drawer__card {
  display: flex;
  flex-direction: column;
  gap: var(--space-8);
  padding: var(--space-12);
  border-radius: var(--radius-control-m);
  background: var(--surface);
  border: 1px solid var(--border);
  transition: var(--theme-transition);
}

.ops-drawer__card--undoable {
  border-color: var(--accent);
}

.ops-drawer__card--failed {
  border-color: var(--danger);
}

.ops-drawer__card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-8);
}

.ops-drawer__card-title {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-emphasis-weight);
}

.ops-drawer__card-desc {
  color: var(--text-2);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
}

.ops-drawer__pct {
  color: var(--accent-text);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-emphasis-weight);
}

.ops-drawer__track {
  width: 100%;
  height: 6px;
  border-radius: var(--radius-badge-pill);
  background: var(--surface-2);
  overflow: hidden;
}

.ops-drawer__bar {
  height: 100%;
  background: var(--accent);
  border-radius: var(--radius-badge-pill);
  transition: var(--theme-transition);
}

.ops-drawer__bar--indeterminate {
  width: 45%;
  opacity: 0.75;
}

.ops-drawer__card-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-8);
}

.ops-drawer__error {
  color: var(--danger-text);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
}
</style>
