import { coreAdminHttp } from '@/shared/api/core/adminHttp';

export interface RealtimeTicketResponse {
  ticket: string;
  wsPath: string;
  expiresAt: string;
  latestSeq: number;
  heartbeatMs: number;
  accountId?: string;
  sessionId?: string;
}

export interface RealtimeEventEnvelope {
  seq: number;
  eventId: string;
  organizationId: string;
  channel: string;
  eventType: string;
  aggregateType: string;
  aggregateId: string;
  actorAccountId: string | null;
  actorPersonId: string | null;
  payload: Record<string, unknown>;
  targetPersonIds: string[] | null;
  createdAt: string;
  ephemeral?: boolean;
}

export interface PresenceEntry {
  personId: string;
  status: 'online' | 'offline';
  lastSeenAt: string | null;
}

interface BroadcastPayload {
  kind: 'event';
  scopeKey: string;
  event: RealtimeEventEnvelope;
}

type EventHandler = (event: RealtimeEventEnvelope) => void;
type StatusHandler = (status: 'disconnected' | 'connecting' | 'connected') => void;

export const REALTIME_BROADCAST_CHANNEL = 'platform_realtime_sync_v1';

export class PlatformRealtimeClient {
  private ws: WebSocket | null = null;
  private accountId = '';
  private sessionId = '';
  private organizationId = '';
  private status: 'disconnected' | 'connecting' | 'connected' = 'disconnected';
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null;
  private heartbeatTimer: ReturnType<typeof setInterval> | null = null;
  private backoffMs = 500;
  private manualClose = false;

  private readonly channelSubscribers = new Map<string, Set<EventHandler>>();
  private readonly globalSubscribers = new Set<EventHandler>();
  private readonly statusSubscribers = new Set<StatusHandler>();
  private readonly lastSeqByChannel = new Map<string, number>();
  private readonly seenEventIds = new Set<string>();
  private broadcast: BroadcastChannel | null = null;

  getConnectionStatus(): 'disconnected' | 'connecting' | 'connected' {
    return this.status;
  }

  getScopeKey(): string {
    if (!this.accountId || !this.sessionId || !this.organizationId) {
      return '';
    }
    return `${this.accountId}:${this.sessionId}:${this.organizationId}`;
  }

  /**
   * Binds the authenticated account + session identity used to scope BroadcastChannel sync.
   * Switching or clearing accounts resets all session-scoped caches and subscriptions.
   */
  setAuthContext(accountId: string, sessionId: string): void {
    const nextAccount = accountId.trim();
    const nextSession = sessionId.trim();

    if (!nextAccount || !nextSession) {
      this.disconnect(true);
      return;
    }

    const switchingIdentity =
      (this.accountId !== '' && this.accountId !== nextAccount) ||
      (this.sessionId !== '' && this.sessionId !== nextSession);

    if (switchingIdentity) {
      this.disconnect(true);
    }

    this.accountId = nextAccount;
    this.sessionId = nextSession;

    if (this.organizationId !== '') {
      this.recreateBroadcastChannel();
    }
  }

  onStatusChange(handler: StatusHandler): () => void {
    this.statusSubscribers.add(handler);
    handler(this.status);
    return () => {
      this.statusSubscribers.delete(handler);
    };
  }

  onAnyEvent(handler: EventHandler): () => void {
    this.globalSubscribers.add(handler);
    return () => {
      this.globalSubscribers.delete(handler);
    };
  }

  async connect(organizationId: string): Promise<void> {
    if (!organizationId) {
      return;
    }
    if (
      this.organizationId === organizationId &&
      (this.ws?.readyState === WebSocket.OPEN || this.ws?.readyState === WebSocket.CONNECTING)
    ) {
      return;
    }
    if (this.organizationId !== organizationId) {
      this.disconnect(false);
      this.organizationId = organizationId;
      this.recreateBroadcastChannel();
    }

    this.manualClose = false;
    await this.openSocket();
  }

  disconnect(resetSession = true): void {
    this.manualClose = true;
    this.organizationId = '';
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = null;
    }
    if (this.heartbeatTimer) {
      clearInterval(this.heartbeatTimer);
      this.heartbeatTimer = null;
    }
    if (this.ws) {
      try {
        this.ws.close(1000, 'client_disconnect');
      } catch {
        // Ignore close errors
      }
      this.ws = null;
    }

    this.lastSeqByChannel.clear();
    this.seenEventIds.clear();
    this.backoffMs = 500;
    this.closeBroadcastChannel();

    if (resetSession) {
      this.accountId = '';
      this.sessionId = '';
      this.channelSubscribers.clear();
    }

    this.setStatus('disconnected');
  }

  subscribe(channel: string, handler: EventHandler): () => void {
    let set = this.channelSubscribers.get(channel);
    const isNew = !set;
    if (!set) {
      set = new Set();
      this.channelSubscribers.set(channel, set);
    }
    set.add(handler);

    if (isNew && this.ws?.readyState === WebSocket.OPEN) {
      this.sendSubscribe(channel);
    }

    return () => {
      const current = this.channelSubscribers.get(channel);
      if (!current) return;
      current.delete(handler);
      if (current.size === 0) {
        this.channelSubscribers.delete(channel);
        if (this.ws?.readyState === WebSocket.OPEN) {
          this.ws.send(JSON.stringify({ type: 'unsubscribe', channel }));
        }
      }
    };
  }

  sendTyping(roomId: string, typing: boolean): void {
    if (this.ws?.readyState === WebSocket.OPEN) {
      this.ws.send(JSON.stringify({ type: 'typing', roomId, typing }));
    } else if (this.organizationId) {
      void coreAdminHttp
        .request('/realtime/typing', {
          method: 'POST',
          headers: { 'X-Education-Organization': this.organizationId },
          body: { roomId, typing },
        })
        .catch(() => undefined);
    }
  }

  async replayChannel(channel: string): Promise<void> {
    if (!this.organizationId) return;
    const sinceSeq = this.lastSeqByChannel.get(channel) ?? 0;
    const res = await coreAdminHttp.request<{ items: RealtimeEventEnvelope[] }>(
      `/realtime/events?channel=${encodeURIComponent(channel)}&sinceSeq=${sinceSeq}`,
      { method: 'GET', headers: { 'X-Education-Organization': this.organizationId } },
    );
    for (const ev of res.items ?? []) {
      this.dispatchEvent(ev, false);
    }
  }

  async fetchPresences(organizationId: string, personIds: string[]): Promise<PresenceEntry[]> {
    if (!organizationId || personIds.length === 0) return [];
    const res = await coreAdminHttp.request<{ items: PresenceEntry[] }>(
      `/realtime/presence?personIds=${encodeURIComponent(personIds.join(','))}`,
      { method: 'GET', headers: { 'X-Education-Organization': organizationId } },
    );
    return res.items ?? [];
  }

  private closeBroadcastChannel(): void {
    if (this.broadcast) {
      try {
        this.broadcast.onmessage = null;
        this.broadcast.close();
      } catch {
        // Ignore close errors
      }
      this.broadcast = null;
    }
  }

  private recreateBroadcastChannel(): void {
    this.closeBroadcastChannel();
    if (typeof BroadcastChannel === 'undefined' || this.getScopeKey() === '') {
      return;
    }
    this.broadcast = new BroadcastChannel(REALTIME_BROADCAST_CHANNEL);
    this.broadcast.onmessage = (ev: MessageEvent) => {
      const activeScope = this.getScopeKey();
      if (!activeScope) {
        return;
      }
      const msg = ev.data as Partial<BroadcastPayload> | undefined;
      if (
        msg?.kind !== 'event' ||
        typeof msg.scopeKey !== 'string' ||
        msg.scopeKey === '' ||
        msg.scopeKey !== activeScope ||
        !msg.event ||
        msg.event.organizationId !== this.organizationId
      ) {
        return;
      }
      this.dispatchEvent(msg.event, false);
    };
  }

  private async openSocket(): Promise<void> {
    const orgId = this.organizationId;
    if (!orgId || this.manualClose) return;

    this.setStatus('connecting');
    try {
      const ticketRes = await coreAdminHttp.request<RealtimeTicketResponse>('/realtime/ticket', {
        method: 'POST',
        headers: { 'X-Education-Organization': orgId },
        body: { organizationId: orgId },
      });
      if (this.organizationId !== orgId || this.manualClose) return;

      if (ticketRes.accountId && ticketRes.sessionId) {
        const switching =
          (this.accountId !== '' && this.accountId !== ticketRes.accountId) ||
          (this.sessionId !== '' && this.sessionId !== ticketRes.sessionId);
        const changed =
          this.accountId !== ticketRes.accountId || this.sessionId !== ticketRes.sessionId;
        if (switching) {
          this.lastSeqByChannel.clear();
          this.seenEventIds.clear();
        }
        this.accountId = ticketRes.accountId;
        this.sessionId = ticketRes.sessionId;
        if (changed || !this.broadcast) {
          this.recreateBroadcastChannel();
        }
      }

      const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
      const url = `${proto}//${window.location.host}${ticketRes.wsPath}?ticket=${encodeURIComponent(ticketRes.ticket)}`;
      const ws = new WebSocket(url);
      this.ws = ws;

      ws.onopen = () => {
        this.backoffMs = 500;
        this.setStatus('connected');
        for (const chan of this.channelSubscribers.keys()) {
          this.sendSubscribe(chan);
        }
        if (this.heartbeatTimer) clearInterval(this.heartbeatTimer);
        this.heartbeatTimer = setInterval(() => {
          if (this.ws?.readyState === WebSocket.OPEN) {
            this.ws.send(JSON.stringify({ type: 'ping' }));
          }
        }, ticketRes.heartbeatMs || 20000);
      };

      ws.onmessage = (msg) => {
        try {
          const parsed = JSON.parse(String(msg.data)) as {
            type?: string;
            event?: RealtimeEventEnvelope;
            channel?: string;
            reason?: string;
          };
          if (parsed.type === 'event' && parsed.event) {
            this.dispatchEvent(parsed.event, true);
          } else if (
            parsed.type === 'unsubscribed' &&
            parsed.channel &&
            (parsed.reason === 'member_removed' || parsed.reason === 'access_revoked')
          ) {
            this.channelSubscribers.delete(parsed.channel);
          }
        } catch {
          // Ignore malformed frame
        }
      };

      ws.onclose = (ev) => {
        if (this.heartbeatTimer) {
          clearInterval(this.heartbeatTimer);
          this.heartbeatTimer = null;
        }
        this.ws = null;
        if (ev.code === 4001) {
          this.manualClose = true;
        }
        this.setStatus('disconnected');
        if (!this.manualClose && this.organizationId === orgId) {
          this.scheduleReconnect();
        }
      };

      ws.onerror = () => {
        // Error triggers onclose which handles backoff
      };
    } catch {
      this.setStatus('disconnected');
      if (!this.manualClose && this.organizationId === orgId) {
        this.scheduleReconnect();
      }
    }
  }

  private scheduleReconnect(): void {
    if (this.reconnectTimer || this.manualClose) return;
    const wait = this.backoffMs;
    this.backoffMs = Math.min(15000, Math.round(this.backoffMs * 1.8));
    this.reconnectTimer = setTimeout(() => {
      this.reconnectTimer = null;
      void this.openSocket();
    }, wait);
  }

  private sendSubscribe(channel: string): void {
    if (this.ws?.readyState !== WebSocket.OPEN) return;
    const sinceSeq = this.lastSeqByChannel.get(channel);
    this.ws.send(
      JSON.stringify({
        type: 'subscribe',
        channel,
        ...(sinceSeq !== undefined ? { sinceSeq } : {}),
      }),
    );
  }

  private dispatchEvent(event: RealtimeEventEnvelope, mirrorToTabs: boolean): void {
    if (this.organizationId && event.organizationId !== this.organizationId) {
      return;
    }
    if (!event.ephemeral && event.eventId) {
      if (this.seenEventIds.has(event.eventId)) {
        return;
      }
      this.seenEventIds.add(event.eventId);
      if (this.seenEventIds.size > 4000) {
        const first = this.seenEventIds.values().next().value;
        if (first) this.seenEventIds.delete(first);
      }
    }
    if (event.seq > 0) {
      const prev = this.lastSeqByChannel.get(event.channel) ?? 0;
      if (event.seq > prev) {
        this.lastSeqByChannel.set(event.channel, event.seq);
      }
    }

    const scopeKey = this.getScopeKey();
    if (mirrorToTabs && this.broadcast && scopeKey !== '' && !event.ephemeral) {
      const payload: BroadcastPayload = { kind: 'event', scopeKey, event };
      this.broadcast.postMessage(payload);
    }

    const subs = this.channelSubscribers.get(event.channel);
    if (subs) {
      for (const fn of subs) {
        try {
          fn(event);
        } catch {
          // Listener errors stay isolated
        }
      }
    }
    for (const fn of this.globalSubscribers) {
      try {
        fn(event);
      } catch {
        // Listener errors stay isolated
      }
    }
  }

  private setStatus(next: 'disconnected' | 'connecting' | 'connected'): void {
    this.status = next;
    for (const fn of this.statusSubscribers) {
      fn(next);
    }
  }
}

export const realtimeClient = new PlatformRealtimeClient();
