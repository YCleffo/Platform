import { coreAdminHttp } from './adminHttp';
import { ApiResponseError } from '../errors';

export interface CorePreferences { locale: string | null; timezone: string | null }
export interface CoreSettings {
  schema_version: 2;
  settings: { interface: { animations: boolean; transparency: boolean }; notifications: { browser: boolean; sound: boolean; email: boolean }; privacy: { show_online_status: boolean } };
}
export type CoreSettingsPatch = { interface?: { animations?: boolean | null; transparency?: boolean | null }; notifications?: { browser?: boolean | null; sound?: boolean | null; email?: boolean | null }; privacy?: { show_online_status?: boolean | null } };
export interface CoreConsentDefinition {
  code: string; purpose_code: string; current_document_version: string; current_document_sha256: string;
  withdrawable: boolean; representative_allowed: boolean; document: { title: string; text: string } | null;
}
export interface CoreConsent {
  id: string; consent_code: string; purpose_code: string; document_version: string; document_sha256: string;
  locale: string; source: string; granted_at: string; withdrawn_at: string | null; current: boolean;
}
export interface CoreConsents { definitions: CoreConsentDefinition[]; consents: CoreConsent[] }
const object = (v: unknown): v is Record<string, unknown> => typeof v === 'object' && v !== null && !Array.isArray(v);
const string = (v: unknown): v is string => typeof v === 'string' && v.length > 0;
const nullableString = (v: unknown) => v === null || string(v);
const hash = (v: unknown) => typeof v === 'string' && /^[a-f0-9]{64}$/.test(v);
function checked<T>(v: unknown, valid: (value: unknown) => boolean, path: string): T { if (!valid(v)) throw new ApiResponseError(path); return v as T; }
export function isCorePreferences(v: unknown): v is CorePreferences { return object(v) && nullableString(v.locale) && nullableString(v.timezone); }
export function isCoreSettings(v: unknown): v is CoreSettings {
  if (!object(v) || v.schema_version !== 2 || !object(v.settings)) return false;
  const s = v.settings;
  return object(s.interface) && typeof s.interface.animations === 'boolean' && typeof s.interface.transparency === 'boolean'
    && object(s.notifications) && [s.notifications.browser, s.notifications.sound, s.notifications.email].every(value => typeof value === 'boolean')
    && object(s.privacy) && typeof s.privacy.show_online_status === 'boolean';
}
function isDefinition(v: unknown): v is CoreConsentDefinition {
  return object(v) && [v.code, v.purpose_code, v.current_document_version].every(string) && hash(v.current_document_sha256)
    && typeof v.withdrawable === 'boolean' && typeof v.representative_allowed === 'boolean'
    && (v.document === null || v.document === undefined || (object(v.document) && string(v.document.title) && string(v.document.text)));
}
function isConsent(v: unknown): v is CoreConsent {
  return object(v) && [v.id, v.consent_code, v.purpose_code, v.document_version, v.locale, v.source, v.granted_at].every(string)
    && hash(v.document_sha256) && nullableString(v.withdrawn_at) && typeof v.current === 'boolean';
}
export const coreAccount = {
  async preferences(): Promise<CorePreferences> { return checked(await coreAdminHttp.get('/auth/me'), isCorePreferences, '/auth/me'); },
  async patchPreferences(patch: Partial<CorePreferences>): Promise<CorePreferences> { return checked(await coreAdminHttp.patch('/auth/me/preferences', patch), isCorePreferences, '/auth/me/preferences'); },
  async settings(): Promise<CoreSettings> { return checked(await coreAdminHttp.get('/auth/me/settings'), isCoreSettings, '/auth/me/settings'); },
  async patchSettings(patch: CoreSettingsPatch): Promise<CoreSettings> { return checked(await coreAdminHttp.patch('/auth/me/settings', patch), isCoreSettings, '/auth/me/settings'); },
  async consents(): Promise<CoreConsents> {
    const path = '/auth/me/consents';
    const result = checked<CoreConsents>(await coreAdminHttp.get(path), v => object(v) && Array.isArray(v.definitions) && v.definitions.every(isDefinition) && Array.isArray(v.consents) && v.consents.every(isConsent), path);
    return { ...result, definitions: result.definitions.map(item => ({ ...item, document: item.document ?? null })) };
  },
  async grant(definition: CoreConsentDefinition): Promise<CoreConsent> {
    const path = `/auth/me/consents/${encodeURIComponent(definition.code)}`;
    const result = checked<CoreConsent>(await coreAdminHttp.post(path, { document_version: definition.current_document_version, document_sha256: definition.current_document_sha256 }), isConsent, path);
    if (!result.current || result.consent_code !== definition.code || result.purpose_code !== definition.purpose_code
      || result.document_version !== definition.current_document_version || result.document_sha256 !== definition.current_document_sha256) throw new ApiResponseError(path);
    return result;
  },
  async withdraw(code: string): Promise<void> { await coreAdminHttp.delete(`/auth/me/consents/${encodeURIComponent(code)}`); },
};
