import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { coreAdminAuth, type CoreAdminLoginResult, type CoreAdminIdentity } from '@/shared/api/core/adminAuth';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { coreAdminSession } from './session';
import { useAdminAuthStore } from './adminAuthStore';
import { ApiError, SupersededOperationError } from '@/shared/api/errors';
import { authenticatePasskey } from '@/shared/webauthn';

vi.mock('@/shared/api/core/adminAuth', () => ({ coreAdminAuth: {
  login: vi.fn(), me: vi.fn(), verifyMfa: vi.fn(), completeRequiredPasswordChange: vi.fn(), logout: vi.fn(),
  startPasskeyAuthentication: vi.fn(), completePasskeyAuthentication: vi.fn(),
} }));
vi.mock('@/shared/api/core/adminHttp', () => ({ coreAdminHttp: { get: vi.fn() } }));
vi.mock('@/shared/webauthn', () => ({ authenticatePasskey: vi.fn() }));
const tokens = (id: string): CoreAdminLoginResult => ({ status: 'authenticated', accessToken: `access-${id}`, refreshToken: `refresh-${id}`,
  accessExpiresAt: '2099-01-01', sessionExpiresAt: '2099-01-01', sessionId: `session-${id}` });
const me = (id: string, personId: string | null = null): CoreAdminIdentity => ({ account: { id, personId }, roles: [], permissions: [], locale: 'en', timezone: 'UTC', sessionId: `session-${id}` });
function deferred<T>() {
  let resolve!: (value: T) => void; let reject!: (error: unknown) => void;
  const promise = new Promise<T>((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}
beforeEach(() => { setActivePinia(createPinia()); coreAdminSession.clear(); vi.resetAllMocks(); });

describe('Core sign-in lifecycle', () => {
  it('keeps passkey MFA continuation in the same authentication state machine', async () => {
    vi.mocked(coreAdminAuth.startPasskeyAuthentication).mockResolvedValue({ challenge: 'AQID', rpId: 'localhost', timeout: 300000, userVerification: 'required' });
    vi.mocked(authenticatePasskey).mockResolvedValue({ type: 'public-key' });
    vi.mocked(coreAdminAuth.completePasskeyAuthentication).mockResolvedValue({ status: 'mfa_required', challengeToken: 'passkey-mfa' });
    const auth = useAdminAuthStore(); await auth.loginWithPasskey();
    expect(auth.status).toBe('mfa_required'); expect(auth.challengeToken).toBe('passkey-mfa'); expect(coreAdminSession.accessToken()).toBeNull();
    expect(coreAdminAuth.me).not.toHaveBeenCalled();
    vi.mocked(coreAdminAuth.verifyMfa).mockResolvedValue(tokens('a')); vi.mocked(coreAdminAuth.me).mockResolvedValue(me('a'));
    await auth.verifyMfa('123456', null); expect(auth.isAuthenticated).toBe(true);
  });
  it('aborts a native passkey prompt on cancellation and ignores a late credential', async () => {
    const native = deferred<Record<string, unknown>>();
    vi.mocked(coreAdminAuth.startPasskeyAuthentication).mockResolvedValue({ challenge: 'AQID', rpId: 'localhost', timeout: 300000, userVerification: 'required' });
    vi.mocked(authenticatePasskey).mockReturnValue(native.promise);
    const auth = useAdminAuthStore(); const done = auth.loginWithPasskey().catch(error => error);
    await vi.waitFor(() => expect(authenticatePasskey).toHaveBeenCalled());
    const signal = vi.mocked(authenticatePasskey).mock.calls[0]![1]; auth.cancelPendingAuthentication();
    expect(signal.aborted).toBe(true); native.resolve({ type: 'public-key' });
    expect(await done).toBeInstanceOf(SupersededOperationError); expect(coreAdminAuth.completePasskeyAuthentication).not.toHaveBeenCalled(); expect(auth.isAuthenticated).toBe(false);
  });
  it('retains the password-change challenge without creating a session', async () => {
    vi.mocked(coreAdminAuth.login).mockResolvedValue({ status: 'password_change_required', challengeToken: 'change' });
    const auth = useAdminAuthStore(); await auth.login(' a@example.test ', 'old password');
    expect(auth.status).toBe('password_change_required'); expect(auth.challengeToken).toBe('change');
    expect(coreAdminSession.accessToken()).toBeNull(); expect(coreAdminAuth.me).not.toHaveBeenCalled();
  });

  it('continues required password change through MFA before establishing a session', async () => {
    vi.mocked(coreAdminAuth.login).mockResolvedValue({ status: 'password_change_required', challengeToken: 'change' });
    vi.mocked(coreAdminAuth.completeRequiredPasswordChange).mockResolvedValue({ status: 'mfa_required', challengeToken: 'mfa' });
    vi.mocked(coreAdminAuth.verifyMfa).mockResolvedValue(tokens('a')); vi.mocked(coreAdminAuth.me).mockResolvedValue(me('a'));
    const auth = useAdminAuthStore(); await auth.login('a@example.test', 'old password');
    await auth.completeRequiredPasswordChange('old password', 'new password');
    expect(auth.status).toBe('mfa_required'); expect(coreAdminSession.accessToken()).toBeNull();
    await auth.verifyMfa('123456', null);
    expect(auth.isAuthenticated).toBe(true); expect(auth.challengeToken).toBeNull();
    expect(coreAdminAuth.verifyMfa).toHaveBeenCalledWith('mfa', '123456', null, expect.any(AbortSignal));
  });

  it('keeps the required-change form and challenge on a rejected current password', async () => {
    vi.mocked(coreAdminAuth.login).mockResolvedValue({ status: 'password_change_required', challengeToken: 'change' });
    vi.mocked(coreAdminAuth.completeRequiredPasswordChange).mockRejectedValue(new ApiError(422, 'auth.invalid_credentials', 'rejected'));
    const auth = useAdminAuthStore(); await auth.login('a@example.test', 'old password');
    await expect(auth.completeRequiredPasswordChange('wrong', 'new password')).rejects.toBeInstanceOf(ApiError);
    expect(auth.status).toBe('password_change_required'); expect(auth.challengeToken).toBe('change'); expect(auth.isAuthenticated).toBe(false);
  });

  it.each(['logout', 'cancel', 'unauthorized'] as const)('ignores a late login after %s', async (reason) => {
    const login = deferred<CoreAdminLoginResult>(); vi.mocked(coreAdminAuth.login).mockReturnValue(login.promise);
    const auth = useAdminAuthStore(); const pending = auth.login('a@example.test', 'password');
    if (reason === 'logout') await auth.logout();
    else if (reason === 'cancel') auth.cancelPendingAuthentication(); else coreAdminSession.notifyUnauthorized();
    login.resolve(tokens('a'));
    await expect(pending).rejects.toBeInstanceOf(SupersededOperationError);
    expect(auth.status).toBe('anonymous'); expect(coreAdminSession.accessToken()).toBeNull(); expect(coreAdminAuth.me).not.toHaveBeenCalled();
  });

  it('does not let an old failure clear a newer successful login', async () => {
    const old = deferred<CoreAdminLoginResult>(); vi.mocked(coreAdminAuth.login).mockReturnValueOnce(old.promise).mockResolvedValueOnce(tokens('b'));
    vi.mocked(coreAdminAuth.me).mockResolvedValue(me('b'));
    const auth = useAdminAuthStore(); const first = auth.login('a@example.test', 'password');
    await auth.login('b@example.test', 'password'); old.reject(new Error('old failure'));
    await expect(first).rejects.toThrow('old failure'); expect(auth.identity?.account.id).toBe('b'); expect(coreAdminSession.accessToken()).toBe('access-b');
  });

  it.each(['mfa_required', 'password_change_required'] as const)('ignores a late %s completion after navigation', async (status) => {
    vi.mocked(coreAdminAuth.login).mockResolvedValue({ status, challengeToken: 'challenge' });
    const completion = deferred<CoreAdminLoginResult>();
    vi.mocked(coreAdminAuth.verifyMfa).mockReturnValue(completion.promise);
    vi.mocked(coreAdminAuth.completeRequiredPasswordChange).mockReturnValue(completion.promise);
    const auth = useAdminAuthStore(); await auth.login('a@example.test', 'password');
    const pending = status === 'mfa_required' ? auth.verifyMfa('123456', null)
      : auth.completeRequiredPasswordChange('old password', 'new password');
    auth.cancelPendingAuthentication(); completion.resolve(tokens('a'));
    await expect(pending).rejects.toBeInstanceOf(SupersededOperationError);
    expect(auth.status).toBe('anonymous'); expect(auth.identity).toBeNull();
    expect(coreAdminSession.accessToken()).toBeNull(); expect(coreAdminAuth.me).not.toHaveBeenCalled();
  });

  it('ignores a me response arriving after logout', async () => {
    const identity = deferred<CoreAdminIdentity>(); vi.mocked(coreAdminAuth.login).mockResolvedValue(tokens('a')); vi.mocked(coreAdminAuth.me).mockReturnValue(identity.promise);
    const auth = useAdminAuthStore(); const login = auth.login('a@example.test', 'password'); await Promise.resolve(); await Promise.resolve();
    await auth.logout(); identity.resolve(me('a'));
    await expect(login).rejects.toBeInstanceOf(SupersededOperationError);
    expect(auth.identity).toBeNull(); expect(coreAdminSession.accessToken()).toBeNull();
  });

  it('ignores late person decoration from a previous session', async () => {
    const person = deferred<Record<string, unknown>>(); vi.mocked(coreAdminHttp.get).mockReturnValue(person.promise);
    vi.mocked(coreAdminAuth.login).mockResolvedValueOnce(tokens('a')).mockResolvedValueOnce(tokens('b'));
    vi.mocked(coreAdminAuth.me).mockResolvedValueOnce({ ...me('a', 'person-a'), permissions: ['core.persons.read'] }).mockResolvedValueOnce(me('b'));
    const auth = useAdminAuthStore(); await auth.login('a@example.test', 'password'); await auth.logout(); await auth.login('b@example.test', 'password');
    person.resolve({ displayName: 'Previous user' }); await Promise.resolve(); await Promise.resolve();
    expect(coreAdminHttp.get).toHaveBeenCalledTimes(1);
    expect(auth.identity?.account.id).toBe('b'); expect(auth.displayName).toBeNull();
  });

  it('does not request the global Person API for an Education teacher without directory permission', async () => {
    vi.mocked(coreAdminAuth.login).mockResolvedValue(tokens('teacher'));
    vi.mocked(coreAdminAuth.me).mockResolvedValue({ ...me('teacher', 'teacher-person'), roles: ['education.teacher'] });
    const auth = useAdminAuthStore(); await auth.login('teacher@example.test', 'password');
    expect(auth.isAuthenticated).toBe(true); expect(coreAdminHttp.get).not.toHaveBeenCalled();
  });

  it('does not remove an established session when its login view unmounts', async () => {
    vi.mocked(coreAdminAuth.login).mockResolvedValue(tokens('a')); vi.mocked(coreAdminAuth.me).mockResolvedValue(me('a'));
    const auth = useAdminAuthStore(); await auth.login('a@example.test', 'password'); auth.cancelPendingAuthentication();
    expect(auth.isAuthenticated).toBe(true); expect(coreAdminSession.accessToken()).toBe('access-a');
  });
});
