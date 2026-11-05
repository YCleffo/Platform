import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { consumeRecoveryCallback, prepareRecoveryRedirect, requireRecoveryStorage } from './externalRecovery';
const state = 'state-' + 'a'.repeat(43);
function start(provider = 'google', changes: Record<string, string> = {}) {
  const url = new URL(provider === 'google' ? 'https://accounts.google.com/o/oauth2/v2/auth' : 'https://oauth.telegram.org/auth');
  url.searchParams.set('state', state); url.searchParams.set('redirect_uri', `${location.origin}/auth/callback/${provider}`);
  for (const [key, value] of Object.entries(changes)) url.searchParams.set(key, value);
  return { authorizationUrl: url.href, expiresAt: new Date(Date.now() + 300000).toISOString() };
}
beforeEach(() => { sessionStorage.clear(); });
afterEach(() => vi.unstubAllGlobals());

describe('External MFA recovery correlation', () => {
  it.each(['google', 'telegram'] as const)('accepts one matching %s callback and persists no raw token/state', async provider => {
    await prepareRecoveryRedirect(provider, start(provider));
    expect(sessionStorage.getItem('platform.core.mfa-recovery')).not.toContain(state);
    expect(await consumeRecoveryCallback(provider, state)).toEqual({ provider, purpose: 'mfa-recovery' });
    await expect(consumeRecoveryCallback(provider, state)).rejects.toMatchObject({ code: 'core_recovery_callback_invalid' });
  });
  it('rejects cross-provider, foreign state, expired and uncorrelated callbacks', async () => {
    await expect(consumeRecoveryCallback('google', state)).rejects.toBeInstanceOf(Error);
    await prepareRecoveryRedirect('google', start());
    await expect(consumeRecoveryCallback('telegram', state)).rejects.toBeInstanceOf(Error);
    await expect(consumeRecoveryCallback('google', 'foreign-state')).rejects.toBeInstanceOf(Error);
    vi.spyOn(Date, 'now').mockReturnValue(Date.now() + 600000);
    await expect(consumeRecoveryCallback('google', state)).rejects.toBeInstanceOf(Error);
  });
  it.each(['https://attacker.test/path', 'javascript:alert(1)', 'https://accounts.google.com.attacker.test/o/oauth2/v2/auth'])('rejects unexpected redirect %s', async authorizationUrl => {
    await expect(prepareRecoveryRedirect('google', { ...start(), authorizationUrl })).rejects.toBeInstanceOf(Error);
  });
  it('rejects an unexpected callback origin and duplicate state', async () => {
    await expect(prepareRecoveryRedirect('google', start('google', { redirect_uri: 'https://attacker.test/auth/callback/google' }))).rejects.toBeInstanceOf(Error);
    const duplicate = start(); duplicate.authorizationUrl += '&state=second';
    await expect(prepareRecoveryRedirect('google', duplicate)).rejects.toBeInstanceOf(Error);
  });
  it('does not leave for the provider when session storage is blocked', () => {
    vi.stubGlobal('sessionStorage', { setItem: () => { throw new Error('blocked'); } });
    expect(requireRecoveryStorage).toThrow();
  });
});
