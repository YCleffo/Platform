import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { commercialAccount, commercialAuth } from '@/core/api';
import { beginSession, endSession, hasSession, sessionTokens } from './session';
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

  const isAuthenticated = computed(() => status.value === 'authenticated' && sessionTokens.value !== null);
  const currentUser = computed(() => user.value);

  async function establishSession(tokens: SessionTokens): Promise<void> {
    beginSession(tokens);
    try {
      user.value = await commercialAccount.me();
      status.value = 'authenticated';
    } catch (error) {
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
    status.value = 'authenticating';
    mfaChallenge.value = null;
    try {
      const result = await commercialAuth.login(email, password);
      if (isMfaChallenge(result)) {
        mfaChallenge.value = result;
        status.value = 'mfa_required';
        return;
      }
      await establishSession(result);
    } catch (error) {
      status.value = mfaChallenge.value !== null ? 'mfa_required' : 'anonymous';
      throw error;
    }
  }

  async function verifyMfa(code?: string, recoveryCode?: string): Promise<void> {
    const challenge = mfaChallenge.value;
    if (challenge === null) {
      throw new MfaChallengeMissingError();
    }
    status.value = 'authenticating';
    try {
      const tokens = await commercialAuth.verifyMfaLogin(challenge.challenge_token, { code, recoveryCode });
      mfaChallenge.value = null;
      await establishSession(tokens);
    } catch (error) {
      status.value = mfaChallenge.value !== null ? 'mfa_required' : 'anonymous';
      throw error;
    }
  }

  async function refreshUser(): Promise<void> {
    if (status.value !== 'authenticated') {
      return;
    }
    user.value = await commercialAccount.me();
  }

  async function logout(): Promise<void> {
    status.value = 'signing_out';
    try {
      await commercialAuth.logout();
    } catch {
      // Local state must be cleared even when the server call fails.
    } finally {
      reset();
    }
  }

  function reset(): void {
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
