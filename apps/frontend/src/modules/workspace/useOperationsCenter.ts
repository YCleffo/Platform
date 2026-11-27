import { computed, getCurrentInstance, reactive, ref } from 'vue';
import { defineStore, getActivePinia } from 'pinia';
import { i18n } from '@/shared/i18n';
import { coreAdminSession } from '@/core/admin/session';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { mediaApi, type UploadProgressInfo } from '@/shared/api/mediaApi';
import {
  isUndoWindowExpiredError,
  normalizeWorkspaceOperation,
  workspaceApi,
  type ExecuteWorkspaceActionPayload,
  type ExecuteWorkspaceActionResponse,
  type WorkspaceOperation,
} from '@/shared/api/workspaceApi';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { realtimeClient } from '@/shared/realtime/realtimeClient';

export type ClientUploadStatus = 'preparing' | 'uploading' | 'completed' | 'cancelled' | 'failed';

export interface ClientUploadEntry {
  id: string;
  mediaId?: string;
  organizationId?: string;
  label: string;
  loaded: number;
  total: number;
  percent: number | null;
  indeterminate: boolean;
  status: ClientUploadStatus;
  error?: string;
  createdAt: string;
  updatedAt: string;
  abortController?: AbortController;
  onCancel?: () => void | Promise<void>;
}

export interface UndoToastEntry {
  operationId: string;
  title: string;
  description?: string;
  undoDeadline: string;
  remainingSeconds: number;
  busy: boolean;
  error: string | null;
  expired: boolean;
}

export interface RegisterUploadInput {
  id?: string;
  mediaId?: string;
  organizationId?: string;
  label: string;
  total?: number;
  abortController?: AbortController;
  onCancel?: () => void | Promise<void>;
}

export interface UploadTrackerHandle {
  id: string;
  setMediaId: (mediaId: string) => void;
  updateProgress: (info: Partial<UploadProgressInfo> & { loaded: number; total?: number }) => void;
  markCompleted: () => void;
  markCancelled: () => void;
  markFailed: (errorMessage: string) => void;
}

const DEFAULT_UNDO_WINDOW_MS = 10_000;

function computeRemainingSeconds(deadlineIso: string | null | undefined, nowMs: number): number {
  if (!deadlineIso) return 0;
  const deadlineMs = Date.parse(deadlineIso);
  if (!Number.isFinite(deadlineMs)) return 0;
  return Math.max(0, Math.ceil((deadlineMs - nowMs) / 1000));
}

function isRunningStatus(status: WorkspaceOperation['status']): boolean {
  return status === 'pending' || status === 'queued' || status === 'running';
}

function isScheduledStatus(status: WorkspaceOperation['status']): boolean {
  return status === 'scheduled' || status === 'deferred';
}

function isUndoableOp(op: WorkspaceOperation, nowMs: number): boolean {
  if (op.status === 'undone' || op.status === 'cancelled' || op.status === 'failed') return false;
  const flag = op.status === 'undoable' || op.canUndo === true;
  if (!flag) return false;
  if (!op.undoDeadline) return true;
  return computeRemainingSeconds(op.undoDeadline, nowMs) > 0;
}

function createOperationsCenterState() {
  const operations = ref<WorkspaceOperation[]>([]);
  const clientUploads = ref<ClientUploadEntry[]>([]);
  const undoQueue = ref<UndoToastEntry[]>([]);
  const drawerOpen = ref(false);
  const commandPaletteOpen = ref(false);
  const loading = ref(false);
  const error = ref('');
  const organizationId = ref<string | null>(null);
  const organizations = ref<Array<{ id: string; name: string }>>([]);
  const nowMs = ref(Date.now());

  let countdownTimer: ReturnType<typeof setInterval> | null = null;
  let realtimeUnsub: (() => void) | null = null;
  let uploadSeq = 0;

  function ensureTicker(): void {
    const hasUndoable = undoQueue.value.some((u) => !u.expired && u.remainingSeconds > 0);
    if (hasUndoable && countdownTimer === null) {
      countdownTimer = setInterval(() => {
        nowMs.value = Date.now();
        let anyActive = false;
        for (const item of undoQueue.value) {
          if (item.expired) continue;
          const next = computeRemainingSeconds(item.undoDeadline, nowMs.value);
          item.remainingSeconds = next;
          if (next > 0) {
            anyActive = true;
          } else if (!item.busy) {
            item.expired = true;
          }
        }
        if (!anyActive) {
          stopTicker();
        }
      }, 250);
    } else if (!hasUndoable) {
      stopTicker();
    }
  }

  function stopTicker(): void {
    if (countdownTimer !== null) {
      clearInterval(countdownTimer);
      countdownTimer = null;
    }
  }

  function syncUndoEntryFromOp(op: WorkspaceOperation): void {
    const deadline =
      op.undoDeadline ??
      (op.status === 'undoable' || op.canUndo
        ? new Date(Date.now() + DEFAULT_UNDO_WINDOW_MS).toISOString()
        : null);

    if (!isUndoableOp({ ...op, undoDeadline: deadline }, nowMs.value) || !deadline) {
      const idx = undoQueue.value.findIndex((entry) => entry.operationId === op.id);
      if (idx >= 0 && !undoQueue.value[idx]!.error && !undoQueue.value[idx]!.busy) {
        undoQueue.value.splice(idx, 1);
      }
      ensureTicker();
      return;
    }

    const remaining = computeRemainingSeconds(deadline, nowMs.value);
    const existing = undoQueue.value.find((entry) => entry.operationId === op.id);
    if (existing) {
      existing.title = op.title;
      existing.description = op.description ?? undefined;
      existing.undoDeadline = deadline;
      existing.remainingSeconds = remaining;
      existing.expired = remaining <= 0;
    } else {
      undoQueue.value.unshift(
        reactive({
          operationId: op.id,
          title: op.title,
          description: op.description ?? undefined,
          undoDeadline: deadline,
          remainingSeconds: remaining,
          busy: false,
          error: null,
          expired: remaining <= 0,
        }),
      );
    }
    ensureTicker();
  }

  function upsertOperation(op: WorkspaceOperation): void {
    const idx = operations.value.findIndex((item) => item.id === op.id);
    if (idx >= 0) {
      operations.value[idx] = op;
    } else {
      operations.value.unshift(op);
    }
    syncUndoEntryFromOp(op);
  }

  function setOrganizationId(nextOrgId: string | null): void {
    const changed = nextOrgId !== organizationId.value;
    organizationId.value = nextOrgId;
    if (changed && nextOrgId && coreAdminSession.accessToken()) {
      void realtimeClient.connect(nextOrgId).catch(() => undefined);
      void syncOperations().catch(() => undefined);
    }
  }

  async function ensureOrganization(forceReload = false): Promise<string | null> {
    if (organizationId.value && !forceReload && organizations.value.length > 0) {
      return organizationId.value;
    }
    if (!coreAdminSession.accessToken()) {
      return organizationId.value;
    }
    try {
      const res = await coreAdminHttp.request<{
        items: Array<{ id: string; name: string }>;
        personId: string;
      }>('/chat/organizations', { method: 'GET' });
      organizations.value = Array.isArray(res?.items) ? res.items : [];
      if (
        (!organizationId.value ||
          !organizations.value.some((org) => org.id === organizationId.value)) &&
        organizations.value[0]
      ) {
        organizationId.value = organizations.value[0].id;
      }
    } catch {
      // Safe fallback in isolated unit tests or unauthenticated shells
    }
    return organizationId.value;
  }

  async function syncOperations(params?: { status?: string; limit?: number; offset?: number }): Promise<void> {
    loading.value = true;
    error.value = '';
    try {
      await ensureOrganization();
      const res = await workspaceApi.listOperations({
        ...params,
        organizationId: organizationId.value,
      });
      operations.value = res.items ?? [];
      nowMs.value = Date.now();
      for (const op of operations.value) {
        syncUndoEntryFromOp(op);
      }
    } catch (caught) {
      error.value = resolveErrorMessage(caught);
    } finally {
      loading.value = false;
    }
  }

  function registerUpload(input: RegisterUploadInput): UploadTrackerHandle {
    uploadSeq += 1;
    const id = input.id ?? `upload-${Date.now()}-${uploadSeq}`;
    const now = new Date().toISOString();
    const total = input.total ?? 0;
    const entry = reactive<ClientUploadEntry>({
      id,
      mediaId: input.mediaId,
      organizationId: input.organizationId,
      label: input.label,
      loaded: 0,
      total,
      percent: total > 0 ? 0 : null,
      indeterminate: total <= 0,
      status: 'preparing',
      createdAt: now,
      updatedAt: now,
      abortController: input.abortController,
      onCancel: input.onCancel,
    });
    clientUploads.value.unshift(entry);

    return {
      id,
      setMediaId(mediaId: string) {
        entry.mediaId = mediaId;
        entry.updatedAt = new Date().toISOString();
      },
      updateProgress(info) {
        if (entry.status === 'cancelled' || entry.status === 'completed' || entry.status === 'failed') {
          return;
        }
        entry.status = 'uploading';
        entry.loaded = info.loaded;
        const knownTotal = info.total ?? entry.total;
        entry.total = knownTotal;
        if (info.lengthComputable === false || knownTotal <= 0) {
          entry.percent = info.percent ?? null;
          entry.indeterminate = entry.percent === null;
        } else {
          const pct = info.percent ?? Math.max(0, Math.min(100, Math.round((info.loaded / knownTotal) * 100)));
          entry.percent = pct;
          entry.indeterminate = false;
        }
        entry.updatedAt = new Date().toISOString();
      },
      markCompleted() {
        if (entry.status === 'cancelled') return;
        entry.status = 'completed';
        entry.percent = 100;
        entry.indeterminate = false;
        entry.loaded = entry.total || entry.loaded;
        entry.updatedAt = new Date().toISOString();
      },
      markCancelled() {
        entry.status = 'cancelled';
        entry.indeterminate = false;
        entry.updatedAt = new Date().toISOString();
      },
      markFailed(errorMessage: string) {
        if (entry.status === 'cancelled') return;
        entry.status = 'failed';
        entry.error = errorMessage;
        entry.indeterminate = false;
        entry.updatedAt = new Date().toISOString();
      },
    };
  }

  async function cancelUpload(uploadId: string): Promise<boolean> {
    const entry = clientUploads.value.find((item) => item.id === uploadId);
    if (!entry) return false;
    if (entry.status === 'completed' || entry.status === 'cancelled') return false;

    entry.status = 'cancelled';
    entry.indeterminate = false;
    entry.updatedAt = new Date().toISOString();

    try {
      entry.abortController?.abort();
    } catch {
      // Ignore abort errors
    }

    if (entry.onCancel) {
      try {
        await entry.onCancel();
      } catch {
        // Best-effort custom cleanup
      }
    }

    if (entry.mediaId) {
      try {
        await mediaApi.cancelUpload(entry.mediaId, entry.organizationId ?? organizationId.value ?? undefined);
      } catch {
        // Server cancel is best-effort if upload wasn't confirmed or already expired
      }
    }
    return true;
  }

  function dismissUpload(uploadId: string): void {
    clientUploads.value = clientUploads.value.filter((item) => item.id !== uploadId);
  }

  function clearCompleted(): void {
    clientUploads.value = clientUploads.value.filter(
      (item) => item.status === 'preparing' || item.status === 'uploading',
    );
    operations.value = operations.value.filter(
      (op) =>
        isRunningStatus(op.status) ||
        isScheduledStatus(op.status) ||
        op.status === 'failed' ||
        isUndoableOp(op, nowMs.value),
    );
  }

  async function undoOperation(
    operationId: string,
  ): Promise<{ ok: boolean; restored?: boolean; expired?: boolean; message?: string }> {
    const toastEntry = undoQueue.value.find((item) => item.operationId === operationId);
    if (toastEntry) {
      toastEntry.busy = true;
      toastEntry.error = null;
    }

    try {
      const res = await workspaceApi.undoOperation(operationId, organizationId.value);
      upsertOperation(res.operation);
      undoQueue.value = undoQueue.value.filter((item) => item.operationId !== operationId);
      ensureTicker();
      return { ok: true, restored: res.restored };
    } catch (caught) {
      const expired = isUndoWindowExpiredError(caught);
      const message = expired
        ? String(i18n.global.t('workspace.opsUndoExpired'))
        : resolveErrorMessage(caught);
      if (toastEntry) {
        toastEntry.busy = false;
        toastEntry.expired = expired;
        toastEntry.error = message;
      }
      if (expired) {
        const op = operations.value.find((item) => item.id === operationId);
        if (op) {
          op.canUndo = false;
          if (op.status === 'undoable') op.status = 'completed';
        }
      }
      void syncOperations().catch(() => undefined);
      return { ok: false, expired, message };
    }
  }

  function dismissUndo(operationId: string): void {
    undoQueue.value = undoQueue.value.filter((item) => item.operationId !== operationId);
    ensureTicker();
  }

  async function cancelOperation(operationId: string): Promise<WorkspaceOperation | null> {
    const res = await workspaceApi.cancelOperation(operationId, organizationId.value);
    upsertOperation(res.operation);
    return res.operation;
  }

  async function retryOperation(operationId: string): Promise<WorkspaceOperation | null> {
    const res = await workspaceApi.retryOperation(operationId, organizationId.value);
    upsertOperation(res.operation);
    return res.operation;
  }

  async function executeAction(
    payload: ExecuteWorkspaceActionPayload,
  ): Promise<ExecuteWorkspaceActionResponse> {
    const res = await workspaceApi.executeAction({
      ...payload,
      organizationId: payload.organizationId ?? organizationId.value ?? undefined,
    });
    if (res.operation) {
      upsertOperation(res.operation);
    }
    return res;
  }

  function initRealtime(): () => void {
    if (realtimeUnsub) return realtimeUnsub;
    realtimeUnsub = realtimeClient.onAnyEvent((ev) => {
      if (ev.eventType === 'workspace.operation.updated') {
        const raw = ev.payload.operation ?? ev.payload;
        if (raw && typeof raw === 'object' && 'id' in raw && 'status' in raw) {
          upsertOperation(normalizeWorkspaceOperation(raw));
        }
      }
    });
    return realtimeUnsub;
  }

  function reset(): void {
    stopTicker();
    operations.value = [];
    clientUploads.value = [];
    undoQueue.value = [];
    error.value = '';
    loading.value = false;
  }

  const runningUploads = computed(() =>
    clientUploads.value.filter((u) => u.status === 'preparing' || u.status === 'uploading'),
  );
  const failedUploads = computed(() => clientUploads.value.filter((u) => u.status === 'failed'));
  const finishedUploads = computed(() =>
    clientUploads.value.filter((u) => u.status === 'completed' || u.status === 'cancelled'),
  );

  const runningOperations = computed(() =>
    operations.value.filter((op) => isRunningStatus(op.status)),
  );
  const undoableOperations = computed(() =>
    operations.value.filter((op) => isUndoableOp(op, nowMs.value)),
  );
  const scheduledOperations = computed(() =>
    operations.value.filter((op) => isScheduledStatus(op.status)),
  );
  const failedOperations = computed(() =>
    operations.value.filter((op) => op.status === 'failed'),
  );
  const completedOperations = computed(() =>
    operations.value.filter(
      (op) =>
        (op.status === 'completed' && !isUndoableOp(op, nowMs.value)) ||
        op.status === 'cancelled' ||
        op.status === 'undone',
    ),
  );

  const activeBadgeCount = computed(
    () =>
      runningUploads.value.length +
      runningOperations.value.length +
      undoableOperations.value.length,
  );

  return {
    operations,
    clientUploads,
    undoQueue,
    drawerOpen,
    commandPaletteOpen,
    loading,
    error,
    organizationId,
    organizations,
    runningUploads,
    failedUploads,
    finishedUploads,
    runningOperations,
    undoableOperations,
    scheduledOperations,
    failedOperations,
    completedOperations,
    activeBadgeCount,
    setOrganizationId,
    ensureOrganization,
    syncOperations,
    upsertOperation,
    registerUpload,
    cancelUpload,
    dismissUpload,
    clearCompleted,
    undoOperation,
    dismissUndo,
    cancelOperation,
    retryOperation,
    executeAction,
    initRealtime,
    reset,
  };
}

export const useOperationsCenterStore = defineStore('operationsCenter', createOperationsCenterState);

let standaloneInstance: ReturnType<typeof useOperationsCenterStore> | null = null;

/**
 * Returns the Pinia Operations Center store when Pinia is active,
 * or a standalone reactive singleton in isolated unit tests without Pinia.
 */
export function useOperationsCenter(): ReturnType<typeof useOperationsCenterStore> {
  const vm = getCurrentInstance();
  const hasAppPinia = vm
    ? Boolean((vm.appContext.config.globalProperties as Record<string, unknown> | undefined)?.$pinia)
    : Boolean(getActivePinia());
  if (hasAppPinia) {
    return useOperationsCenterStore();
  }
  if (!standaloneInstance) {
    standaloneInstance = reactive(
      createOperationsCenterState(),
    ) as unknown as ReturnType<typeof useOperationsCenterStore>;
  }
  return standaloneInstance;
}
