import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { ApiError } from '@/shared/api/errors';
import { coreAdminAuth, type CoreAdminIdentity, type CoreAdminLoginResult } from '@/shared/api/core/adminAuth';
import { coreAdminSession, type CoreAdminTokens } from '@/core/admin/session';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';

type AdminStatus = 'anonymous' | 'authenticating' | 'mfa_required' | 'authenticated';

function tokensFromResult(result: CoreAdminLoginResult): CoreAdminTokens {
  if (
    typeof result.accessToken !== 'string'
    || typeof result.refreshToken !== 'string'
    || typeof result.accessExpiresAt !== 'string'
    || typeof result.sessionExpiresAt !== 'string'
    || typeof result.sessionId !== 'string'
  ) {
    throw new ApiError(502, 'unexpected_response', 'The authentication response is incomplete.');
  }
  return {
    accessToken: result.accessToken,
    refreshToken: result.refreshToken,
    accessExpiresAt: result.accessExpiresAt,
    sessionExpiresAt: result.sessionExpiresAt,
    sessionId: result.sessionId,
  };
}

export const useAdminAuthStore = defineStore('adminAuth', () => {
  const status = ref<AdminStatus>('anonymous');
  const identity = ref<CoreAdminIdentity | null>(null);
  const displayName = ref<string | null>(null);
  const challengeToken = ref<string | null>(null);
  const isAuthenticated = computed(() => status.value === 'authenticated' && identity.value !== null);
  const permissions = computed(() => identity.value?.permissions ?? []);
  const roles = computed(() => identity.value?.roles ?? []);

  coreAdminSession.setUnauthorizedHandler(() => {
    status.value = 'anonymous';
    identity.value = null;
    displayName.value = null;
    challengeToken.value = null;
  });

  function can(permission: string): boolean {
    return roles.value.includes('core.superadmin') || permissions.value.includes(permission);
  }

  async function establish(tokens: CoreAdminTokens): Promise<void> {
    coreAdminSession.set(tokens);
    try {
      identity.value = await coreAdminAuth.me(tokens.accessToken);
      status.value = 'authenticated';
      challengeToken.value = null;
      const personId = identity.value.account.personId;
      if (personId !== null) {
        void coreAdminHttp.get<Record<string, unknown>>(`/core/persons/${encodeURIComponent(personId)}`)
          .then((person) => {
            const display = typeof person.displayName === 'string' && person.displayName.trim() !== ''
              ? person.displayName
              : [person.givenName, person.familyName].filter(value => typeof value === 'string' && value.trim() !== '').join(' ');
            displayName.value = display === '' ? null : display;
          })
          .catch(() => { displayName.value = null; });
      }
    } catch (error) {
      coreAdminSession.clear();
      identity.value = null;
      displayName.value = null;
      status.value = 'anonymous';
      throw error;
    }
  }

  async function login(identifier: string, password: string): Promise<void> {
    status.value = 'authenticating';
    identity.value = null;
    displayName.value = null;
    challengeToken.value = null;
    try {
      const result = await coreAdminAuth.login(identifier.trim(), password);
      if (result.status === 'mfa_required') {
        if (typeof result.challengeToken !== 'string' || result.challengeToken === '') {
          throw new ApiError(502, 'unexpected_response', 'The MFA challenge is missing.');
        }
        challengeToken.value = result.challengeToken;
        status.value = 'mfa_required';
        return;
      }
      if (result.status === 'password_change_required') {
        status.value = 'anonymous';
        throw new ApiError(409, 'password_change_required', 'Change the password before using the admin console.');
      }
      await establish(tokensFromResult(result));
    } catch (error) {
      status.value = 'anonymous';
      throw error;
    }
  }

  async function verifyMfa(code: string | null, recoveryCode: string | null): Promise<void> {
    if (challengeToken.value === null) {
      throw new ApiError(409, 'mfa_challenge_missing', 'The MFA challenge is missing.');
    }
    status.value = 'authenticating';
    try {
      const result = await coreAdminAuth.verifyMfa(challengeToken.value, code, recoveryCode);
      await establish(tokensFromResult(result));
    } catch (error) {
      status.value = 'mfa_required';
      throw error;
    }
  }

  async function logout(): Promise<void> {
    const accessToken = coreAdminSession.accessToken();
    coreAdminSession.clear();
    identity.value = null;
    displayName.value = null;
    challengeToken.value = null;
    status.value = 'anonymous';
    if (accessToken !== null) {
      try {
        await coreAdminAuth.logout(accessToken);
      } catch {
        // Local session is already cleared.
      }
    }
  }

  return {
    status,
    identity,
    displayName,
    challengeToken,
    isAuthenticated,
    permissions,
    roles,
    can,
    login,
    verifyMfa,
    logout,
  };
});
