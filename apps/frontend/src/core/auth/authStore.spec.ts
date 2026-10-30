import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { commercialAccount, commercialAuth } from '@/core/api';
import { endSession, getAccessToken } from './session';
import { MfaChallengeMissingError, useAuthStore } from './authStore';
import { ApiError } from '@/shared/api/errors';
import type { CurrentUser, SessionTokens } from '@/shared/types';

vi.mock('@/core/api', () => ({
  commercialAuth: {
    login: vi.fn(),
    register: vi.fn(),
    resendVerification: vi.fn(),
    verifyEmail: vi.fn(),
    verifyMfaLogin: vi.fn(),
    logout: vi.fn(),
  },
  commercialAccount: {
    me: vi.fn(),
    listAccounts: vi.fn(),
    getAccount: vi.fn(),
    createAccount: vi.fn(),
  },
  commercialCatalog: { listPlans: vi.fn() },
}));

const user = (id: string): CurrentUser => ({
  id,
  email: `${id}@example.test`,
  status: 'active',
  email_verified_at: '2026-01-01T00:00:00Z',
  last_login_at: null,
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
});

const tokens = (suffix: string) => ({ access_token: `access-${suffix}`, refresh_token: `refresh-${suffix}` });

const mfaChallenge = { status: 'mfa_required', challenge_token: 'challenge-1', expires_in: 300 } as const;

describe('authStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    endSession();
    vi.resetAllMocks();
  });

  it('authenticates after login and loads the user context', async () => {
    vi.mocked(commercialAuth.login).mockResolvedValue(tokens('1'));
    vi.mocked(commercialAccount.me).mockResolvedValue(user('a'));

    const store = useAuthStore();
    await store.login('a@example.test', 'password12345');

    expect(store.status).toBe('authenticated');
    expect(store.isAuthenticated).toBe(true);
    expect(store.currentUser?.id).toBe('a');
    expect(getAccessToken()).toBe('access-1');
  });

  it('enters mfa_required without establishing a session', async () => {
    vi.mocked(commercialAuth.login).mockResolvedValue(mfaChallenge);

    const store = useAuthStore();
    await store.login('a@example.test', 'password12345');

    expect(store.status).toBe('mfa_required');
    expect(store.mfaChallenge?.challenge_token).toBe('challenge-1');
    expect(store.isAuthenticated).toBe(false);
    expect(getAccessToken()).toBeNull();
  });

  it('completes login after a successful MFA challenge', async () => {
    vi.mocked(commercialAuth.login).mockResolvedValue(mfaChallenge);
    vi.mocked(commercialAuth.verifyMfaLogin).mockResolvedValue(tokens('2'));
    vi.mocked(commercialAccount.me).mockResolvedValue(user('a'));

    const store = useAuthStore();
    await store.login('a@example.test', 'password12345');
    await store.verifyMfa('123456');

    expect(store.status).toBe('authenticated');
    expect(store.mfaChallenge).toBeNull();
    expect(getAccessToken()).toBe('access-2');
  });

  it('rolls back the session when loadMe fails after tokens were issued', async () => {
    vi.mocked(commercialAuth.login).mockResolvedValue(tokens('1'));
    vi.mocked(commercialAccount.me).mockRejectedValue(new ApiError(500, 'server_error', 'boom'));

    const store = useAuthStore();
    await expect(store.login('a@example.test', 'password12345')).rejects.toBeInstanceOf(ApiError);

    expect(store.status).toBe('anonymous');
    expect(store.isAuthenticated).toBe(false);
    expect(getAccessToken()).toBeNull();
  });

  it('clears all authentication state on logout', async () => {
    vi.mocked(commercialAuth.login).mockResolvedValue(tokens('1'));
    vi.mocked(commercialAccount.me).mockResolvedValue(user('a'));
    vi.mocked(commercialAuth.logout).mockResolvedValue(undefined);

    const store = useAuthStore();
    await store.login('a@example.test', 'password12345');
    await store.logout();

    expect(store.status).toBe('anonymous');
    expect(store.currentUser).toBeNull();
    expect(getAccessToken()).toBeNull();
  });

  it('never exposes the previous user after switching accounts', async () => {
    vi.mocked(commercialAuth.login).mockResolvedValue(tokens('1'));
    vi.mocked(commercialAccount.me)
      .mockResolvedValueOnce(user('a'))
      .mockResolvedValueOnce(user('b'));
    vi.mocked(commercialAuth.logout).mockResolvedValue(undefined);

    const store = useAuthStore();
    await store.login('a@example.test', 'password12345');
    expect(store.currentUser?.id).toBe('a');

    await store.logout();
    await store.login('b@example.test', 'password12345');
    expect(store.currentUser?.id).toBe('b');
  });

  it('rejects verifyMfa when no challenge is active', async () => {
    const store = useAuthStore();
    await expect(store.verifyMfa('123456')).rejects.toBeInstanceOf(MfaChallengeMissingError);
  });

  it('ignores a login that resolves after a newer login', async () => {
    let resolveFirst: (value: SessionTokens) => void = () => {};
    let resolveSecond: (value: SessionTokens) => void = () => {};
    const firstAttempt = new Promise<SessionTokens>((resolve) => {
      resolveFirst = resolve;
    });
    const secondAttempt = new Promise<SessionTokens>((resolve) => {
      resolveSecond = resolve;
    });
    vi.mocked(commercialAuth.login).mockReturnValueOnce(firstAttempt).mockReturnValueOnce(secondAttempt);
    vi.mocked(commercialAccount.me).mockResolvedValue(user('b'));

    const store = useAuthStore();
    const first = store.login('a@example.test', 'password12345');
    const second = store.login('b@example.test', 'password12345');

    resolveSecond(tokens('b'));
    await second;
    resolveFirst(tokens('a'));
    await first.catch(() => undefined);

    expect(store.status).toBe('authenticated');
    expect(getAccessToken()).toBe('access-b');
    expect(store.currentUser?.id).toBe('b');
  });

  it('ignores a login that finishes after logout', async () => {
    let resolveLogin: (value: SessionTokens) => void = () => {};
    const pending = new Promise<SessionTokens>((resolve) => {
      resolveLogin = resolve;
    });
    vi.mocked(commercialAuth.login).mockReturnValueOnce(pending);
    vi.mocked(commercialAuth.logout).mockResolvedValue(undefined);

    const store = useAuthStore();
    const login = store.login('a@example.test', 'password12345');
    await store.logout();
    resolveLogin(tokens('a'));
    await login.catch(() => undefined);

    expect(store.status).toBe('anonymous');
    expect(getAccessToken()).toBeNull();
    expect(store.currentUser).toBeNull();
  });

  it('ignores an MFA result after the session was reset', async () => {
    vi.mocked(commercialAuth.login).mockResolvedValue(mfaChallenge);
    let resolveVerify: (value: SessionTokens) => void = () => {};
    const pending = new Promise<SessionTokens>((resolve) => {
      resolveVerify = resolve;
    });
    vi.mocked(commercialAuth.verifyMfaLogin).mockReturnValueOnce(pending);

    const store = useAuthStore();
    await store.login('a@example.test', 'password12345');
    expect(store.status).toBe('mfa_required');

    const verify = store.verifyMfa('123456');
    store.reset();
    resolveVerify(tokens('mfa'));
    await verify.catch(() => undefined);

    expect(store.status).toBe('anonymous');
    expect(getAccessToken()).toBeNull();
  });
});
