import { readonly, shallowRef } from 'vue';
import { resetSessionLocales } from '@/shared/i18n';

export interface CoreAdminTokens {
  accessToken: string;
  refreshToken: string;
  accessExpiresAt: string;
  sessionExpiresAt: string;
  sessionId: string;
}

let tokens: CoreAdminTokens | null = null;
const epoch = shallowRef(0);
export const coreAdminSessionEpoch = readonly(epoch);
let unauthorizedHandler: (() => void) | null = null;

export const coreAdminSession = {
  accessToken(): string | null {
    return tokens?.accessToken ?? null;
  },
  refreshToken(): string | null {
    return tokens?.refreshToken ?? null;
  },
  epoch(): number {
    return epoch.value;
  },
  set(next: CoreAdminTokens): void {
    tokens = next;
    resetSessionLocales();
    epoch.value += 1;
  },
  rotate(next: CoreAdminTokens, expectedEpoch: number, expectedRefreshToken: string): boolean {
    if (epoch.value !== expectedEpoch || tokens === null || tokens.refreshToken !== expectedRefreshToken
      || tokens.sessionId !== next.sessionId) return false;
    tokens = next;
    return true;
  },
  clear(): void {
    tokens = null;
    resetSessionLocales();
    epoch.value += 1;
  },
  setUnauthorizedHandler(handler: () => void): void {
    unauthorizedHandler = handler;
  },
  notifyUnauthorized(): void {
    coreAdminSession.clear();
    unauthorizedHandler?.();
  },
};
