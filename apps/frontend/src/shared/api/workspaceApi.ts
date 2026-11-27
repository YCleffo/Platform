import { ApiError } from '@/shared/api/errors';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';

export interface WorkspaceSummaryItem {
  key: string;
  label: string;
  value: number | string;
  route?: string;
  badge?: string;
  tone?: 'default' | 'primary' | 'success' | 'warning' | 'danger';
}

export interface WorkspaceWidgetItem {
  id: string;
  title: string;
  subtitle?: string;
  route?: string;
  status?: string;
  updatedAt?: string;
  dueAt?: string;
  badge?: string;
  metadata?: Record<string, unknown>;
}

export interface WorkspaceDashboardWidget {
  id: string;
  kind: string;
  title: string;
  description?: string;
  route?: string;
  emptyText?: string;
  items?: WorkspaceWidgetItem[];
}

export interface WorkspaceQuickAction {
  id: string;
  actionCode?: string;
  title: string;
  description?: string;
  route?: string;
  permission?: string;
  module?: string;
  dangerous?: boolean;
  icon?: string;
}

export interface WorkspaceActivityEntry {
  id: string;
  title: string;
  subtitle?: string;
  category?: string;
  route?: string;
  createdAt: string;
  status?: string;
}

export interface WorkspaceDashboardResponse {
  organizationId: string | null;
  accountId: string;
  roleContext: string;
  availableModules: string[];
  summary: Record<string, number | string> | WorkspaceSummaryItem[];
  widgets: WorkspaceDashboardWidget[];
  quickActions: WorkspaceQuickAction[];
  recentActivity: WorkspaceActivityEntry[];
}

export type WorkspaceSearchCategory =
  | 'all'
  | 'users'
  | 'orgs'
  | 'students'
  | 'teachers'
  | 'groups'
  | 'lessons'
  | 'homework'
  | 'tests'
  | 'documents'
  | 'files'
  | 'chats'
  | 'messages'
  | 'devices'
  | 'sections';

export type WorkspaceSearchScope = 'current_org' | 'all';

export interface WorkspaceSearchItem {
  id: string;
  entityType: string;
  entityId: string;
  category: string;
  title: string;
  subtitle?: string | null;
  snippet?: string | null;
  route: string;
  module?: string | null;
  organizationId?: string | null;
  updatedAt?: string | null;
  score?: number;
  metadata?: Record<string, unknown>;
}

export interface WorkspaceSearchResponse {
  query: string;
  category: string;
  total: number;
  items: WorkspaceSearchItem[];
  facets: Record<string, number>;
}

export type WorkspaceOperationStatus =
  | 'pending'
  | 'queued'
  | 'running'
  | 'undoable'
  | 'scheduled'
  | 'deferred'
  | 'completed'
  | 'failed'
  | 'cancelled'
  | 'undone';

export interface WorkspaceOperation {
  id: string;
  actionCode: string;
  title: string;
  description?: string | null;
  status: WorkspaceOperationStatus;
  progress?: number | null;
  canUndo?: boolean;
  undoDeadline?: string | null;
  canCancel?: boolean;
  canRetry?: boolean;
  errorMessage?: string | null;
  errorCode?: string | null;
  scheduledFor?: string | null;
  entityType?: string | null;
  entityId?: string | null;
  organizationId?: string | null;
  createdAt: string;
  updatedAt: string;
  completedAt?: string | null;
  metadata?: Record<string, unknown>;
}

export interface WorkspaceOperationsResponse {
  items: WorkspaceOperation[];
  activeCount: number;
  total: number;
}

export interface ExecuteWorkspaceActionPayload {
  actionCode: string;
  targetType?: string;
  targetId?: string;
  organizationId?: string;
  confirmed?: boolean;
  params?: Record<string, unknown>;
}

export interface ExecuteWorkspaceActionResponse {
  operation: WorkspaceOperation;
  result?: unknown;
  undoToken?: string;
}

export interface TrashItem {
  id: string;
  entityType: string;
  entityId: string;
  category: string;
  title: string;
  subtitle?: string | null;
  originalPath?: string | null;
  organizationId?: string | null;
  deletedAt: string;
  expiresAt?: string | null;
  deletedBy?: string | null;
  canRestore?: boolean;
  metadata?: Record<string, unknown>;
}

export interface TrashListResponse {
  items: TrashItem[];
  total: number;
}

export interface RestoreConflictDetails {
  error: string;
  message: string;
  conflictField?: string;
  existingId?: string;
  suggestedName?: string;
}

export interface WorkspacePreferences {
  theme?: string;
  locale?: string;
  defaultScope?: WorkspaceSearchScope;
  sidebarCollapsed?: boolean;
  compactMode?: boolean;
  notificationsGrouped?: boolean;
  recentCommands?: string[];
  [key: string]: unknown;
}

function orgHeaders(organizationId?: string | null): Record<string, string> | undefined {
  return organizationId ? { 'X-Education-Organization': organizationId } : undefined;
}

function normalizeWorkspaceRoute(route: unknown): string | undefined {
  if (typeof route !== 'string' || route === '') return undefined;
  if (route === '/workspace/search') return '/search';
  if (route === '/workspace/trash') return '/trash';
  if (route === '/workspace/operations') return '/workspace';
  return route;
}

function asObject(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
}

export function normalizeWorkspaceOperation(rawInput: unknown): WorkspaceOperation {
  const envelope = asObject(rawInput);
  const raw = envelope.operation && typeof envelope.operation === 'object' ? asObject(envelope.operation) : envelope;
  const status = (typeof raw.status === 'string' ? raw.status : 'pending') as WorkspaceOperationStatus;
  const actionCode =
    typeof raw.actionCode === 'string'
      ? raw.actionCode
      : typeof raw.actionKey === 'string'
        ? raw.actionKey
        : '';
  return {
    ...(raw as Partial<WorkspaceOperation>),
    id: String(raw.id ?? ''),
    actionCode,
    title: typeof raw.title === 'string' && raw.title !== '' ? raw.title : actionCode,
    description: typeof raw.description === 'string' ? raw.description : null,
    status,
    progress: typeof raw.progress === 'number' ? raw.progress : null,
    canUndo: typeof raw.canUndo === 'boolean' ? raw.canUndo : status === 'undoable',
    undoDeadline: typeof raw.undoDeadline === 'string' ? raw.undoDeadline : null,
    canCancel:
      typeof raw.canCancel === 'boolean'
        ? raw.canCancel
        : status === 'pending' || status === 'queued' || status === 'running' || status === 'scheduled' || status === 'deferred',
    canRetry: typeof raw.canRetry === 'boolean' ? raw.canRetry : status === 'failed' || status === 'cancelled',
    errorMessage: typeof raw.errorMessage === 'string' ? raw.errorMessage : null,
    errorCode: typeof raw.errorCode === 'string' ? raw.errorCode : null,
    scheduledFor:
      typeof raw.scheduledFor === 'string'
        ? raw.scheduledFor
        : typeof raw.executeAt === 'string'
          ? raw.executeAt
          : null,
    entityType: typeof raw.entityType === 'string' ? raw.entityType : null,
    entityId: typeof raw.entityId === 'string' ? raw.entityId : null,
    organizationId: typeof raw.organizationId === 'string' ? raw.organizationId : null,
    createdAt: typeof raw.createdAt === 'string' ? raw.createdAt : new Date().toISOString(),
    updatedAt: typeof raw.updatedAt === 'string' ? raw.updatedAt : new Date().toISOString(),
    completedAt:
      typeof raw.completedAt === 'string'
        ? raw.completedAt
        : typeof raw.finalizedAt === 'string'
          ? raw.finalizedAt
          : null,
  };
}

function normalizeTrashCategory(entityType: string): string {
  switch (entityType) {
    case 'storage.file':
      return 'files';
    case 'media.asset':
      return 'documents';
    case 'activity.item':
      return 'tests';
    case 'education.homework':
      return 'homework';
    default:
      return entityType;
  }
}

function normalizeTrashItem(rawInput: unknown): TrashItem {
  const raw = asObject(rawInput);
  const entityType = typeof raw.entityType === 'string' ? raw.entityType : 'files';
  return {
    ...(raw as Partial<TrashItem>),
    id: String(raw.id ?? ''),
    entityType,
    entityId: String(raw.entityId ?? ''),
    category: typeof raw.category === 'string' ? raw.category : normalizeTrashCategory(entityType),
    title: typeof raw.title === 'string' ? raw.title : String(raw.entityId ?? ''),
    subtitle: typeof raw.subtitle === 'string' ? raw.subtitle : null,
    originalPath: typeof raw.originalPath === 'string' ? raw.originalPath : null,
    organizationId: typeof raw.organizationId === 'string' ? raw.organizationId : null,
    deletedAt: typeof raw.deletedAt === 'string' ? raw.deletedAt : new Date().toISOString(),
    expiresAt: typeof raw.expiresAt === 'string' ? raw.expiresAt : null,
    deletedBy:
      typeof raw.deletedBy === 'string'
        ? raw.deletedBy
        : typeof raw.deletedByAccountId === 'string'
          ? raw.deletedByAccountId
          : null,
    canRestore:
      typeof raw.canRestore === 'boolean'
        ? raw.canRestore
        : raw.restoreStatus === undefined || raw.restoreStatus === 'trashed',
  };
}

function normalizeDashboard(rawInput: unknown): WorkspaceDashboardResponse {
  const raw = asObject(rawInput);
  let roleContext = 'member';
  if (typeof raw.roleContext === 'string') {
    roleContext = raw.roleContext;
  } else if (raw.roleContext && typeof raw.roleContext === 'object') {
    const rc = asObject(raw.roleContext);
    const edu = Array.isArray(rc.education) ? (rc.education as string[]) : [];
    const roles = Array.isArray(rc.roles) ? (rc.roles as string[]) : [];
    if (edu.length > 0) roleContext = edu.join(', ');
    else if (rc.isSuperadmin) roleContext = 'superadmin';
    else if (rc.isOrgAdmin) roleContext = 'org_admin';
    else if (roles.length > 0) roleContext = roles.join(', ');
  }

  const rawActions = Array.isArray(raw.quickActions) ? raw.quickActions : [];
  const quickActions: WorkspaceQuickAction[] = rawActions.map((entry, idx) => {
    const a = asObject(entry);
    return {
      ...(a as Partial<WorkspaceQuickAction>),
      id: String(a.id ?? a.key ?? `qa-${idx}`),
      title: String(a.title ?? a.label ?? a.key ?? ''),
      route: normalizeWorkspaceRoute(a.route),
    };
  });

  const rawWidgets = Array.isArray(raw.widgets) ? raw.widgets : [];
  const widgets: WorkspaceDashboardWidget[] = rawWidgets.map((entry, idx) => {
    const w = asObject(entry);
    let items = Array.isArray(w.items) ? (w.items as WorkspaceWidgetItem[]) : undefined;
    if (!items && w.data && typeof w.data === 'object') {
      const dataObj = asObject(w.data);
      if (Array.isArray(dataObj.items)) {
        items = (dataObj.items as unknown[]).map((sub, subIdx) => {
          const s = asObject(sub);
          return {
            id: String(s.id ?? `item-${subIdx}`),
            title: String(s.title ?? s.actionKey ?? s.name ?? s.id ?? ''),
            subtitle: typeof s.subtitle === 'string' ? s.subtitle : typeof s.entityType === 'string' ? s.entityType : undefined,
            route: normalizeWorkspaceRoute(s.route),
            status: typeof s.status === 'string' ? s.status : undefined,
          };
        });
      }
    }
    return {
      ...(w as Partial<WorkspaceDashboardWidget>),
      id: String(w.id ?? `widget-${idx}`),
      kind: String(w.kind ?? w.module ?? 'card'),
      title: String(w.title ?? ''),
      route: normalizeWorkspaceRoute(w.route),
      items,
    };
  });

  const rawRecent = Array.isArray(raw.recentActivity) ? raw.recentActivity : [];
  const recentActivity: WorkspaceActivityEntry[] = rawRecent.map((entry, idx) => {
    const r = asObject(entry);
    return {
      ...(r as Partial<WorkspaceActivityEntry>),
      id: String(r.id ?? `act-${idx}`),
      title: String(r.title ?? ''),
      category: typeof r.category === 'string' ? r.category : typeof r.module === 'string' ? r.module : undefined,
      route: normalizeWorkspaceRoute(r.route),
      createdAt: String(r.createdAt ?? r.occurredAt ?? new Date().toISOString()),
    };
  });

  return {
    organizationId: typeof raw.organizationId === 'string' ? raw.organizationId : null,
    accountId: String(raw.accountId ?? ''),
    roleContext,
    availableModules: Array.isArray(raw.availableModules) ? (raw.availableModules as string[]) : [],
    summary: (raw.summary as WorkspaceDashboardResponse['summary']) ?? {},
    widgets,
    quickActions,
    recentActivity,
  };
}

function normalizeSearchResponse(rawInput: unknown): WorkspaceSearchResponse {
  const raw = asObject(rawInput);
  const facetsRaw = asObject(raw.facets) as Record<string, number>;
  const facets: Record<string, number> = { ...facetsRaw };
  if (typeof facets.people === 'number' && facets.users === undefined) facets.users = facets.people;
  if (typeof facets.organizations === 'number' && facets.orgs === undefined) facets.orgs = facets.organizations;
  if (typeof facets.activities === 'number' && facets.tests === undefined) facets.tests = facets.activities;
  return {
    query: String(raw.query ?? ''),
    category: String(raw.category ?? 'all'),
    total: typeof raw.total === 'number' ? raw.total : 0,
    items: Array.isArray(raw.items) ? (raw.items as WorkspaceSearchItem[]) : [],
    facets,
  };
}

export function extractRestoreConflict(error: unknown): RestoreConflictDetails | null {
  if (!(error instanceof ApiError) || error.status !== 409) return null;
  const meta = error.meta ?? {};
  const code = typeof meta.error === 'string' ? meta.error : error.code;
  if (code !== 'restore_conflict' && !('suggestedName' in meta) && !('conflictField' in meta)) {
    return null;
  }
  return {
    error: code,
    message: typeof meta.message === 'string' ? meta.message : error.message,
    conflictField: typeof meta.conflictField === 'string' ? meta.conflictField : undefined,
    existingId: typeof meta.existingId === 'string' ? meta.existingId : undefined,
    suggestedName: typeof meta.suggestedName === 'string' ? meta.suggestedName : undefined,
  };
}

export function isConfirmationRequiredError(error: unknown): boolean {
  if (!(error instanceof ApiError) || error.status !== 422) return false;
  return error.code === 'confirmation_required' || error.meta?.error === 'confirmation_required';
}

export function isUndoWindowExpiredError(error: unknown): boolean {
  if (!(error instanceof ApiError) || error.status !== 409) return false;
  return error.code === 'undo_window_expired' || error.meta?.error === 'undo_window_expired';
}

export const workspaceApi = {
  async getDashboard(
    organizationId?: string | null,
    signalOrOptions?: AbortSignal | { signal?: AbortSignal; studentId?: string },
  ): Promise<WorkspaceDashboardResponse> {
    const isSignal = typeof AbortSignal !== 'undefined' && signalOrOptions instanceof AbortSignal;
    const opts = isSignal
      ? undefined
      : (signalOrOptions as { signal?: AbortSignal; studentId?: string } | undefined);
    const signal = isSignal ? (signalOrOptions as AbortSignal) : opts?.signal;
    const studentId = opts?.studentId;
    const raw = await coreAdminHttp.request<WorkspaceDashboardResponse>('/workspace/dashboard', {
      method: 'GET',
      query: studentId ? { studentId } : undefined,
      headers: orgHeaders(organizationId),
      signal,
    });
    return normalizeDashboard(raw);
  },

  async search(
    params: {
      q: string;
      category?: string;
      limit?: number;
      offset?: number;
      scope?: WorkspaceSearchScope;
      organizationId?: string | null;
    },
    signal?: AbortSignal,
  ): Promise<WorkspaceSearchResponse> {
    const raw = await coreAdminHttp.request<WorkspaceSearchResponse>('/workspace/search', {
      method: 'GET',
      query: {
        q: params.q,
        category: params.category && params.category !== 'all' ? params.category : undefined,
        limit: params.limit,
        offset: params.offset,
        scope: params.scope,
      },
      headers: orgHeaders(params.organizationId),
      signal,
    });
    return normalizeSearchResponse(raw);
  },

  async executeAction(
    payload: ExecuteWorkspaceActionPayload,
    signal?: AbortSignal,
  ): Promise<ExecuteWorkspaceActionResponse> {
    const { organizationId, ...body } = payload;
    const raw = await coreAdminHttp.request<ExecuteWorkspaceActionResponse | WorkspaceOperation>(
      '/workspace/actions/execute',
      {
        method: 'POST',
        body,
        headers: orgHeaders(organizationId),
        signal,
      },
    );
    const obj = asObject(raw);
    return {
      operation: normalizeWorkspaceOperation(obj.operation ?? obj),
      result: obj.result,
      undoToken: typeof obj.undoToken === 'string' ? obj.undoToken : undefined,
    };
  },

  async listOperations(
    params?: { status?: string; limit?: number; offset?: number; organizationId?: string | null },
    signal?: AbortSignal,
  ): Promise<WorkspaceOperationsResponse> {
    const raw = await coreAdminHttp.request<WorkspaceOperationsResponse>('/workspace/operations', {
      method: 'GET',
      query: {
        status: params?.status,
        limit: params?.limit,
        offset: params?.offset,
      },
      headers: orgHeaders(params?.organizationId),
      signal,
    });
    const obj = asObject(raw);
    const items = Array.isArray(obj.items) ? obj.items.map(normalizeWorkspaceOperation) : [];
    const activeCount =
      typeof obj.activeCount === 'number'
        ? obj.activeCount
        : items.filter((op) => ['pending', 'queued', 'running', 'undoable', 'scheduled', 'deferred'].includes(op.status)).length;
    return {
      items,
      activeCount,
      total: typeof obj.total === 'number' ? obj.total : items.length,
    };
  },

  async undoOperation(
    id: string,
    organizationId?: string | null,
  ): Promise<{ operation: WorkspaceOperation; restored: boolean }> {
    const raw = await coreAdminHttp.request<Record<string, unknown>>(
      `/workspace/operations/${encodeURIComponent(id)}/undo`,
      {
        method: 'POST',
        body: {},
        headers: orgHeaders(organizationId),
      },
    );
    const op = normalizeWorkspaceOperation(raw?.operation ?? raw);
    return {
      operation: op,
      restored: typeof raw?.restored === 'boolean' ? raw.restored : op.status === 'undone',
    };
  },

  async cancelOperation(
    id: string,
    organizationId?: string | null,
  ): Promise<{ operation: WorkspaceOperation; cancelled: boolean }> {
    const raw = await coreAdminHttp.request<Record<string, unknown>>(
      `/workspace/operations/${encodeURIComponent(id)}/cancel`,
      {
        method: 'POST',
        body: {},
        headers: orgHeaders(organizationId),
      },
    );
    const op = normalizeWorkspaceOperation(raw?.operation ?? raw);
    return {
      operation: op,
      cancelled: typeof raw?.cancelled === 'boolean' ? raw.cancelled : op.status === 'cancelled',
    };
  },

  async retryOperation(
    id: string,
    organizationId?: string | null,
  ): Promise<{ operation: WorkspaceOperation }> {
    const raw = await coreAdminHttp.request<Record<string, unknown>>(
      `/workspace/operations/${encodeURIComponent(id)}/retry`,
      {
        method: 'POST',
        body: {},
        headers: orgHeaders(organizationId),
      },
    );
    return {
      operation: normalizeWorkspaceOperation(raw?.operation ?? raw),
    };
  },

  async listTrash(
    params?: { category?: string; limit?: number; offset?: number; organizationId?: string | null },
    signal?: AbortSignal,
  ): Promise<TrashListResponse> {
    const raw = await coreAdminHttp.request<TrashListResponse>('/workspace/trash', {
      method: 'GET',
      query: {
        category: params?.category && params.category !== 'all' ? params.category : undefined,
        limit: params?.limit,
        offset: params?.offset,
      },
      headers: orgHeaders(params?.organizationId),
      signal,
    });
    const obj = asObject(raw);
    const items = Array.isArray(obj.items) ? obj.items.map(normalizeTrashItem) : [];
    return {
      items,
      total: typeof obj.total === 'number' ? obj.total : items.length,
    };
  },

  async moveToTrash(
    payload: {
      entityType: string;
      entityId: string;
      category?: string;
      title?: string;
      organizationId?: string | null;
      metadata?: Record<string, unknown>;
    },
  ): Promise<{ item: TrashItem; operation?: WorkspaceOperation }> {
    const { organizationId, ...body } = payload;
    const raw = await coreAdminHttp.request<Record<string, unknown>>('/workspace/trash', {
      method: 'POST',
      body,
      headers: orgHeaders(organizationId),
    });
    const obj = asObject(raw);
    return {
      item: normalizeTrashItem(obj.item ?? obj),
      operation: obj.operation ? normalizeWorkspaceOperation(obj.operation) : undefined,
    };
  },

  async restoreTrashItem(
    id: string,
    options?: { renameOnConflict?: boolean; organizationId?: string | null },
  ): Promise<{ item: TrashItem; restored: boolean }> {
    const raw = await coreAdminHttp.request<Record<string, unknown>>(
      `/workspace/trash/${encodeURIComponent(id)}/restore`,
      {
        method: 'POST',
        body: options?.renameOnConflict ? { renameOnConflict: true } : {},
        headers: orgHeaders(options?.organizationId),
      },
    );
    const obj = asObject(raw);
    return {
      item: normalizeTrashItem(obj.item ?? obj),
      restored: typeof obj.restored === 'boolean' ? obj.restored : obj.restoreStatus === 'restored',
    };
  },

  async purgeTrashItem(
    id: string,
    organizationId?: string | null,
  ): Promise<{ deleted: boolean; id: string }> {
    const raw = await coreAdminHttp.request<Record<string, unknown>>(
      `/workspace/trash/${encodeURIComponent(id)}`,
      {
        method: 'DELETE',
        headers: orgHeaders(organizationId),
      },
    );
    const obj = asObject(raw);
    return {
      deleted: typeof obj.deleted === 'boolean' ? obj.deleted : Boolean(obj.purged),
      id: typeof obj.id === 'string' ? obj.id : id,
    };
  },

  async emptyTrash(organizationId?: string | null): Promise<{ purgedCount: number }> {
    return coreAdminHttp.request<{ purgedCount: number }>('/workspace/trash/empty', {
      method: 'POST',
      body: {},
      headers: orgHeaders(organizationId),
    });
  },

  async getPreferences(organizationId?: string | null): Promise<WorkspacePreferences> {
    return coreAdminHttp.request<WorkspacePreferences>('/workspace/preferences', {
      method: 'GET',
      headers: orgHeaders(organizationId),
    });
  },

  async updatePreferences(
    preferences: Partial<WorkspacePreferences>,
    organizationId?: string | null,
  ): Promise<WorkspacePreferences> {
    return coreAdminHttp.request<WorkspacePreferences>('/workspace/preferences', {
      method: 'PUT',
      body: preferences,
      headers: orgHeaders(organizationId),
    });
  },
};
