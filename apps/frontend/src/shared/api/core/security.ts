import { coreAdminHttp } from './adminHttp';
import { ApiResponseError } from '../errors';

export interface CoreMfaStatus { totpEnabled: boolean; verifiedAt: string | null; recoveryCodesRemaining: number }
export interface CoreTotpSetup { setupId: string; issuer: string; accountLabel: string; secret: string; provisioningUri: string; expiresAt: string }
export interface CoreOwnSession {
  id: string; current: boolean; clientType: string; deviceName: string | null;
  browser: { name: string | null; version: string | null }; os: { name: string | null; version: string | null };
  ipAddress: string | null; approximateLocation: { countryCode: string | null; region: string | null; city: string | null; approximate: true };
  createdAt: string; lastSeenAt: string; expiresAt: string;
}
const record = (v: unknown): v is Record<string, unknown> => typeof v === 'object' && v !== null && !Array.isArray(v);
const text = (v: unknown): v is string => typeof v === 'string' && v.length > 0;
const nullable = (v: unknown): boolean => v === null || typeof v === 'string';
function checked<T>(value: unknown, valid: (data: unknown) => boolean, path: string): T {
  if (!valid(value)) throw new ApiResponseError(path);
  return value as T;
}
function codes(data: unknown): data is { recoveryCodes: string[] } {
  return record(data) && Array.isArray(data.recoveryCodes) && data.recoveryCodes.length > 0 && data.recoveryCodes.every(text);
}
export function isCoreSession(data: unknown): data is CoreOwnSession {
  return record(data) && text(data.id) && typeof data.current === 'boolean' && text(data.clientType) && nullable(data.deviceName)
    && [data.browser, data.os].every(part => record(part) && nullable(part.name) && nullable(part.version))
    && nullable(data.ipAddress) && record(data.approximateLocation) && data.approximateLocation.approximate === true
    && ['countryCode', 'region', 'city'].every(key => nullable((data.approximateLocation as Record<string, unknown>)[key]))
    && [data.createdAt, data.lastSeenAt, data.expiresAt].every(text);
}
export const coreSecurity = {
  async status(): Promise<CoreMfaStatus> {
    const path = '/auth/mfa';
    return checked(await coreAdminHttp.get(path), data => record(data) && typeof data.totpEnabled === 'boolean'
      && nullable(data.verifiedAt) && typeof data.recoveryCodesRemaining === 'number' && Number.isSafeInteger(data.recoveryCodesRemaining) && data.recoveryCodesRemaining >= 0, path);
  },
  async setup(): Promise<CoreTotpSetup> {
    const path = '/auth/mfa/totp/setup';
    return checked(await coreAdminHttp.post(path), data => record(data)
      && ['setupId', 'issuer', 'accountLabel', 'secret', 'expiresAt'].every(key => text(data[key]))
      && typeof data.secret === 'string' && /^[A-Z2-7]+$/.test(data.secret)
      && typeof data.expiresAt === 'string' && Number.isFinite(Date.parse(data.expiresAt))
      && typeof data.provisioningUri === 'string' && data.provisioningUri.startsWith('otpauth://totp/'), path);
  },
  async confirm(setupId: string, code: string): Promise<string[]> {
    const path = '/auth/mfa/totp/confirm';
    return checked<{ recoveryCodes: string[] }>(await coreAdminHttp.post(path, { setupId, code }), codes, path).recoveryCodes;
  },
  async regenerate(code: string): Promise<string[]> {
    const path = '/auth/mfa/recovery-codes/regenerate';
    return checked<{ recoveryCodes: string[] }>(await coreAdminHttp.post(path, { code }), codes, path).recoveryCodes;
  },
  async disable(password: string | null, code: string): Promise<void> {
    await coreAdminHttp.delete('/auth/mfa/totp', { password, code });
  },
  async sessions(): Promise<CoreOwnSession[]> {
    const path = '/auth/sessions';
    return checked(await coreAdminHttp.get(path), data => Array.isArray(data) && data.every(isCoreSession), path);
  },
  async revoke(id: string): Promise<void> { await coreAdminHttp.delete(`/auth/sessions/${encodeURIComponent(id)}`); },
  async revokeOthers(): Promise<number> {
    const path = '/auth/sessions/revoke-others';
    return checked<{ revokedCount: number }>(await coreAdminHttp.post(path), data => record(data) && typeof data.revokedCount === 'number'
      && Number.isSafeInteger(data.revokedCount) && data.revokedCount >= 0, path).revokedCount;
  },
};
