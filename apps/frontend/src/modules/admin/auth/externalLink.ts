import { ApiError, RequestAbortedError } from '@/shared/api/errors';
import { coreCredentials } from '@/shared/api/core/credentials';
import type { CoreExternalProvider } from '@/shared/api/core/adminAuth';
import { prepareRecoveryRedirect } from './externalRecovery';

async function channelName(state: string): Promise<string> {
  const hash = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(state));
  return (
    'platform.core.identity-link.' +
    Array.from(new Uint8Array(hash), (b) => b.toString(16).padStart(2, '0')).join('')
  );
}
/** The popup has no Core tokens or opener. Only its same-origin callback relays a one-time code. */
export async function linkExternalIdentity(
  provider: CoreExternalProvider,
  signal: AbortSignal,
  loadingText: string,
): Promise<void> {
  if (typeof BroadcastChannel === 'undefined')
    throw new ApiError(400, 'identity_popup_unsupported', 'Popup communication is unavailable.');
  const popup = window.open('about:blank', '_blank', 'popup,width=520,height=720');
  if (!popup)
    throw new ApiError(400, 'identity_popup_blocked', 'Allow this site to open a sign-in window.');
  popup.document.body.textContent = loadingText;
  popup.opener = null;
  let channel: BroadcastChannel | undefined;
  try {
    const start = await coreCredentials.startLink(provider);
    if (signal.aborted) throw new RequestAbortedError();
    const url = await prepareRecoveryRedirect(
      provider,
      start,
      'identity-link',
      popup.sessionStorage,
    );
    const state = new URL(url).searchParams.get('state')!;
    channel = new BroadcastChannel(await channelName(state));
    const code = await new Promise<string>((resolve, reject) => {
      const cleanup = () => {
        clearTimeout(timer);
        signal.removeEventListener('abort', abort);
      };
      const abort = () => {
        cleanup();
        reject(new RequestAbortedError());
      };
      const timer = setTimeout(
        () => {
          cleanup();
          reject(new ApiError(400, 'identity_link_timeout', 'Start linking again.'));
        },
        Math.min(300_000, Math.max(1, Date.parse(start.expiresAt) - Date.now())),
      );
      signal.addEventListener('abort', abort, { once: true });
      channel!.onmessage = ({ data }: MessageEvent<unknown>) => {
        if (typeof data !== 'object' || data === null) return;
        const message = data as Record<string, unknown>;
        if (
          message.type !== 'core-identity-link' ||
          message.provider !== provider ||
          message.state !== state
        )
          return;
        cleanup();
        if (message.cancelled === true)
          reject(new ApiError(400, 'identity_link_cancelled', 'Provider sign-in was cancelled.'));
        else if (
          typeof message.code === 'string' &&
          message.code.length > 0 &&
          message.code.length <= 4096
        )
          resolve(message.code);
        else
          reject(new ApiError(400, 'core_recovery_callback_invalid', 'Invalid provider response.'));
      };
      if (signal.aborted) {
        abort();
        return;
      }
      popup.location.replace(url);
    });
    if (signal.aborted) throw new RequestAbortedError();
    await coreCredentials.completeLink(provider, state, code);
    if (signal.aborted) throw new RequestAbortedError();
  } finally {
    channel?.close();
    try {
      popup.close();
    } catch {
      /* Cross-origin isolation may have detached the window. */
    }
  }
}
export async function relayIdentityCallback(
  provider: CoreExternalProvider,
  state: string,
  code: unknown,
  error: unknown,
): Promise<void> {
  if (typeof BroadcastChannel === 'undefined')
    throw new ApiError(400, 'identity_popup_unsupported', 'Popup communication is unavailable.');
  const channel = new BroadcastChannel(await channelName(state));
  channel.postMessage({
    type: 'core-identity-link',
    provider,
    state,
    code: typeof code === 'string' ? code : null,
    cancelled: error !== undefined,
  });
  channel.close();
}
