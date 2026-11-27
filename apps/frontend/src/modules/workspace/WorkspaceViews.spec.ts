import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory } from 'vue-router';
import { flushPromises, mount } from '@vue/test-utils';
import { createAppRouter } from '@/app/router';
import { ApiError } from '@/shared/api/errors';
import { i18n } from '@/shared/i18n';
import { workspaceApi } from '@/shared/api/workspaceApi';
import { notificationsApi } from '@/shared/api/notificationsApi';
import TrashView from './TrashView.vue';
import GlobalSearchView from './GlobalSearchView.vue';
import UndoToastHost from './UndoToastHost.vue';
import CommandPalette from './CommandPalette.vue';
import NotificationsCenterView from '@/modules/notifications/NotificationsCenterView.vue';
import { useOperationsCenter } from './useOperationsCenter';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';

vi.mock('@/shared/api/workspaceApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/shared/api/workspaceApi')>();
  return {
    ...actual,
    workspaceApi: {
      getDashboard: vi.fn(),
      search: vi.fn(),
      executeAction: vi.fn(),
      listOperations: vi.fn(),
      undoOperation: vi.fn(),
      cancelOperation: vi.fn(),
      retryOperation: vi.fn(),
      listTrash: vi.fn(),
      restoreTrashItem: vi.fn(),
      purgeTrashItem: vi.fn(),
      emptyTrash: vi.fn(),
    },
  };
});

vi.mock('@/shared/api/notificationsApi', () => ({
  notificationsApi: {
    list: vi.fn(),
    markRead: vi.fn(),
    markAllRead: vi.fn(),
    bulkRead: vi.fn(),
    bulkArchive: vi.fn(),
    getPreferences: vi.fn(),
    updatePreference: vi.fn(),
    registerPushSubscription: vi.fn(),
  },
}));

vi.mock('@/shared/realtime/realtimeClient', () => ({
  realtimeClient: {
    connect: vi.fn().mockResolvedValue(undefined),
    subscribe: vi.fn(() => vi.fn()),
    onAnyEvent: vi.fn(() => vi.fn()),
  },
}));

describe('Workspace views and components', () => {
  let pinia = createPinia();

  beforeEach(() => {
    pinia = createPinia();
    setActivePinia(pinia);
    vi.clearAllMocks();
  });

  it('TrashView handles 409 restore_conflict and retries with renameOnConflict', async () => {
    vi.mocked(workspaceApi.listTrash).mockResolvedValueOnce({
      total: 1,
      items: [
        {
          id: 'trash-1',
          entityType: 'file',
          entityId: 'file-1',
          category: 'files',
          title: 'syllabus.pdf',
          deletedAt: '2026-11-25T10:00:00Z',
          expiresAt: '2026-12-25T10:00:00Z',
        },
      ],
    });
    vi.mocked(workspaceApi.restoreTrashItem)
      .mockRejectedValueOnce(
        new ApiError(409, 'restore_conflict', 'File already exists', null, {
          error: 'restore_conflict',
          message: 'File already exists',
          conflictField: 'name',
          existingId: 'file-existing',
          suggestedName: 'syllabus (restored).pdf',
        }),
      )
      .mockResolvedValueOnce({
        restored: true,
        item: {
          id: 'trash-1',
          entityType: 'file',
          entityId: 'file-1',
          category: 'files',
          title: 'syllabus (restored).pdf',
          deletedAt: '2026-11-25T10:00:00Z',
        },
      });

    const router = createAppRouter(createMemoryHistory());
    const wrapper = mount(TrashView, {
      global: { plugins: [pinia, i18n, router] },
    });
    await flushPromises();

    expect(wrapper.text()).toContain('syllabus.pdf');
    await wrapper.find('[data-testid="trash-restore-btn"]').trigger('click');
    await flushPromises();

    expect(document.body.textContent).toContain('syllabus (restored).pdf');
    const confirmBtn = Array.from(document.body.querySelectorAll('button')).find((b) =>
      b.textContent?.includes('syllabus (restored).pdf'),
    );
    expect(confirmBtn).toBeTruthy();
    confirmBtn?.click();
    await flushPromises();

    expect(workspaceApi.restoreTrashItem).toHaveBeenLastCalledWith('trash-1', {
      renameOnConflict: true,
      organizationId: null,
    });
    wrapper.unmount();
  });

  it('GlobalSearchView searches and renders highlighted snippets', async () => {
    vi.mocked(workspaceApi.search).mockResolvedValueOnce({
      query: 'algebra',
      category: 'all',
      total: 1,
      facets: { lessons: 1 },
      items: [
        {
          id: 'sr-1',
          entityType: 'lesson',
          entityId: 'les-1',
          category: 'lessons',
          title: 'Algebra Basics',
          snippet: 'Linear algebra introduction',
          route: '/lessons/les-1',
        },
      ],
    });

    const auth = useAdminAuthStore();
    auth.status = 'authenticated';
    auth.identity = {
      account: { id: 'acc-1', personId: 'p-1' },
      roles: ['core.superadmin'],
      permissions: [],
      locale: 'en-US',
      timezone: 'UTC',
      sessionId: 's-1',
    };

    const router = createAppRouter(createMemoryHistory());
    await router.push('/search?q=algebra');
    const wrapper = mount(GlobalSearchView, {
      global: { plugins: [pinia, i18n, router] },
    });
    await flushPromises();

    expect(wrapper.findAll('[data-testid="search-result-item"]')).toHaveLength(1);
    expect(wrapper.find('mark').text().toLowerCase()).toBe('algebra');
    wrapper.unmount();
  });

  it('UndoToastHost shows countdown and surfaces 409 undo_window_expired error', async () => {
    vi.mocked(workspaceApi.undoOperation).mockRejectedValueOnce(
      new ApiError(409, 'undo_window_expired', 'Undo window expired'),
    );
    vi.mocked(workspaceApi.listOperations).mockResolvedValueOnce({
      items: [],
      activeCount: 0,
      total: 0,
    });

    const ops = useOperationsCenter();
    ops.upsertOperation({
      id: 'op-1',
      actionCode: 'trash.move',
      title: 'Moved document to trash',
      status: 'undoable',
      canUndo: true,
      undoDeadline: new Date(Date.now() + 9000).toISOString(),
      createdAt: new Date().toISOString(),
      updatedAt: new Date().toISOString(),
    });

    const wrapper = mount(UndoToastHost, {
      global: { plugins: [pinia, i18n] },
    });
    await flushPromises();

    const actionBtn = document.body.querySelector('[data-testid="undo-toast-action"]') as HTMLButtonElement | null;
    expect(actionBtn).toBeTruthy();
    actionBtn?.click();
    await flushPromises();

    expect(document.body.querySelector('[data-testid="undo-toast-error"]')?.textContent).toContain(
      'Undo window expired',
    );
    wrapper.unmount();
  });

  it('CommandPalette opens via Ctrl+K and asks confirmation for dangerous commands', async () => {
    const auth = useAdminAuthStore();
    auth.status = 'authenticated';
    auth.identity = {
      account: { id: 'acc-1', personId: 'p-1' },
      roles: ['core.superadmin'],
      permissions: [],
      locale: 'en-US',
      timezone: 'UTC',
      sessionId: 's-1',
    };

    const router = createAppRouter(createMemoryHistory());
    const wrapper = mount(CommandPalette, {
      global: { plugins: [pinia, i18n, router] },
    });

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true }));
    await flushPromises();

    const dialog = document.body.querySelector('[data-testid="command-palette-dialog"]');
    expect(dialog).toBeTruthy();

    const emptyTrashOption = Array.from(
      document.body.querySelectorAll('[data-testid="command-palette-item"]'),
    ).find((el) => el.textContent?.includes('Empty Trash Permanently')) as HTMLButtonElement | undefined;
    expect(emptyTrashOption).toBeTruthy();
    emptyTrashOption?.click();
    await flushPromises();

    expect(workspaceApi.executeAction).not.toHaveBeenCalled();
    expect(document.body.textContent).toContain('Empty trash permanently?');
    wrapper.unmount();
  });

  it('NotificationsCenterView shows unavailable badge, bulk actions, and honest push status', async () => {
    vi.mocked(notificationsApi.list).mockResolvedValueOnce({
      unreadCount: 1,
      hasMore: false,
      offset: 0,
      pushStatus: {
        configured: false,
        status: 'not_configured',
        reason: 'VAPID keys missing',
      },
      items: [
        {
          id: 'n-1',
          category: 'homework',
          kind: 'homework_deleted',
          title: 'Deleted assignment',
          body: 'This target was moved to trash',
          roomId: null,
          messageId: null,
          resourceType: 'homework',
          resourceId: 'hw-1',
          resourcePath: '/homework/hw-1',
          resourceAvailable: false,
          parentStudentId: null,
          priority: 'normal',
          payload: {},
          createdAt: '2026-11-25T10:00:00Z',
          readAt: null,
        },
      ],
    });
    vi.mocked(notificationsApi.bulkRead).mockResolvedValueOnce({ updatedCount: 1, unreadCount: 0 });

    const router = createAppRouter(createMemoryHistory());
    const wrapper = mount(NotificationsCenterView, {
      props: { organizationId: 'org-1', personId: 'person-1' },
      global: { plugins: [pinia, i18n, router] },
    });
    await flushPromises();

    expect(wrapper.find('[data-testid="push-status-panel"]').text()).toContain('Not configured');
    expect(wrapper.find('[data-testid="notif-unavailable-badge"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="notif-open-target"]').attributes('disabled')).toBeDefined();

    await wrapper.find('[data-testid="notif-item-checkbox"]').setValue(true);
    await wrapper.find('[data-testid="notif-bulk-read"]').trigger('click');
    await flushPromises();

    expect(notificationsApi.bulkRead).toHaveBeenCalledWith('org-1', {
      ids: ['n-1'],
      category: undefined,
    });
    wrapper.unmount();
  });
});
