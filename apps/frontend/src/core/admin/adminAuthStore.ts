import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { ApiError, SupersededOperationError } from '@/shared/api/errors';
import { coreAdminAuth, type CoreAdminIdentity, type CoreAdminLoginResult, type CoreExternalProvider } from '@/shared/api/core/adminAuth';
import { coreAdminSession, type CoreAdminTokens } from '@/core/admin/session';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { authenticatePasskey } from '@/shared/webauthn';
import { applyAccountLocale } from '@/shared/i18n';

type AdminStatus = 'anonymous' | 'authenticating' | 'mfa_required' | 'password_change_required' | 'authenticated';
interface Operation { generation: number; controller: AbortController }

function tokensFromResult(result: CoreAdminLoginResult): CoreAdminTokens {
  if (![result.accessToken, result.refreshToken, result.accessExpiresAt, result.sessionExpiresAt, result.sessionId]
    .every(value => typeof value === 'string' && value !== '')) {
    throw new ApiError(502, 'unexpected_response', 'The authentication response is incomplete.');
  }
  return result as CoreAdminTokens;
}

export const useAdminAuthStore = defineStore('adminAuth', () => {
  const status = ref<AdminStatus>('anonymous');
  const identity = ref<CoreAdminIdentity | null>(null);
  const displayName = ref<string | null>(null);
  const challengeToken = ref<string | null>(null);
  const isAuthenticated = computed(() => status.value === 'authenticated' && identity.value !== null);
  const permissions = computed(() => identity.value?.permissions ?? []);
  const roles = computed(() => identity.value?.roles ?? []);
  let generation = 0;
  let pending: Operation | null = null;

  function invalidate(): void {
    generation += 1;
    pending?.controller.abort();
    pending = null;
  }

  function resetIdentity(): void {
    status.value = 'anonymous';
    identity.value = null;
    displayName.value = null;
    challengeToken.value = null;
  }

  coreAdminSession.setUnauthorizedHandler(() => {
    invalidate();
    resetIdentity();
  });

  function can(permission: string): boolean {
    return roles.value.includes('core.superadmin') || permissions.value.includes(permission);
  }

  function begin(): Operation {
    invalidate();
    const operation = { generation, controller: new AbortController() };
    pending = operation;
    return operation;
  }

  function current(operation: Operation): boolean {
    return operation.generation === generation && !operation.controller.signal.aborted;
  }

  function assertCurrent(operation: Operation): void {
    if (!current(operation)) throw new SupersededOperationError();
  }

  async function accept(result: CoreAdminLoginResult, operation: Operation): Promise<void> {
    assertCurrent(operation);
    if (result.status === 'mfa_required' || result.status === 'password_change_required') {
      if (!result.challengeToken) throw new ApiError(502, 'unexpected_response', 'The challenge is missing.');
      challengeToken.value = result.challengeToken;
      status.value = result.status;
      return;
    }
    if (result.status !== 'authenticated') throw new ApiError(502, 'unexpected_response', 'Unknown authentication status.');
    const tokens = tokensFromResult(result);
    coreAdminSession.set(tokens);
    const epoch = coreAdminSession.epoch();
    const me = await coreAdminAuth.me(tokens.accessToken, operation.controller.signal);
    assertCurrent(operation);
    if (coreAdminSession.epoch() !== epoch) throw new SupersededOperationError();
    identity.value = me;
    applyAccountLocale(me.locale);
    status.value = 'authenticated';
    challengeToken.value = null;
    if (me.account.personId !== null && (me.roles.includes('core.superadmin') || me.permissions.includes('core.persons.read'))) {
      void coreAdminHttp.get<Record<string, unknown>>(`/core/persons/${encodeURIComponent(me.account.personId)}`)
        .then((person) => {
          if (!current(operation) || coreAdminSession.epoch() !== epoch) return;
          const display = typeof person.displayName === 'string' && person.displayName.trim() !== ''
            ? person.displayName
            : [person.givenName, person.familyName].filter(value => typeof value === 'string' && value.trim() !== '').join(' ');
          displayName.value = display === '' ? null : display;
        })
        .catch(() => { /* Decoration is optional; never alter a newer identity on failure. */ });
    }
  }

  async function login(identifier: string, password: string): Promise<void> {
    const operation = begin();
    coreAdminSession.clear();
    resetIdentity();
    status.value = 'authenticating';
    try {
      await accept(await coreAdminAuth.login(identifier.trim(), password, operation.controller.signal), operation);
    } catch (error) {
      if (current(operation)) {
        coreAdminSession.clear();
        resetIdentity();
      }
      throw error;
    } finally {
      if (current(operation)) pending = null;
    }
  }

  async function continueChallenge(kind: 'mfa_required' | 'password_change_required',
    action: (token: string, signal: AbortSignal) => Promise<CoreAdminLoginResult>): Promise<void> {
    const token = challengeToken.value;
    if (status.value !== kind || token === null) {
      throw new ApiError(409, 'core_challenge_missing', 'Start sign-in again.');
    }
    const operation = begin();
    try {
      await accept(await action(token, operation.controller.signal), operation);
    } catch (error) {
      if (current(operation)) {
        coreAdminSession.clear();
        identity.value = null;
        displayName.value = null;
        status.value = kind;
        challengeToken.value = token;
      }
      throw error;
    } finally {
      if (current(operation)) pending = null;
    }
  }

  async function loginWithPasskey(): Promise<void> {
    const operation = begin(); coreAdminSession.clear(); resetIdentity(); status.value = 'authenticating';
    try {
      const options = await coreAdminAuth.startPasskeyAuthentication(operation.controller.signal);
      assertCurrent(operation);
      const credential = await authenticatePasskey(options, operation.controller.signal);
      assertCurrent(operation);
      await accept(await coreAdminAuth.completePasskeyAuthentication(credential, operation.controller.signal), operation);
    } catch (error) {
      if (current(operation)) { coreAdminSession.clear(); resetIdentity(); }
      throw error;
    } finally { if (current(operation)) pending = null; }
  }

  async function completeExternalLogin(provider: CoreExternalProvider, state: string, code: string): Promise<void> {
    const operation = begin(); coreAdminSession.clear(); resetIdentity(); status.value = 'authenticating';
    try { await accept(await coreAdminAuth.completeExternalLogin(provider, state, code, operation.controller.signal), operation); }
    catch (error) { if (current(operation)) { coreAdminSession.clear(); resetIdentity(); } throw error; }
    finally { if (current(operation)) pending = null; }
  }

  async function verifyMfa(code: string | null, recoveryCode: string | null): Promise<void> {
    await continueChallenge('mfa_required', (token, signal) => coreAdminAuth.verifyMfa(token, code, recoveryCode, signal));
  }

  async function completeRequiredPasswordChange(currentPassword: string, newPassword: string): Promise<void> {
    await continueChallenge('password_change_required', (token, signal) =>
      coreAdminAuth.completeRequiredPasswordChange(token, currentPassword, newPassword, signal));
  }

  function cancelPendingAuthentication(): void {
    if (pending !== null || !isAuthenticated.value) {
      invalidate();
      coreAdminSession.clear();
      resetIdentity();
    }
  }

  async function logout(): Promise<void> {
    const accessToken = coreAdminSession.accessToken();
    invalidate();
    coreAdminSession.clear();
    resetIdentity();
    if (accessToken !== null) {
      try { await coreAdminAuth.logout(accessToken); } catch { /* Local session is already cleared. */ }
    }
  }

  return { status, identity, displayName, challengeToken, isAuthenticated, permissions, roles, can,
    login, loginWithPasskey, verifyMfa, completeRequiredPasswordChange, completeExternalLogin, cancelPendingAuthentication, logout };
});
