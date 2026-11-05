import { describe, expect, it } from 'vitest';
import { isSavedPaymentMethodList } from './guards';
describe('saved payment method HTTP contract', () => {
  const method = { id: 'method', method_type: 'bank_card', status: 'active', title: null, card_brand: 'Visa', last4: '4242', expiry_month: null, expiry_year: null, is_default: true, created_at: '2026-11-05T00:00:00Z', last_used_at: null, disabled_at: null };
  it('accepts the documented nullable provider title after a successful paid checkout', () => {
    expect(isSavedPaymentMethodList({ items: [method] })).toBe(true);
  });
  it('continues to reject missing or malformed title and expiry values', () => {
    expect(isSavedPaymentMethodList({ items: [{ ...method, title: undefined }] })).toBe(false);
    expect(isSavedPaymentMethodList({ items: [{ ...method, title: {} }] })).toBe(false);
    expect(isSavedPaymentMethodList({ items: [{ ...method, expiry_year: '2030' }] })).toBe(false);
  });
});
