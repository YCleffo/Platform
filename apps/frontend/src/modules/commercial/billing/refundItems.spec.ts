import { describe, expect, it } from 'vitest';
import type { InvoiceLine, Refund } from '@/shared/types';
import { refundSelection, remainingRefundLines } from './refundItems';

const lines = [{ id: 'line-a', quantity: 5, unit_amount_minor: 100 }, { id: 'line-b', quantity: 2, unit_amount_minor: 250 }] as InvoiceLine[];
const refund = (status: Refund['status'], quantity: number) => ({ status, items: [{ invoice_line_id: 'line-a', quantity }] }) as Refund;
describe('partial refund selection', () => {
  it('reserves pending and completed quantities, but releases canceled refunds', () => {
    const remaining = remainingRefundLines(lines, [refund('succeeded', 1), refund('pending', 2), refund('canceled', 3)]);
    expect(remaining.map(line => line.remaining)).toEqual([2, 2]);
    expect(refundSelection(remaining, { 'line-a': 1, 'line-b': 1 }, 350)).toEqual([
      { invoice_line_id: 'line-a', quantity: 1, unit_amount_minor: 100 },
      { invoice_line_id: 'line-b', quantity: 1, unit_amount_minor: 250 },
    ]);
  });
  it.each([-1, 0.5, 6, Infinity, NaN])('rejects invalid or excessive quantity %s', quantity => {
    expect(() => refundSelection(remainingRefundLines(lines, []), { 'line-a': quantity }, 10000)).toThrow();
  });
  it('rejects empty selection and cumulative overspend', () => {
    const remaining = remainingRefundLines(lines, []);
    expect(() => refundSelection(remaining, {}, 10000)).toThrow();
    expect(() => refundSelection(remaining, { 'line-a': 1, 'line-b': 1 }, 349)).toThrow();
  });
});
