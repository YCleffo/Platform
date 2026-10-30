import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { commercialAccount, commercialAuth } from '@/core/api';
import { SupersededOperationError } from '@/shared/api/errors';
import { beginSession, endSession, getAccessToken, getSessionEpoch, hasSession, sessionTokens } from './session';
import type { CurrentUser, LoginResult, MfaChallenge, RegisterResult, SessionTokens, StatusResult } from '@/shared/types';

export type AuthStatus = 'anonymous' | 'authenticating' | 'mfa_required' | 'authenticated' | 'signing_out';

export class MfaChallengeMissingError extends Error {
  constructor() {
    super('MFA challenge is not active.');
    this.name = 'MfaChallengeMissingError';
  }
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<CurrentUser | null>(null);
  const mfaChallenge = ref<MfaChallenge | null>(null);
  const status = ref<AuthStatus>(hasSession() ? 'authenticated' : 'anonymous');

  // Monotonic token: a login/MFA/logout that is superseded must never apply its result.
  let operationId = 0;
  let refreshUserId = 0;

  const isAuthenticated = computed(() => status.value === 'authenticated' && sessionTokens.value !== null);
  const currentUser = computed(() => user.value);

  async function establishSession(tokens: SessionTokens, operation: number): Promise<void> {
    beginSession(tokens);
    try {
      const me = await commercialAccount.me();
      if (operation !== operationId) {
        return;
      }
      user.value = me;
      status.value = 'authenticated';
    } catch (error) {
      if (operation !== operationId) {
        return;
      }
      // Never leave the app "authenticated" without a loaded user context.
      endSession();
      user.value = null;
      status.value = 'anonymous';
      throw error;
    }
  }

  async function register(email: string, password: string): Promise<RegisterResult> {
    return commercialAuth.register(email, password);
  }

  async function resendVerification(email: string): Promise<StatusResult> {
    return commercialAuth.resendVerification(email);
  }

  async function verifyEmail(token: string): Promise<StatusResult> {
    return commercialAuth.verifyEmail(token);
  }

  async function login(email: string, password: string): Promise<void> {
    const operation = ++operationId;
    status.value = 'authenticating';
    mfaChallenge.value = null;
    try {
      const result = await commercialAuth.login(email, password);
      if (operation !== operationId) {
        throw new SupersededOperationError();
      }
      if (isMfaChallenge(result)) {
        mfaChallenge.value = result;
        status.value = 'mfa_required';
        return;
      }
      await establishSession(result, operation);
    } catch (error) {
      if (operation !== operationId) {
        throw new SupersededOperationError();
      }
      status.value = mfaChallenge.value !== null ? 'mfa_required' : 'anonymous';
      throw error;
    }
  }

  async function verifyMfa(code?: string, recoveryCode?: string): Promise<void> {
    const challenge = mfaChallenge.value;
    if (challenge === null) {
      throw new MfaChallengeMissingError();
    }
    const operation = ++operationId;
    status.value = 'authenticating';
    try {
      const tokens = await commercialAuth.verifyMfaLogin(challenge.challenge_token, { code, recoveryCode });
      if (operation !== operationId) {
        throw new SupersededOperationError();
      }
      mfaChallenge.value = null;
      await establishSession(tokens, operation);
    } catch (error) {
      if (operation !== operationId) {
        throw new SupersededOperationError();
      }
      status.value = mfaChallenge.value !== null ? 'mfa_required' : 'anonymous';
      throw error;
    }
  }

  async function refreshUser(): Promise<void> {
    if (status.value !== 'authenticated') {
      return;
    }
    const operation = operationId;
    const epoch = getSessionEpoch();
    const refresh = ++refreshUserId;
    let me: CurrentUser;
    try {
      me = await commercialAccount.me();
    } catch (error) {
      if (operation !== operationId || epoch !== getSessionEpoch() || refresh !== refreshUserId) {
        return;
      }
      throw error;
    }
    if (
      operation !== operationId ||
      epoch !== getSessionEpoch() ||
      refresh !== refreshUserId ||
      status.value !== 'authenticated'
    ) {
      return;
    }
    user.value = me;
  }

  /**
   * Local logout is immediate. The captured token is used only for the revocation request;
   * a late or failed response can never affect a newer session.
   */
  function logout(): void {
    const accessToken = getAccessToken();
    operationId += 1;
    status.value = 'signing_out';
    reset();
    if (accessToken !== null) {
      void Promise.resolve(commercialAuth.logout(accessToken)).catch(() => undefined);
    }
  }

  function reset(): void {
    operationId += 1;
    endSession();
    user.value = null;
    mfaChallenge.value = null;
    status.value = 'anonymous';
  }

  return {
    user,
    mfaChallenge,
    status,
    isAuthenticated,
    currentUser,
    register,
    resendVerification,
    verifyEmail,
    login,
    verifyMfa,
    refreshUser,
    logout,
    reset,
  };
});

function isMfaChallenge(result: LoginResult): result is MfaChallenge {
  return 'status' in result && result.status === 'mfa_required';
}
