import { beforeEach, describe, expect, it } from 'vitest';
import {
  beginSession,
  endSession,
  getAccessToken,
  getRefreshToken,
  getSessionEpoch,
  rotateSession,
} from './session';

const tokens = (access: string, refresh: string) => ({ access_token: access, refresh_token: refresh });

describe('session', () => {
  beforeEach(() => {
    endSession();
  });

  it('stores tokens on beginSession and starts a new epoch', () => {
    const before = getSessionEpoch();
    beginSession(tokens('a', 'r'));
    expect(getAccessToken()).toBe('a');
    expect(getRefreshToken()).toBe('r');
    expect(getSessionEpoch()).toBeGreaterThan(before);
  });

  it('rotates tokens only for the current epoch', () => {
    beginSession(tokens('a', 'r'));
    const epoch = getSessionEpoch();
    expect(rotateSession(tokens('a2', 'r2'), epoch)).toBe(true);
    expect(getAccessToken()).toBe('a2');

    endSession();
    expect(rotateSession(tokens('a3', 'r3'), epoch)).toBe(false);
    expect(getAccessToken()).toBeNull();
  });

  it('clears tokens and invalidates the epoch on endSession', () => {
    beginSession(tokens('a', 'r'));
    const epoch = getSessionEpoch();
    endSession();
    expect(getAccessToken()).toBeNull();
    expect(getSessionEpoch()).not.toBe(epoch);
  });
});
