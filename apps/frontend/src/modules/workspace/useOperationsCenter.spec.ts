import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { ApiError } from '@/shared/api/errors';
import { mediaApi } from '@/shared/api/mediaApi';
import { workspaceApi } from '@/shared/api/workspaceApi';
import { useOperationsCenter } from './useOperationsCenter';

vi.mock('@/shared/api/workspaceApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/shared/api/workspaceApi')>();
  return {
    ...actual,
    workspaceApi: {
      listOperations: vi.fn(),
      undoOperation: vi.fn(),
      cancelOperation: vi.fn(),
      retryOperation: vi.fn(),
      executeAction: vi.fn(),
    },
  };
});

vi.mock('@/shared/api/mediaApi', () => ({
  mediaApi: {
    cancelUpload: vi.fn(),
  },
}));

describe('useOperationsCenter', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
  });

  it('registers upload progress and cancels both AbortController and mediaApi.cancelUpload', async () => {
    vi.mocked(mediaApi.cancelUpload).mockResolvedValueOnce({
      asset: { id: 'media-1', status: 'cancelled' } as never,
      cancelled: true,
    });

    const store = useOperationsCenter();
    const controller = new AbortController();
    const tracker = store.registerUpload({
      label: 'lecture.mp4',
      total: 1000,
      organizationId: 'org-1',
      abortController: controller,
    });
    tracker.setMediaId('media-1');
    tracker.updateProgress({ loaded: 400, total: 1000, lengthComputable: true });

    expect(store.runningUploads).toHaveLength(1);
    expect(store.runningUploads[0]?.percent).toBe(40);
    expect(store.activeBadgeCount).toBe(1);

    const cancelled = await store.cancelUpload(tracker.id);
    expect(cancelled).toBe(true);
    expect(controller.signal.aborted).toBe(true);
    expect(mediaApi.cancelUpload).toHaveBeenCalledWith('media-1', 'org-1');
    expect(store.runningUploads).toHaveLength(0);
    expect(store.finishedUploads[0]?.status).toBe('cancelled');
  });

  it('handles 409 undo_window_expired honestly without pretending undo succeeded', async () => {
    vi.mocked(workspaceApi.undoOperation).mockRejectedValueOnce(
      new ApiError(409, 'undo_window_expired', 'Undo window expired'),
    );
    vi.mocked(workspaceApi.listOperations).mockResolvedValueOnce({
      items: [],
      activeCount: 0,
      total: 0,
    });

    const store = useOperationsCenter();
    store.upsertOperation({
      id: 'op-undo-1',
      actionCode: 'file.trash',
      title: 'Moved file to trash',
      status: 'undoable',
      canUndo: true,
      undoDeadline: new Date(Date.now() + 8000).toISOString(),
      createdAt: new Date().toISOString(),
      updatedAt: new Date().toISOString(),
    });

    expect(store.undoQueue).toHaveLength(1);
    const result = await store.undoOperation('op-undo-1');
    expect(result.ok).toBe(false);
    expect(result.expired).toBe(true);
    expect(store.undoQueue[0]?.expired).toBe(true);
    expect(store.undoQueue[0]?.error).toBeTruthy();
  });
});
