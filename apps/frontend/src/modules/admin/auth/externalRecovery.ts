import { ApiError } from '@/shared/api/errors';
import type { CoreExternalProvider, CoreExternalStart } from '@/shared/api/core/adminAuth';
import { coreClientHeaders, resumeCoreClientInstance } from '@/core/admin/clientInstance';

const KEY = 'platform.core.mfa-recovery';
const endpoints: Record<CoreExternalProvider, string> = {
  google: 'https://accounts.google.com/o/oauth2/v2/auth',
  telegram: 'https://oauth.telegram.org/auth',
};
interface Marker { provider: CoreExternalProvider; stateHash: string; expiresAt: number; clientInstanceId: string; purpose: 'mfa-recovery' | 'login' | 'identity-link' }
const rejected = () => new ApiError(400, 'core_recovery_callback_invalid', 'Recovery must be restarted.');

export function isCoreProvider(value: unknown): value is CoreExternalProvider {
  return value === 'google' || value === 'telegram';
}

export function requireRecoveryStorage(storage?: Storage): Storage {
  try { const target = storage ?? sessionStorage; target.setItem(`${KEY}.probe`, '1'); target.removeItem(`${KEY}.probe`); return target; }
  catch { throw new ApiError(400, 'core_recovery_storage', 'Session storage is unavailable.'); }
}

async function digest(state: string): Promise<string> {
  const bytes = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(state));
  return Array.from(new Uint8Array(bytes), byte => byte.toString(16).padStart(2, '0')).join('');
}

/** Carry correlation and the non-secret client binding; never an email/proof/code/session token. */
export async function prepareRecoveryRedirect(provider: CoreExternalProvider, start: CoreExternalStart, purpose: Marker['purpose'] = 'mfa-recovery', storage?: Storage): Promise<string> {
  const target = requireRecoveryStorage(storage);
  const url = new URL(start.authorizationUrl);
  const expiry = Date.parse(start.expiresAt);
  const state = url.searchParams.get('state');
  if (`${url.origin}${url.pathname}` !== endpoints[provider] || url.username || url.password || url.hash
    || !state || state.length > 512 || url.searchParams.getAll('state').length !== 1
    || url.searchParams.getAll('redirect_uri').length !== 1
    || url.searchParams.get('redirect_uri') !== new URL(`/auth/callback/${provider}`, location.origin).href
    || !Number.isFinite(expiry) || expiry <= Date.now()) throw rejected();
  const clientInstanceId = coreClientHeaders()['X-Client-Instance-ID'];
  if (!clientInstanceId) throw rejected();
  const marker: Marker = { provider, stateHash: await digest(state), expiresAt: expiry, clientInstanceId, purpose };
  target.setItem(KEY, JSON.stringify(marker));
  return url.href;
}

export async function consumeRecoveryCallback(provider: unknown, state: unknown): Promise<Pick<Marker, 'provider' | 'purpose'>> {
  if (!isCoreProvider(provider) || typeof state !== 'string' || state === '' || state.length > 512) throw rejected();
  let raw: string | null;
  try { raw = sessionStorage.getItem(KEY); } catch { throw rejected(); }
  let marker: Marker;
  try { marker = JSON.parse(raw ?? '') as Marker; } catch { throw rejected(); }
  if (marker?.provider !== provider || !['login', 'mfa-recovery', 'identity-link'].includes(marker.purpose) || typeof marker.stateHash !== 'string' || marker.expiresAt <= Date.now()
    || !Number.isFinite(marker.expiresAt) || marker.stateHash !== await digest(state)) throw rejected();
  if (sessionStorage.getItem(KEY) !== raw) throw rejected();
  // Login/recovery return in a new document. Keep the server's original client
  // binding; identity linking completes in the original window via its channel.
  if (marker.purpose !== 'identity-link' && !resumeCoreClientInstance(marker.clientInstanceId)) throw rejected();
  sessionStorage.removeItem(KEY);
  return { provider, purpose: marker.purpose };
}
