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

  it('checks for untranslated english in russian dictionary', () => {
    const enMap = collect(en);
    const ruMap = collect(ru);
    const whitelist = new Set([
      'picker.dateFormatEn',
      'navigation.core',
      'app.name',
      'admin.brandSubtitle',
    ]);
    for (const [key, enVal] of Object.entries(enMap)) {
      const ruVal = ruMap[key];
      if (enVal === ruVal && /[a-zA-Z]{4,}/.test(enVal)) {
        expect(whitelist.has(key), `Unexpected identical string at ${key}: "${enVal}"`).toBe(true);
      }
    }
  });

  it('every error code key resolves to a valid translation in both locales', () => {
    const enMap = collect(en);
    const ruMap = collect(ru);
    const codeKeys = [
      'operator.authorizationRequired',
      'coreCredentials.insecure', 'coreCredentials.unsupported',
      'coreCredentials.cancelled', 'coreCredentials.duplicate',
      'coreCredentials.origin', 'coreCredentials.unavailable',
      'coreCredentials.popupBlocked', 'coreCredentials.popupUnsupported',
      'coreCredentials.linkTimeout', 'coreCredentials.linkCancelled',
      'coreSecurity.recentRequired', 'coreCredentials.lastMethod',
      'coreCredentials.identityConflict', 'coreCredentials.invalid',
      'errors.notFound', 'coreCredentials.providerUnavailable',
      'coreProfile.invalidPreferences', 'coreAuth.externalInvalid',
      'coreAuth.externalCancelled', 'coreAuth.externalStorage',
      'auth.invalidCredentials', 'auth.mfaInvalid', 'coreAuth.linkInvalid',
      'errors.rate_limited', 'coreAuth.temporarilyUnavailable',
      'coreAuth.restartLogin', 'errors.invalidResponse', 'admin.forbidden',
      'errors.sessionExpired', 'auth.mfaExpired', 'security.mfaRequired',
      'auth.resetInvalid', 'auth.verifyLinkInvalid', 'errors.email_unavailable',
      'errors.validation_failed', 'errors.request_too_large', 'errors.operationRejected',
      'errors.generic', 'errors.network', 'common.unexpectedError',
    ];
    for (const key of codeKeys) {
      expect(enMap[key], `en:${key}`).toBeDefined();
      expect(ruMap[key], `ru:${key}`).toBeDefined();
    }
  });
});
