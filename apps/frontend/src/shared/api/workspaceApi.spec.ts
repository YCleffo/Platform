import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/shared/api/errors';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import {
  extractRestoreConflict,
  isConfirmationRequiredError,
  isUndoWindowExpiredError,
  workspaceApi,
} from './workspaceApi';

vi.mock('@/shared/api/core/adminHttp', () => ({
  coreAdminHttp: {
    request: vi.fn(),
  },
}));

describe('workspaceApi', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('requests workspace dashboard with organization header', async () => {
    vi.mocked(coreAdminHttp.request).mockResolvedValueOnce({
      organizationId: 'org-1',
      accountId: 'acc-1',
      roleContext: 'teacher',
      availableModules: ['education'],
      summary: { activeLessons: 3 },
      widgets: [],
      quickActions: [],
      recentActivity: [],
    });

    const res = await workspaceApi.getDashboard('org-1');
    expect(res.roleContext).toBe('teacher');
    expect(coreAdminHttp.request).toHaveBeenCalledWith('/workspace/dashboard', {
      method: 'GET',
      headers: { 'X-Education-Organization': 'org-1' },
      signal: undefined,
    });
  });

  it('sends search params without "all" category', async () => {
    vi.mocked(coreAdminHttp.request).mockResolvedValueOnce({
      query: 'algebra',
      category: 'all',
      total: 1,
      items: [],
      facets: {},
    });

    await workspaceApi.search({ q: 'algebra', category: 'all', scope: 'all', limit: 20 });
    expect(coreAdminHttp.request).toHaveBeenCalledWith('/workspace/search', {
      method: 'GET',
      query: { q: 'algebra', category: undefined, limit: 20, offset: undefined, scope: 'all' },
      headers: undefined,
      signal: undefined,
    });
  });

  it('executes actions, manages operations, trash, and preferences', async () => {
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ ok: true });

    await workspaceApi.executeAction({ actionCode: 'trash.empty', confirmed: true, organizationId: 'org-1' });
    expect(coreAdminHttp.request).toHaveBeenCalledWith('/workspace/actions/execute', {
      method: 'POST',
      body: { actionCode: 'trash.empty', confirmed: true },
      headers: { 'X-Education-Organization': 'org-1' },
      signal: undefined,
    });

    await workspaceApi.undoOperation('op-1');
    expect(coreAdminHttp.request).toHaveBeenCalledWith('/workspace/operations/op-1/undo', {
      method: 'POST',
      body: {},
      headers: undefined,
    });

    await workspaceApi.cancelOperation('op-2');
    expect(coreAdminHttp.request).toHaveBeenCalledWith('/workspace/operations/op-2/cancel', {
      method: 'POST',
      body: {},
      headers: undefined,
    });

    await workspaceApi.retryOperation('op-3');
    expect(coreAdminHttp.request).toHaveBeenCalledWith('/workspace/operations/op-3/retry', {
      method: 'POST',
      body: {},
      headers: undefined,
    });

    await workspaceApi.restoreTrashItem('tr-1', { renameOnConflict: true });
    expect(coreAdminHttp.request).toHaveBeenCalledWith('/workspace/trash/tr-1/restore', {
      method: 'POST',
      body: { renameOnConflict: true },
      headers: undefined,
    });

    await workspaceApi.purgeTrashItem('tr-1');
    expect(coreAdminHttp.request).toHaveBeenCalledWith('/workspace/trash/tr-1', {
      method: 'DELETE',
      headers: undefined,
    });

    await workspaceApi.emptyTrash('org-1');
    expect(coreAdminHttp.request).toHaveBeenCalledWith('/workspace/trash/empty', {
      method: 'POST',
      body: {},
      headers: { 'X-Education-Organization': 'org-1' },
    });

    await workspaceApi.updatePreferences({ defaultScope: 'all' });
    expect(coreAdminHttp.request).toHaveBeenCalledWith('/workspace/preferences', {
      method: 'PUT',
      body: { defaultScope: 'all' },
      headers: undefined,
    });
  });

  it('extracts restore conflict, confirmation required, and undo window expired errors', () => {
    const conflictErr = new ApiError(409, 'restore_conflict', 'Name exists', null, {
      error: 'restore_conflict',
      message: 'Name exists',
      conflictField: 'name',
      existingId: 'file-1',
      suggestedName: 'notes (restored).txt',
    });
    expect(extractRestoreConflict(conflictErr)).toEqual({
      error: 'restore_conflict',
      message: 'Name exists',
      conflictField: 'name',
      existingId: 'file-1',
      suggestedName: 'notes (restored).txt',
    });

    const confirmErr = new ApiError(422, 'confirmation_required', 'Confirmation needed');
    expect(isConfirmationRequiredError(confirmErr)).toBe(true);

    const expiredErr = new ApiError(409, 'undo_window_expired', 'Too late');
    expect(isUndoWindowExpiredError(expiredErr)).toBe(true);
  });
});
