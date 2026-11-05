import { describe, expect, it } from 'vitest';
import { formatDateTime } from './date';

describe('optional billing dates', () => {
  it('shows an absent period without turning null into the Unix epoch', () => {
    for (const value of [null, undefined, '', 'invalid-date']) {
      expect(formatDateTime(value, 'en')).toBe('—');
    }
    expect(formatDateTime('2026-11-03T12:00:00Z', 'en')).not.toBe('—');
  });
});
