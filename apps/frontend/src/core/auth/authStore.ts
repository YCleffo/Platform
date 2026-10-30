import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { commercialAccount, commercialAuth } from '@/shared/api/commercial';
import { clearSession, getAccessToken, setSessionTokens } from './session';
import type { CurrentUser, LoginResult, MfaChallenge, RegisterResult, SessionTokens } from '@/shared/types';

export const useAuthStore = defineStore('auth', () => {
  const user = ref<CurrentUser | null>(null);
  const mfaChallenge = ref<MfaChallenge | null>(null);
  const accessToken = ref<string | null>(getAccessToken());

  const isAuthenticated = computed(() => accessToken.value !== null);
  const currentUser = computed(() => user.value);

  function applyTokens(tokens: SessionTokens | null): void {
    setSessionTokens(tokens);
    accessToken.value = tokens?.access_token ?? null;
  }

  async function register(email: string, password: string): Promise<RegisterResult> {
    return commercialAuth.register(email, password);
  }

  async function login(email: string, password: string): Promise<void> {
    const result = await commercialAuth.login(email, password);
    if (isMfaChallenge(result)) {
      mfaChallenge.value = result;
      return;
    }
    applyTokens(result);
    await loadMe();
  }

  async function verifyMfa(code?: string, recoveryCode?: string): Promise<void> {
    const challenge = mfaChallenge.value;
    if (challenge === null) {
      throw new Error('MFA challenge is not active.');
    }
    const tokens = await commercialAuth.verifyMfaLogin(challenge.challenge_token, { code, recoveryCode });
    mfaChallenge.value = null;
    applyTokens(tokens);
    await loadMe();
  }

  async function loadMe(): Promise<void> {
    user.value = await commercialAccount.me();
  }

  async function logout(): Promise<void> {
    try {
      await commercialAuth.logout();
    } finally {
      reset();
    }
  }

  function reset(): void {
    clearSession();
    applyTokens(null);
    user.value = null;
    mfaChallenge.value = null;
  }

  return {
    user,
    mfaChallenge,
    isAuthenticated,
    currentUser,
    register,
    login,
    verifyMfa,
    loadMe,
    logout,
    reset,
  };
});

function isMfaChallenge(result: LoginResult): result is MfaChallenge {
  return 'status' in result && result.status === 'mfa_required';
}
