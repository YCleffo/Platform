import { describe, expect, it } from 'vitest';
import { draftValue, readDraft } from './valueDraft';
describe('structured configuration values', () => {
  it('round-trips nested values, false, zero, null, empty objects and lists', () => {
    const value = {
      text: 'line\nline',
      zero: 0,
      off: false,
      empty: null,
      object: {},
      list: [],
      nested: [{ label: 'value' }],
    };
    expect(readDraft(draftValue(value))).toEqual(value);
  });
  it('preserves explicit empty text separately from null', () => {
    expect(readDraft(draftValue(''))).toBe('');
    expect(readDraft(draftValue(null))).toBeNull();
  });
  it('rejects duplicate and prototype property names', () => {
    const value = draftValue({ first: 1, second: 2 });
    value.children[1]!.key = 'first';
    expect(() => readDraft(value)).toThrow();
    value.children[1]!.key = '__proto__';
    expect(() => readDraft(value)).toThrow();
  });
  it('does not turn an invalid or blank number into zero', () => {
    const value = draftValue(1);
    for (const text of ['', ' ', 'Infinity', '01', '1foo']) {
      value.text = text;
      expect(() => readDraft(value)).toThrow();
    }
    value.text = '-1.25e2';
    expect(readDraft(value)).toBe(-125);
  });
});
