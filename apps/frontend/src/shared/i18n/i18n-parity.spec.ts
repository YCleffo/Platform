import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import en from './messages/en';
import ru from './messages/ru';
import { educationCopy } from '@/modules/education/i18n';

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

function getSourceFiles(dir: string, list: string[] = []): string[] {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = resolve(dir, entry.name);
    if (entry.isDirectory()) {
      if (entry.name !== 'node_modules' && entry.name !== 'dist' && entry.name !== 'messages') {
        getSourceFiles(full, list);
      }
    } else if (/\.(vue|ts)$/.test(entry.name) && !entry.name.endsWith('.spec.ts')) {
      list.push(full);
    }
  }
  return list;
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

  it('all localization keys used in source files exist in both dictionaries', () => {
    const enMap = collect(en);
    const ruMap = collect(ru);
    for (const [key, value] of Object.entries(educationCopy)) {
      enMap[`education.${key}`] = (value as readonly [string, string])[1];
      ruMap[`education.${key}`] = (value as readonly [string, string])[0];
    }

    const srcDir = resolve(__dirname, '../..');
    const files = getSourceFiles(srcDir);
    const staticKeyRegex = /(?:\$t|\bt|\bte)\(\s*['"]([a-zA-Z0-9_.-]+)['"]/g;
    const missingInEn: Array<{ file: string; key: string }> = [];
    const missingInRu: Array<{ file: string; key: string }> = [];

    const enKeys = Object.keys(enMap);
    const ruKeys = Object.keys(ruMap);

    for (const file of files) {
      const content = readFileSync(file, 'utf8');
      let match: RegExpExecArray | null;
      while ((match = staticKeyRegex.exec(content)) !== null) {
        const key = match[1];
        if (!key || key === 'en' || key === 'ru') continue;

        if (key.endsWith('.') || key.endsWith('_')) {
          if (!enKeys.some((k) => k.startsWith(key))) {
            missingInEn.push({ file: file.replace(/.*src[\\/]/, 'src/'), key });
          }
          if (!ruKeys.some((k) => k.startsWith(key))) {
            missingInRu.push({ file: file.replace(/.*src[\\/]/, 'src/'), key });
          }
        } else {
          if (!enMap[key]) {
            missingInEn.push({ file: file.replace(/.*src[\\/]/, 'src/'), key });
          }
          if (!ruMap[key]) {
            missingInRu.push({ file: file.replace(/.*src[\\/]/, 'src/'), key });
          }
        }
      }
    }

    expect(missingInEn, `Missing translations in EN:\n${JSON.stringify(missingInEn, null, 2)}`).toEqual([]);
    expect(missingInRu, `Missing translations in RU:\n${JSON.stringify(missingInRu, null, 2)}`).toEqual([]);
  });
});
