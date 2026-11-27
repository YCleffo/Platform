import { coreAdminHttp } from '@/shared/api/core/adminHttp';

export interface NotificationItem {
  id: string;
  category: string;
  kind: string;
  title: string;
  body: string;
  roomId: string | null;
  messageId: string | null;
  resourceType: string | null;
  resourceId: string | null;
  resourcePath: string | null;
  resourceAvailable?: boolean;
  parentStudentId: string | null;
  priority: 'low' | 'normal' | 'high' | 'urgent';
  payload: Record<string, unknown>;
  createdAt: string;
  readAt: string | null;
  archivedAt?: string | null;
}

export interface NotificationGroup {
  key: string;
  groupKey?: string;
  label?: string;
  category?: string;
  count: number;
  unreadCount?: number;
  items: NotificationItem[];
}

export interface PushStatusInfo {
  configured: boolean;
  status: 'active' | 'permission_denied' | 'not_configured' | 'unsupported' | 'unsubscribed';
  reason?: string;
  vapidPublicKey?: string | null;
}

export interface NotificationListResponse {
  items: NotificationItem[];
  groups?: NotificationGroup[];
  hasMore: boolean;
  offset: number;
  unreadCount: number;
  pushStatus?: PushStatusInfo;
}

export interface NotificationPreference {
  category: string;
  inApp: boolean;
  realtime: boolean;
  webPush: boolean;
  email: boolean;
  sound: boolean;
}

function orgHeaders(organizationId: string): Record<string, string> {
  return { 'X-Education-Organization': organizationId };
}

export const notificationsApi = {
  async list(
    organizationId: string,
    params?: {
      offset?: number;
      limit?: number;
      category?: string;
      unreadOnly?: boolean;
      grouped?: boolean;
    },
  ): Promise<NotificationListResponse> {
    const q = new URLSearchParams();
    if (params?.offset) q.set('offset', String(params.offset));
    if (params?.limit) q.set('limit', String(params.limit));
    if (params?.category) q.set('category', params.category);
    if (params?.unreadOnly) q.set('unreadOnly', '1');
    if (params?.grouped) q.set('grouped', '1');
    const qs = q.toString();
    const res = await coreAdminHttp.request<NotificationListResponse & { groups?: Array<NotificationGroup & { groupKey?: string }> }>(
      `/notifications${qs ? '?' + qs : ''}`,
      {
        method: 'GET',
        headers: orgHeaders(organizationId),
      },
    );
    return {
      ...res,
      groups: res.groups?.map((g) => ({
        ...g,
        key: g.key ?? g.groupKey ?? g.category ?? 'general',
      })),
    };
  },

  async markRead(organizationId: string, id: string): Promise<{ read: boolean; unreadCount: number }> {
    return coreAdminHttp.request(`/notifications/${encodeURIComponent(id)}/read`, {
      method: 'POST',
      headers: orgHeaders(organizationId),
      body: {},
    });
  },

  async markAllRead(organizationId: string, category?: string): Promise<{ readAll: boolean; unreadCount: number }> {
    return coreAdminHttp.request('/notifications/read-all', {
      method: 'POST',
      headers: orgHeaders(organizationId),
      body: category ? { category } : {},
    });
  },

  async bulkRead(
    organizationId: string,
    payload: { ids?: string[]; category?: string },
  ): Promise<{ updatedCount: number; unreadCount: number }> {
    const res = await coreAdminHttp.request<{ updatedCount?: number; updated?: number; unreadCount: number }>(
      '/notifications/bulk-read',
      {
        method: 'POST',
        headers: orgHeaders(organizationId),
        body: payload,
      },
    );
    return { updatedCount: res.updatedCount ?? res.updated ?? 0, unreadCount: res.unreadCount };
  },

  async bulkArchive(
    organizationId: string,
    payload: { ids?: string[]; category?: string; readOnly?: boolean },
  ): Promise<{ archivedCount: number; unreadCount: number }> {
    const res = await coreAdminHttp.request<{ archivedCount?: number; archived?: number; unreadCount: number }>(
      '/notifications/bulk-archive',
      {
        method: 'POST',
        headers: orgHeaders(organizationId),
        body: payload,
      },
    );
    return { archivedCount: res.archivedCount ?? res.archived ?? 0, unreadCount: res.unreadCount };
  },

  async getPreferences(organizationId: string): Promise<{ items: NotificationPreference[] }> {
    return coreAdminHttp.request('/notifications/preferences', {
      method: 'GET',
      headers: orgHeaders(organizationId),
    });
  },

  async updatePreference(
    organizationId: string,
    pref: NotificationPreference,
  ): Promise<{ items: NotificationPreference[] }> {
    return coreAdminHttp.request('/notifications/preferences', {
      method: 'PUT',
      headers: orgHeaders(organizationId),
      body: pref,
    });
  },

  async registerPushSubscription(
    organizationId: string,
    sub: { endpoint: string; p256dh?: string; authSecret?: string; deviceLabel?: string },
  ): Promise<{ id: string; registered: boolean }> {
    return coreAdminHttp.request('/notifications/push-subscriptions', {
      method: 'POST',
      headers: orgHeaders(organizationId),
      body: sub,
    });
  },
};
