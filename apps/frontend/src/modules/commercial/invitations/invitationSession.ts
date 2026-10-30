let invitationToken: string | null = null;

/** The secret invitation token lives only in memory. */
export function setInvitationToken(value: string | null): void {
  invitationToken = value;
}

export function getInvitationToken(): string | null {
  return invitationToken;
}

export function clearInvitationToken(): void {
  invitationToken = null;
}

/**
 * Captures `#token=...` from the URL fragment, keeps it in memory and removes the
 * fragment from the address bar. Never writes the token to storage or a query string.
 */
export function captureInvitationTokenFromLocation(): string | null {
  if (typeof window === 'undefined') {
    return invitationToken;
  }
  const hash = window.location.hash;
  if (hash.length > 1) {
    const params = new URLSearchParams(hash.slice(1));
    const incoming = params.get('token');
    if (incoming !== null && incoming !== '') {
      invitationToken = incoming;
    }
    window.history.replaceState(null, '', window.location.pathname + window.location.search);
  }
  return invitationToken;
}
