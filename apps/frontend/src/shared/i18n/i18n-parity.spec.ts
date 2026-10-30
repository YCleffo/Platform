import { describe, expect, it } from 'vitest';
import en from './messages/en';
import ru from './messages/ru';

function collect(messages: Record<string, unknown>, prefix = '', out: Record<string, string> = {}): Record<string, string> {
  for (const [key, value] of Object.entries(messages)) {
    const path = prefix === '' ? key : `${prefix}.${key}`;
    if (typeof value === 'string') {
      out[path] = value;
    } else if (typeof value === 'object' && value !== null) {
      collect(value as Record<string, unknown>, path, out);
    }
  }
  return out;
}

describe('i18n messages', () => {
  it('en and ru share the same keys', () => {
    expect(Object.keys(collect(en)).sort()).toEqual(Object.keys(collect(ru)).sort());
  });

  it('has no empty translations', () => {
    const locales: Array<[string, Record<string, string>]> = [
      ['en', collect(en)],
      ['ru', collect(ru)],
    ];
    for (const [locale, messages] of locales) {
      for (const [key, value] of Object.entries(messages)) {
        expect(value.length, `${locale}:${key}`).toBeGreaterThan(0);
      }
    }
  });
});
