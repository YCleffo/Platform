import { readonly, ref, shallowRef } from 'vue';
import type { SessionTokens } from '@/shared/types';

// Single in-memory owner of authentication tokens.
// Tokens never touch localStorage/sessionStorage/IndexedDB.
let epoch = 0;
const epochRef = ref(0);
const tokens = shallowRef<SessionTokens | null>(null);

export const sessionEpoch = readonly(epochRef);
export const sessionTokens = readonly(tokens);

function bumpEpoch(): void {
  epoch += 1;
  epochRef.value = epoch;
}

export function getSessionEpoch(): number {
  return epoch;
}

export function hasSession(): boolean {
  return tokens.value !== null;
}

export function getAccessToken(): string | null {
  return tokens.value?.access_token ?? null;
}

export function getRefreshToken(): string | null {
  return tokens.value?.refresh_token ?? null;
}

/** Starts a brand-new session (login / MFA success). Invalidates any in-flight refresh. */
export function beginSession(next: SessionTokens): void {
  bumpEpoch();
  tokens.value = next;
}

/**
 * Applies refreshed tokens only if the session identity is unchanged since the refresh started.
 * Returns false when the session was replaced or cleared meanwhile (logout / user switch).
 */
export function rotateSession(next: SessionTokens, expectedEpoch: number): boolean {
  if (epoch !== expectedEpoch || tokens.value === null) {
    return false;
  }
  tokens.value = next;
  return true;
}

/** Ends the session and invalidates every in-flight request/refresh via the epoch. */
export function endSession(): void {
  bumpEpoch();
  tokens.value = null;
}

type UnauthorizedHandler = () => void;

let unauthorizedHandler: UnauthorizedHandler = () => {};

export function setUnauthorizedHandler(handler: UnauthorizedHandler): void {
  unauthorizedHandler = handler;
}

export function runUnauthorizedHandler(): void {
  unauthorizedHandler();
}
