import { beforeEach, describe, expect, it } from 'vitest';
import {
  captureInvitationTokenFromLocation,
  clearInvitationToken,
  getInvitationToken,
  setInvitationToken,
} from './invitationSession';

describe('invitationSession', () => {
  beforeEach(() => {
    clearInvitationToken();
    window.history.replaceState(null, '', '/invitation');
  });

  it('captures the token from the fragment and removes the fragment', () => {
    window.history.replaceState(null, '', '/invitation#token=secret-token');

    expect(captureInvitationTokenFromLocation()).toBe('secret-token');
    expect(getInvitationToken()).toBe('secret-token');
    expect(window.location.hash).toBe('');
    expect(localStorage.length).toBe(0);
    expect(sessionStorage.length).toBe(0);
  });

  it('falls back to the in-memory token when the fragment is absent', () => {
    setInvitationToken('memory-token');

    expect(captureInvitationTokenFromLocation()).toBe('memory-token');
  });

  it('clears the in-memory token on demand', () => {
    setInvitationToken('x');
    clearInvitationToken();

    expect(getInvitationToken()).toBeNull();
  });
});
