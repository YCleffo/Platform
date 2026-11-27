import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  PlatformRealtimeClient,
  REALTIME_BROADCAST_CHANNEL,
  type RealtimeEventEnvelope,
} from './realtimeClient';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';

class FakeBroadcastChannel {
  static instances: FakeBroadcastChannel[] = [];
  readonly name: string;
  onmessage: ((ev: MessageEvent) => void) | null = null;
  closed = false;
  posted: unknown[] = [];

  constructor(name: string) {
    this.name = name;
    FakeBroadcastChannel.instances.push(this);
  }

  postMessage(data: unknown): void {
    this.posted.push(data);
    for (const peer of FakeBroadcastChannel.instances) {
      if (peer !== this && !peer.closed && peer.name === this.name && peer.onmessage) {
        peer.onmessage({ data } as MessageEvent);
      }
    }
  }

  close(): void {
    this.closed = true;
  }
}

class FakeWebSocket {
  static OPEN = 1;
  static CONNECTING = 0;
  static CLOSED = 3;
  static instances: FakeWebSocket[] = [];

  readonly url: string;
  readyState = FakeWebSocket.CONNECTING;
  sent: string[] = [];
  onopen: (() => void) | null = null;
  onmessage: ((ev: { data: string }) => void) | null = null;
  onclose: ((ev: { code: number; reason?: string }) => void) | null = null;
  onerror: (() => void) | null = null;

  constructor(url: string) {
    this.url = url;
    FakeWebSocket.instances.push(this);
  }

  send(data: string): void {
    this.sent.push(data);
  }

  close(code = 1000, reason = ''): void {
    this.readyState = FakeWebSocket.CLOSED;
    this.onclose?.({ code, reason });
  }

  triggerOpen(): void {
    this.readyState = FakeWebSocket.OPEN;
    this.onopen?.();
  }

  triggerMessage(payload: unknown): void {
    this.onmessage?.({ data: JSON.stringify(payload) });
  }
}

function makeEvent(overrides: Partial<RealtimeEventEnvelope> = {}): RealtimeEventEnvelope {
  return {
    seq: 10,
    eventId: 'evt-10',
    organizationId: 'org-1',
    channel: 'room:r1',
    eventType: 'chat.message.created',
    aggregateType: 'chat_message',
    aggregateId: 'msg-1',
    actorAccountId: 'acc-1',
    actorPersonId: 'per-1',
    payload: { text: 'hello' },
    targetPersonIds: null,
    createdAt: '2026-11-25T10:00:00Z',
    ...overrides,
  };
}

describe('PlatformRealtimeClient security & lifecycle', () => {
  const originalBc = globalThis.BroadcastChannel;
  const originalWs = globalThis.WebSocket;

  beforeEach(() => {
    FakeBroadcastChannel.instances = [];
    FakeWebSocket.instances = [];
    vi.stubGlobal('BroadcastChannel', FakeBroadcastChannel);
    vi.stubGlobal('WebSocket', FakeWebSocket);
    vi.spyOn(coreAdminHttp, 'request').mockResolvedValue({
      ticket: 'signed-ticket',
      wsPath: '/ws',
      expiresAt: '2026-11-25T12:00:00Z',
      latestSeq: 5,
      heartbeatMs: 20000,
      accountId: 'acc-1',
      sessionId: 'sess-1',
    });
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.stubGlobal('BroadcastChannel', originalBc);
    vi.stubGlobal('WebSocket', originalWs);
  });

  it('scopes BroadcastChannel messages by accountId:sessionId:organizationId and rejects mismatched scopes', async () => {
    const clientA = new PlatformRealtimeClient();
    const receivedA: RealtimeEventEnvelope[] = [];
    clientA.setAuthContext('acc-1', 'sess-1');
    clientA.subscribe('room:r1', (ev) => receivedA.push(ev));
    await clientA.connect('org-1');

    const wsA = FakeWebSocket.instances[0]!;
    wsA.triggerOpen();
    expect(clientA.getScopeKey()).toBe('acc-1:sess-1:org-1');

    // Another tab of the EXACT SAME session & org receives the mirrored event
    const sameSessionPeer = FakeBroadcastChannel.instances.find((c) => !c.closed);
    expect(sameSessionPeer?.name).toBe(REALTIME_BROADCAST_CHANNEL);

    const attackerChannel = new FakeBroadcastChannel(REALTIME_BROADCAST_CHANNEL);
    // 1. Missing scopeKey -> ignored
    attackerChannel.postMessage({ kind: 'event', event: makeEvent({ eventId: 'evt-no-scope', seq: 11 }) });
    // 2. Different sessionId -> ignored
    attackerChannel.postMessage({
      kind: 'event',
      scopeKey: 'acc-1:sess-OTHER:org-1',
      event: makeEvent({ eventId: 'evt-other-sess', seq: 12 }),
    });
    // 3. Different accountId -> ignored
    attackerChannel.postMessage({
      kind: 'event',
      scopeKey: 'acc-OTHER:sess-1:org-1',
      event: makeEvent({ eventId: 'evt-other-acc', seq: 13 }),
    });
    // 4. Different organizationId -> ignored
    attackerChannel.postMessage({
      kind: 'event',
      scopeKey: 'acc-1:sess-1:org-2',
      event: makeEvent({ eventId: 'evt-other-org', seq: 14, organizationId: 'org-2' }),
    });
    expect(receivedA).toHaveLength(0);

    // 5. Exact matching scopeKey & org -> accepted
    attackerChannel.postMessage({
      kind: 'event',
      scopeKey: 'acc-1:sess-1:org-1',
      event: makeEvent({ eventId: 'evt-valid', seq: 15 }),
    });
    expect(receivedA).toHaveLength(1);
    expect(receivedA[0]?.eventId).toBe('evt-valid');

    // Verify outbound WS events include exact scopeKey in BroadcastChannel postMessage
    wsA.triggerMessage({ type: 'event', event: makeEvent({ eventId: 'evt-ws', seq: 16 }) });
    expect(sameSessionPeer?.posted).toHaveLength(1);
    expect((sameSessionPeer?.posted[0] as { scopeKey?: string }).scopeKey).toBe('acc-1:sess-1:org-1');

    clientA.disconnect();
  });

  it('clears caches, subscribers, backoff, and closes BroadcastChannel on logout and account/org switch', async () => {
    const client = new PlatformRealtimeClient();
    const handler = vi.fn();

    client.setAuthContext('acc-1', 'sess-1');
    client.subscribe('room:r1', handler);
    await client.connect('org-1');
    const ws1 = FakeWebSocket.instances[0]!;
    ws1.triggerOpen();
    ws1.triggerMessage({ type: 'event', event: makeEvent({ eventId: 'evt-1', seq: 42 }) });
    expect(handler).toHaveBeenCalledTimes(1);

    const bc1 = FakeBroadcastChannel.instances.find((c) => !c.closed)!;
    expect(bc1).toBeDefined();
    expect(bc1.closed).toBe(false);

    // Switch organization: closes old BC, creates new BC, resets lastSeq & seenEventIds
    await client.connect('org-2');
    expect(bc1.closed).toBe(true);
    expect(client.getScopeKey()).toBe('acc-1:sess-1:org-2');
    const ws2 = FakeWebSocket.instances[1]!;
    ws2.triggerOpen();
    // Subscribe sent on ws2 MUST NOT carry org-1's sinceSeq=42
    const subPayload = JSON.parse(ws2.sent.find((s) => s.includes('"subscribe"')) ?? '{}') as { sinceSeq?: number };
    expect(subPayload.sinceSeq).toBeUndefined();

    // Switch account -> full reset including clearing channelSubscribers
    const bc2 = FakeBroadcastChannel.instances.find((c) => !c.closed)!;
    client.setAuthContext('acc-2', 'sess-2');
    expect(client.getScopeKey()).toBe('');
    expect(bc2.closed).toBe(true);

    // Logout / disconnect() -> clears everything
    client.subscribe('room:r2', handler);
    await client.connect('org-3');
    const ws3 = FakeWebSocket.instances[2]!;
    ws3.triggerOpen();
    client.disconnect();
    expect(client.getConnectionStatus()).toBe('disconnected');
    expect(client.getScopeKey()).toBe('');
  });

  it('stops reconnecting when server closes WebSocket with code 4001 (revoked session)', async () => {
    vi.useFakeTimers();
    const client = new PlatformRealtimeClient();
    client.setAuthContext('acc-1', 'sess-1');
    await client.connect('org-1');

    const ws = FakeWebSocket.instances[0]!;
    ws.triggerOpen();
    ws.close(4001, 'session_revoked');

    await vi.advanceTimersByTimeAsync(20_000);
    expect(FakeWebSocket.instances).toHaveLength(1);
    expect(client.getConnectionStatus()).toBe('disconnected');
    vi.useRealTimers();
  });
});
