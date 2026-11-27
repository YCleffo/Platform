import { coreAdminHttp } from '@/shared/api/core/adminHttp';

export interface ChatPresence {
  personId: string;
  status: 'online' | 'offline';
  lastSeenAt: string | null;
}

export interface ChatRoomSummary {
  id: string;
  roomType: 'direct' | 'group' | 'organization' | 'support' | 'context';
  title: string;
  subject: string;
  description: string;
  contextType: string | null;
  contextId: string | null;
  pinnedMessageId: string | null;
  lastActivityAt: string;
  createdAt: string;
  myRole: string;
  canWrite: boolean;
  mutedUntil: string | null;
  archivedAt: string | null;
  pinnedOrder: number | null;
  draftText: string | null;
  draftReplyToId: string | null;
  memberCount: number;
  peerPersonId: string | null;
  peerName: string | null;
  peerPresence: ChatPresence | null;
  lastMessageBody: string | null;
  lastMessageKind: string | null;
  lastMessageAt: string | null;
  lastMessageSenderId: string | null;
  unreadCount: number;
}

export interface ChatMember {
  personId: string;
  name: string;
  role: string;
  canWrite: boolean;
  lastReadAt: string | null;
  joinedAt: string;
  presence?: ChatPresence;
}

export interface ChatMessage {
  id: string;
  roomId: string;
  seq?: number;
  senderPersonId: string;
  senderName?: string;
  body: string | null;
  kind: string;
  mediaAssetId: string | null;
  replyToId: string | null;
  forwardedFromMessageId: string | null;
  forwardedFromRoomId: string | null;
  metadata: Record<string, unknown>;
  editedAt: string | null;
  deletedAt: string | null;
  deletedForAll: boolean;
  pinnedAt: string | null;
  createdAt: string;
  media: {
    id: string;
    kind: string;
    name: string;
    mime: string;
    size: number;
    duration: number | null;
    status: string;
  } | null;
  replyTo: {
    id: string;
    body: string | null;
    senderPersonId: string;
  } | null;
  isReadByPeer: boolean;
  reactions: Array<{ emoji: string; count: number; reacted: boolean }>;
  optimisticPending?: boolean;
  optimisticFailed?: boolean;
  clientKey?: string;
}

export interface ChatContact {
  id: string;
  personId: string;
  name: string;
  role: string;
}

function orgHeaders(organizationId: string): Record<string, string> {
  return { 'X-Education-Organization': organizationId };
}

export const chatApi = {
  async listRooms(
    organizationId: string,
    params?: {
      offset?: number;
      limit?: number;
      tab?: string;
      q?: string;
      contextType?: string;
      contextId?: string;
    },
  ): Promise<{ items: ChatRoomSummary[]; hasMore: boolean; offset: number }> {
    const q = new URLSearchParams();
    if (params?.offset) q.set('offset', String(params.offset));
    if (params?.limit) q.set('limit', String(params.limit));
    if (params?.tab) q.set('tab', params.tab);
    if (params?.q) q.set('q', params.q);
    if (params?.contextType) q.set('contextType', params.contextType);
    if (params?.contextId) q.set('contextId', params.contextId);
    const qs = q.toString();
    return coreAdminHttp.request(`/chat/rooms${qs ? '?' + qs : ''}`, {
      method: 'GET',
      headers: orgHeaders(organizationId),
    });
  },

  async getRoom(
    organizationId: string,
    roomId: string,
  ): Promise<
    ChatRoomSummary & { members: ChatMember[]; pinnedMessage: Record<string, unknown> | null }
  > {
    return coreAdminHttp.request(`/chat/rooms/${encodeURIComponent(roomId)}`, {
      method: 'GET',
      headers: orgHeaders(organizationId),
    });
  },

  async createRoom(
    organizationId: string,
    payload: {
      roomType?: 'direct' | 'group' | 'organization' | 'support' | 'context';
      title?: string;
      description?: string;
      recipientPersonId?: string;
      memberPersonIds?: string[];
      includeAllOrgMembers?: boolean;
      contextType?: string;
      contextId?: string;
      body?: string;
      clientKey?: string;
    },
  ): Promise<ChatRoomSummary & { members: ChatMember[] }> {
    return coreAdminHttp.request('/chat/rooms', {
      method: 'POST',
      headers: orgHeaders(organizationId),
      body: payload,
    });
  },

  async listMessages(
    organizationId: string,
    roomId: string,
    params?: { offset?: number; limit?: number; beforeSeq?: number; aroundMessageId?: string },
  ): Promise<{ items: ChatMessage[]; hasMore: boolean; offset: number }> {
    const q = new URLSearchParams();
    if (params?.offset) q.set('offset', String(params.offset));
    if (params?.limit) q.set('limit', String(params.limit));
    if (params?.beforeSeq) q.set('beforeSeq', String(params.beforeSeq));
    if (params?.aroundMessageId) q.set('aroundMessageId', params.aroundMessageId);
    const qs = q.toString();
    return coreAdminHttp.request(
      `/chat/rooms/${encodeURIComponent(roomId)}/messages${qs ? '?' + qs : ''}`,
      {
        method: 'GET',
        headers: orgHeaders(organizationId),
      },
    );
  },

  async sendMessage(
    organizationId: string,
    roomId: string,
    payload: {
      body?: string;
      kind?: string;
      mediaAssetId?: string;
      replyToId?: string;
      forwardedFromMessageId?: string;
      clientKey?: string;
      metadata?: Record<string, unknown>;
    },
  ): Promise<ChatMessage> {
    return coreAdminHttp.request(`/chat/rooms/${encodeURIComponent(roomId)}/messages`, {
      method: 'POST',
      headers: orgHeaders(organizationId),
      body: payload,
    });
  },

  async editMessage(
    organizationId: string,
    roomId: string,
    messageId: string,
    body: string,
  ): Promise<ChatMessage> {
    return coreAdminHttp.request(
      `/chat/rooms/${encodeURIComponent(roomId)}/messages/${encodeURIComponent(messageId)}`,
      {
        method: 'PUT',
        headers: orgHeaders(organizationId),
        body: { body },
      },
    );
  },

  async deleteMessage(
    organizationId: string,
    roomId: string,
    messageId: string,
    mode: 'for_me' | 'for_all' = 'for_me',
  ): Promise<{ deleted: boolean; mode: string }> {
    return coreAdminHttp.request(
      `/chat/rooms/${encodeURIComponent(roomId)}/messages/${encodeURIComponent(messageId)}/delete`,
      {
        method: 'POST',
        headers: orgHeaders(organizationId),
        body: { mode },
      },
    );
  },

  async toggleReaction(
    organizationId: string,
    roomId: string,
    messageId: string,
    emoji: string,
  ): Promise<{
    reacted: boolean;
    reactions: Array<{ emoji: string; count: number; reacted: boolean }>;
  }> {
    return coreAdminHttp.request(`/chat/rooms/${encodeURIComponent(roomId)}/reactions`, {
      method: 'POST',
      headers: orgHeaders(organizationId),
      body: { messageId, emoji },
    });
  },

  async markRead(
    organizationId: string,
    roomId: string,
    lastSeenMessageId?: string,
  ): Promise<{ read: boolean }> {
    return coreAdminHttp.request(`/chat/rooms/${encodeURIComponent(roomId)}/read`, {
      method: 'POST',
      headers: orgHeaders(organizationId),
      body: lastSeenMessageId ? { lastSeenMessageId } : {},
    });
  },

  async pinMessage(
    organizationId: string,
    roomId: string,
    messageId: string | null,
  ): Promise<{ pinnedMessageId: string | null }> {
    return coreAdminHttp.request(`/chat/rooms/${encodeURIComponent(roomId)}/pin`, {
      method: 'POST',
      headers: orgHeaders(organizationId),
      body: { messageId },
    });
  },

  async saveDraft(
    organizationId: string,
    roomId: string,
    draftText: string | null,
    replyToId: string | null = null,
  ): Promise<{ saved: boolean }> {
    return coreAdminHttp.request(`/chat/rooms/${encodeURIComponent(roomId)}/draft`, {
      method: 'PUT',
      headers: orgHeaders(organizationId),
      body: { draftText, replyToId },
    });
  },

  async updatePreferences(
    organizationId: string,
    roomId: string,
    prefs: { archived?: boolean; mutedMinutes?: number; pinnedOrder?: number | null },
  ): Promise<ChatRoomSummary> {
    return coreAdminHttp.request(`/chat/rooms/${encodeURIComponent(roomId)}/preferences`, {
      method: 'PUT',
      headers: orgHeaders(organizationId),
      body: prefs,
    });
  },

  async searchMessages(
    organizationId: string,
    q: string,
    roomId?: string,
  ): Promise<{
    items: Array<{
      id: string;
      roomId: string;
      roomTitle: string;
      senderName: string;
      body: string;
      createdAt: string;
    }>;
  }> {
    const params = new URLSearchParams({ q });
    if (roomId) params.set('roomId', roomId);
    return coreAdminHttp.request(`/chat/search?${params.toString()}`, {
      method: 'GET',
      headers: orgHeaders(organizationId),
    });
  },

  async listContacts(organizationId: string, q?: string): Promise<{ items: ChatContact[] }> {
    const params = new URLSearchParams();
    if (q) params.set('q', q);
    const qs = params.toString();
    return coreAdminHttp.request(`/chat/contacts${qs ? '?' + qs : ''}`, {
      method: 'GET',
      headers: orgHeaders(organizationId),
    });
  },

  async addMember(
    organizationId: string,
    roomId: string,
    personId: string,
  ): Promise<{ added: boolean; roomId: string; personId: string }> {
    return coreAdminHttp.request(`/chat/rooms/${encodeURIComponent(roomId)}/members`, {
      method: 'POST',
      headers: orgHeaders(organizationId),
      body: { personId },
    });
  },

  async removeMember(
    organizationId: string,
    roomId: string,
    personId: string,
  ): Promise<{ removed: boolean; roomId: string; personId: string }> {
    return coreAdminHttp.request(
      `/chat/rooms/${encodeURIComponent(roomId)}/members/${encodeURIComponent(personId)}`,
      {
        method: 'DELETE',
        headers: orgHeaders(organizationId),
      },
    );
  },

  async leaveRoom(
    organizationId: string,
    roomId: string,
  ): Promise<{ left: boolean; roomId: string; personId: string }> {
    return coreAdminHttp.request(`/chat/rooms/${encodeURIComponent(roomId)}/leave`, {
      method: 'POST',
      headers: orgHeaders(organizationId),
      body: {},
    });
  },
};
