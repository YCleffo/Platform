import { afterEach, describe, expect, it, vi } from 'vitest';
import { coreAdminAuth } from './adminAuth';
import { ApiError, ApiResponseError, NetworkError, RequestAbortedError } from '@/shared/api/errors';
import { setLocale } from '@/shared/i18n';

const reply = (data: unknown, status = 200) => new Response(JSON.stringify({ status: 'success', data }), { status });
afterEach(() => vi.unstubAllGlobals());

describe('Core authentication contracts', () => {
  it('uses Core endpoints, Core field names, current locale and cancellation for email flows', async () => {
    const fetch = vi.fn().mockResolvedValueOnce(reply({ activated: true })).mockResolvedValueOnce(reply({ accepted: true }, 202))
      .mockResolvedValueOnce(reply({ completed: true })).mockResolvedValueOnce(reply({ status: 'accepted' }, 202))
      .mockResolvedValueOnce(reply({ status: 'completed' }));
    vi.stubGlobal('fetch', fetch);
    setLocale('ru');
    const signal = new AbortController().signal;
    await coreAdminAuth.activate('core-token', 'a secure password', signal);
    await coreAdminAuth.requestPasswordReset('user@example.test', signal);
    await coreAdminAuth.confirmPasswordReset('core-token', 'new secure password', signal);
    setLocale('en');
    await coreAdminAuth.requestMfaRecovery('user@example.test', signal);
    expect(await coreAdminAuth.confirmMfaRecovery('core-token', null, signal)).toEqual({ status: 'completed' });
    expect(fetch.mock.calls.map(call => call[0])).toEqual(['/api/auth/activate', '/api/auth/password-reset/request',
      '/api/auth/password-reset/confirm', '/api/auth/mfa/recovery/request', '/api/auth/mfa/recovery/confirm']);
    expect(JSON.parse(fetch.mock.calls[0]![1].body)).toEqual({ token: 'core-token', password: 'a secure password' });
    expect(JSON.parse(fetch.mock.calls[4]![1].body)).toEqual({ token: 'core-token', password: null });
    expect(fetch.mock.calls[0]![1].headers.get('X-Client-Locale')).toBe('ru-RU');
    expect(fetch.mock.calls[3]![1].headers.get('X-Client-Locale')).toBe('en-US');
    expect(fetch.mock.calls.every(call => call[1].signal === signal)).toBe(true);
  });

  it.each([{}, { activated: false }, { status: 'completed' }])('rejects malformed activation success %j', async (data) => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(reply(data)));
    await expect(coreAdminAuth.activate('token', 'password')).rejects.toBeInstanceOf(ApiResponseError);
  });

  it.each([{}, { status: 'password_change_required' }, { status: 'mfa_required', challengeToken: '' },
    { status: 'authenticated', accessToken: 'incomplete' }, { status: 'unknown' }])('rejects incomplete login %j', async (data) => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(reply(data)));
    await expect(coreAdminAuth.login('x@example.test', 'password')).rejects.toBeInstanceOf(ApiResponseError);
  });

  it('accepts a password-change challenge without requiring or fabricating tokens', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(reply({ status: 'password_change_required', challengeToken: 'challenge' })));
    expect(await coreAdminAuth.login('x@example.test', 'password')).toEqual({ status: 'password_change_required', challengeToken: 'challenge' });
  });

  it('preserves the MFA step returned after required password change', async () => {
    const fetch = vi.fn().mockResolvedValue(reply({ status: 'mfa_required', challengeToken: 'mfa' }));
    vi.stubGlobal('fetch', fetch);
    expect((await coreAdminAuth.completeRequiredPasswordChange('challenge', 'old password', 'new password')).status).toBe('mfa_required');
    expect(JSON.parse(fetch.mock.calls[0]![1].body)).toEqual({ challengeToken: 'challenge', currentPassword: 'old password', newPassword: 'new password' });
  });

  it('does not interpret external or additional MFA recovery as completed', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValueOnce(reply({ status: 'additional_recovery_required' }))
      .mockResolvedValueOnce(reply({ status: 'external_reauth_required', recoveryToken: 'proof', providers: ['google'], expiresAt: '2099-01-01' })));
    expect((await coreAdminAuth.confirmMfaRecovery('token', null)).status).toBe('additional_recovery_required');
    expect((await coreAdminAuth.confirmMfaRecovery('token', null)).status).toBe('external_reauth_required');
  });

  it('preserves invalid token codes for safe localization', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ status: 'error', error: { code: 'auth.password.reset_invalid', message: 'private details' } }), { status: 400 })));
    await expect(coreAdminAuth.confirmPasswordReset('token', 'password')).rejects.toMatchObject({ status: 400, code: 'auth.password.reset_invalid' } satisfies Partial<ApiError>);
  });

  it('distinguishes aborted and offline requests', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('fetch failed')));
    await expect(coreAdminAuth.requestPasswordReset('x@example.test')).rejects.toBeInstanceOf(NetworkError);
    const controller = new AbortController(); controller.abort();
    await expect(coreAdminAuth.requestPasswordReset('x@example.test', controller.signal)).rejects.toBeInstanceOf(RequestAbortedError);
  });
});
