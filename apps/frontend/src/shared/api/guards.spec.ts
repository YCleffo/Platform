import { describe, expect, it } from 'vitest';
import { isControlSessionList } from './guards';

const freshSession = {
  id: '018f0000-0000-7000-8000-000000000001',
  issued_at: '2026-10-31 04:00:00+00',
  last_used_at: null,
  expires_at: '2026-11-30 04:00:00+00',
  revoked_at: null,
  revoke_reason: null,
  user_agent: null,
  authentication_method: 'password',
  primary_authenticated_at: '2026-10-31 04:00:00+00',
  mfa_authenticated_at: null,
  current: true,
};

describe('session API contract', () => {
  it('accepts a newly issued session before refresh, without a user agent', () => {
    expect(isControlSessionList({ items: [freshSession] })).toBe(true);
  });

  it.each([
    { last_used_at: 0 },
    { user_agent: {} },
    { current: 't' },
    { authentication_method: 'unknown' },
    { primary_authenticated_at: null },
  ])('rejects malformed session fields: %o', (fields) => {
    expect(isControlSessionList({ items: [{ ...freshSession, ...fields }] })).toBe(false);
  });
});
