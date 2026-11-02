export interface CoreAdminTokens {
  accessToken: string;
  refreshToken: string;
  accessExpiresAt: string;
  sessionExpiresAt: string;
  sessionId: string;
}

let tokens: CoreAdminTokens | null = null;
let epoch = 0;
let unauthorizedHandler: (() => void) | null = null;

export const coreAdminSession = {
  accessToken(): string | null {
    return tokens?.accessToken ?? null;
  },
  refreshToken(): string | null {
    return tokens?.refreshToken ?? null;
  },
  epoch(): number {
    return epoch;
  },
  set(next: CoreAdminTokens): void {
    tokens = next;
    epoch += 1;
  },
  clear(): void {
    tokens = null;
    epoch += 1;
  },
  setUnauthorizedHandler(handler: () => void): void {
    unauthorizedHandler = handler;
  },
  notifyUnauthorized(): void {
    tokens = null;
    epoch += 1;
    unauthorizedHandler?.();
  },
};
