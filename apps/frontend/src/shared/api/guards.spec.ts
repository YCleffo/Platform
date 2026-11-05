import { describe, expect, it } from 'vitest';
import { isControlSessionList, isInvoice, isSubscription } from './guards';

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

describe('unpaid billing API contracts', () => {
  const subscription = {
    id: 'subscription', status: 'draft', billing_status: 'current', past_due_since: null,
    grace_until: null, auto_renew_enabled: true, renewal_required: false,
    commercial_account_id: 'account', billing_profile_id: 'profile',
    current_period_start: null, current_period_end: null,
    created_at: '2026-11-03T00:00:00Z', updated_at: '2026-11-03T00:00:00Z', items: [], renewal: null,
  };
  const invoice = {
    id: 'invoice', subscription_id: 'subscription', billing_profile_id: 'profile',
    billing_reason: 'subscription_create', service_period_start: null, service_period_end: null,
    status: 'issued', currency: 'RUB', total_minor: 1000, issued_at: '2026-11-03T00:00:00Z',
    paid_at: null, voided_at: null, created_at: '2026-11-03T00:00:00Z',
    bill_to: { legal_name: 'Company', country_code: 'RU', contact_name: null, contact_email: null, tax_identifier: null, address: {} },
    lines: [],
  };

  it('accepts persisted draft and initial invoice before a service period exists', () => {
    expect(isSubscription(subscription)).toBe(true);
    expect(isInvoice(invoice)).toBe(true);
  });

  it('requires both period dates on an active subscription', () => {
    expect(isSubscription({ ...subscription, status: 'active' })).toBe(false);
    expect(isSubscription({ ...subscription, status: 'active', current_period_start: '2026-11-03T00:00:00Z' })).toBe(false);
    expect(isSubscription({ ...subscription, status: 'active', current_period_start: '2026-11-03T00:00:00Z', current_period_end: '2026-12-03T00:00:00Z' })).toBe(true);
  });

  it('rejects malformed or missing nullable dates instead of fabricating a period', () => {
    expect(isSubscription({ ...subscription, current_period_start: 0 })).toBe(false);
    expect(isSubscription({ ...subscription, current_period_end: undefined })).toBe(false);
    expect(isInvoice({ ...invoice, service_period_start: {} })).toBe(false);
    expect(isInvoice({ ...invoice, service_period_end: undefined })).toBe(false);
  });
});
