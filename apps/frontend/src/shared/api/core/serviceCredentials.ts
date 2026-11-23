import { ApiResponseError } from '../errors';
import { coreAdminHttp } from './adminHttp';

export interface ServiceCredentialSummary {
  id: string;
  name: string;
  prefix: string;
  scopes: string[];
  status: 'active' | 'rotated' | 'revoked' | 'expired';
  createdAt: string;
  expiresAt: string | null;
  lastUsedAt: string | null;
  rotationGraceUntil: string | null;
}

export interface IssuedServiceCredential extends ServiceCredentialSummary {
  secretToken: string;
}

const object = (v: unknown): v is Record<string, unknown> =>
  typeof v === 'object' && v !== null && !Array.isArray(v);
const text = (v: unknown): v is string => typeof v === 'string' && v !== '';
const nullableText = (v: unknown): v is string | null => v === null || (typeof v === 'string' && v !== '');

export function isServiceCredentialSummary(v: unknown): v is ServiceCredentialSummary {
  return (
    object(v) &&
    text(v.id) &&
    text(v.name) &&
    text(v.prefix) &&
    Array.isArray(v.scopes) &&
    v.scopes.every(text) &&
    (v.status === 'active' ||
      v.status === 'rotated' ||
      v.status === 'revoked' ||
      v.status === 'expired') &&
    text(v.createdAt) &&
    nullableText(v.expiresAt) &&
    nullableText(v.lastUsedAt) &&
    nullableText(v.rotationGraceUntil)
  );
}

export function isIssuedServiceCredential(v: unknown): v is IssuedServiceCredential {
  return isServiceCredentialSummary(v) && text((v as unknown as { secretToken?: unknown }).secretToken);
}

function checked<T>(value: unknown, valid: (v: unknown) => boolean, path: string): T {
  if (!valid(value)) throw new ApiResponseError(path);
  return value as T;
}

export const coreServiceCredentialsApi = {
  async list(accountId: string): Promise<ServiceCredentialSummary[]> {
    const path = `/core/accounts/${encodeURIComponent(accountId)}/service-credentials`;
    const res = await coreAdminHttp.get(path);
    return checked<{ items: ServiceCredentialSummary[] }>(
      res,
      (v) => object(v) && Array.isArray(v.items) && v.items.every(isServiceCredentialSummary),
      path,
    ).items;
  },

  async issue(
    accountId: string,
    payload: {
      name: string;
      scopes: string[];
      expiresInDays?: number;
    },
  ): Promise<IssuedServiceCredential> {
    const path = `/core/accounts/${encodeURIComponent(accountId)}/service-credentials`;
    const res = await coreAdminHttp.post(path, payload);
    return checked<IssuedServiceCredential>(res, isIssuedServiceCredential, path);
  },

  async rotate(
    accountId: string,
    credentialId: string,
    payload?: {
      gracePeriodHours?: number;
    },
  ): Promise<IssuedServiceCredential> {
    const path = `/core/accounts/${encodeURIComponent(accountId)}/service-credentials/${encodeURIComponent(credentialId)}/rotate`;
    const res = await coreAdminHttp.post(path, payload ?? {});
    return checked<IssuedServiceCredential>(res, isIssuedServiceCredential, path);
  },

  async revoke(accountId: string, credentialId: string): Promise<void> {
    const path = `/core/accounts/${encodeURIComponent(accountId)}/service-credentials/${encodeURIComponent(credentialId)}/revoke`;
    const res = await coreAdminHttp.post(path);
    checked(res, (v) => object(v) && v.status === 'revoked', path);
  },
};
