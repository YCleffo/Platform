import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { linkExternalIdentity } from './externalLink';
import { coreCredentials } from '@/shared/api/core/credentials';
vi.mock('@/shared/api/core/credentials', () => ({ coreCredentials: { startLink: vi.fn(), completeLink: vi.fn() } }));
class Channel {
  static instances: Channel[] = [];
  onmessage: ((event: MessageEvent<unknown>) => void) | null = null;
  close = vi.fn();
  constructor(public name: string) { Channel.instances.push(this); }
}
const state = 'correlation-state';
const start = () => ({ authorizationUrl: 'https://accounts.google.com/o/oauth2/v2/auth?' + new URLSearchParams({ state, redirect_uri: location.origin + '/auth/callback/google' }), expiresAt: new Date(Date.now() + 300000).toISOString() });
let popup: Window; let storage: Map<string, string>;
beforeEach(() => {
  vi.resetAllMocks(); Channel.instances = []; sessionStorage.clear(); storage = new Map();
  popup = { document: { body: { textContent: '' } }, opener: window, sessionStorage: { setItem: (key: string, value: string) => storage.set(key, value), removeItem: (key: string) => storage.delete(key) }, location: { replace: vi.fn() }, close: vi.fn() } as unknown as Window;
  vi.spyOn(window, 'open').mockReturnValue(popup); vi.stubGlobal('BroadcastChannel', Channel);
  vi.mocked(coreCredentials.startLink).mockResolvedValue(start()); vi.mocked(coreCredentials.completeLink).mockResolvedValue({ provider: 'google', email: null, emailVerified: false, displayName: null, avatarUrl: null, linkedAt: '2026-11-03', lastLoginAt: null });
});
afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals(); });
describe('external identity popup boundary', () => {
  it('does not start the provider flow if the popup was blocked', async () => {
    vi.mocked(window.open).mockReturnValue(null);
    await expect(linkExternalIdentity('google', new AbortController().signal, 'Loading')).rejects.toMatchObject({ code: 'identity_popup_blocked' });
    expect(coreCredentials.startLink).not.toHaveBeenCalled();
  });
  it('stores only correlation in the popup, removes its opener and ignores mismatched callbacks', async () => {
    const done = linkExternalIdentity('google', new AbortController().signal, 'Loading');
    await vi.waitFor(() => expect(Channel.instances.length).toBe(1));
    expect(popup.opener).toBeNull(); expect(sessionStorage.length).toBe(0);
    expect([...storage.values()].join('')).not.toContain(state);
    const channel = Channel.instances[0]!;
    channel.onmessage?.({ data: { type: 'core-identity-link', provider: 'google', state: 'wrong', code: 'wrong-code' } } as MessageEvent);
    expect(coreCredentials.completeLink).not.toHaveBeenCalled();
    channel.onmessage?.({ data: { type: 'core-identity-link', provider: 'google', state, code: 'valid-code' } } as MessageEvent);
    await done; expect(coreCredentials.completeLink).toHaveBeenCalledExactlyOnceWith('google', state, 'valid-code'); expect(channel.close).toHaveBeenCalled(); expect(popup.close).toHaveBeenCalled();
  });
  it('cleans up cancellation before a callback and never submits it', async () => {
    const controller = new AbortController(); const done = linkExternalIdentity('google', controller.signal, 'Loading').catch(error => error);
    await vi.waitFor(() => expect(Channel.instances.length).toBe(1)); controller.abort();
    expect(await done).toMatchObject({ name: 'RequestAbortedError' }); expect(coreCredentials.completeLink).not.toHaveBeenCalled(); expect(Channel.instances[0]!.close).toHaveBeenCalled(); expect(popup.close).toHaveBeenCalled();
  });
  it('rejects an unexpected provider URL and closes the blank popup', async () => {
    vi.mocked(coreCredentials.startLink).mockResolvedValue({ ...start(), authorizationUrl: 'https://attacker.test/?state=correlation-state' });
    await expect(linkExternalIdentity('google', new AbortController().signal, 'Loading')).rejects.toMatchObject({ code: 'core_recovery_callback_invalid' });
    expect(coreCredentials.completeLink).not.toHaveBeenCalled(); expect(popup.close).toHaveBeenCalled();
  });
});
