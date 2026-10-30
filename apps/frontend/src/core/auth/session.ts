import type { SessionTokens } from '@/shared/types';

let tokens: SessionTokens | null = null;
let unauthorizedHandler: () => void = () => {};

export function setSessionTokens(next: SessionTokens | null): void {
  tokens = next;
}

export function getAccessToken(): string | null {
  return tokens?.access_token ?? null;
}

export function getRefreshToken(): string | null {
  return tokens?.refresh_token ?? null;
}

export function clearSession(): void {
  tokens = null;
}

export function isAuthenticated(): boolean {
  return getAccessToken() !== null;
}

export function setUnauthorizedHandler(handler: () => void): void {
  unauthorizedHandler = handler;
}

export function runUnauthorizedHandler(): void {
  unauthorizedHandler();
}
