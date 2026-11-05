import { ApiResponseError } from '../errors';
import { coreAdminHttp } from './adminHttp';
import type { CoreExternalProvider, CoreExternalStart } from './adminAuth';
import { isCreationOptions, type CreationOptionsJson } from '@/shared/webauthn';
export interface CorePasskey {
  id: string;
  label: string | null;
  createdAt: string;
  lastUsedAt: string | null;
  transports: string[];
  backupEligible: boolean;
  backupState: boolean;
}
export interface CoreExternalIdentity {
  provider: CoreExternalProvider;
  email: string | null;
  emailVerified: boolean;
  displayName: string | null;
  avatarUrl: string | null;
  linkedAt: string;
  lastLoginAt: string | null;
}
export interface CoreLinkProvider {
  code: CoreExternalProvider;
  loginEnabled: boolean;
}
const object = (v: unknown): v is Record<string, unknown> =>
  typeof v === 'object' && v !== null && !Array.isArray(v);
const text = (v: unknown) => typeof v === 'string' && v !== '';
const nullable = (v: unknown) => v === null || typeof v === 'string';
const provider = (v: unknown) => v === 'google' || v === 'telegram';
export function isPasskey(v: unknown): v is CorePasskey {
  return (
    object(v) &&
    text(v.id) &&
    nullable(v.label) &&
    text(v.createdAt) &&
    nullable(v.lastUsedAt) &&
    Array.isArray(v.transports) &&
    v.transports.every(text) &&
    typeof v.backupEligible === 'boolean' &&
    typeof v.backupState === 'boolean'
  );
}
function isIdentity(v: unknown): v is CoreExternalIdentity {
  return (
    object(v) &&
    provider(v.provider) &&
    nullable(v.email) &&
    typeof v.emailVerified === 'boolean' &&
    nullable(v.displayName) &&
    nullable(v.avatarUrl) &&
    text(v.linkedAt) &&
    nullable(v.lastLoginAt)
  );
}
function checked<T>(value: unknown, valid: (v: unknown) => boolean, path: string): T {
  if (!valid(value)) throw new ApiResponseError(path);
  return value as T;
}
export const coreCredentials = {
  async passkeys(): Promise<CorePasskey[]> {
    const path = '/auth/me/passkeys';
    return checked<{ items: CorePasskey[] }>(
      await coreAdminHttp.get(path),
      (v) => object(v) && Array.isArray(v.items) && v.items.every(isPasskey),
      path,
    ).items;
  },
  async registrationOptions(): Promise<CreationOptionsJson> {
    const path = '/auth/passkeys/registration/start';
    return checked(await coreAdminHttp.post(path), isCreationOptions, path);
  },
  async register(credential: Record<string, unknown>, label: string | null): Promise<void> {
    const path = '/auth/passkeys/registration/complete';
    checked(
      await coreAdminHttp.post(path, { credential, label }),
      (v) =>
        object(v) &&
        text(v.id) &&
        nullable(v.label) &&
        text(v.createdAt) &&
        Array.isArray(v.transports) &&
        typeof v.backupEligible === 'boolean' &&
        typeof v.backupState === 'boolean',
      path,
    );
  },
  async rename(id: string, label: string | null): Promise<CorePasskey> {
    const path = `/auth/me/passkeys/${encodeURIComponent(id)}`;
    return checked(await coreAdminHttp.patch(path, { label }), isPasskey, path);
  },
  async revoke(id: string): Promise<void> {
    const path = `/auth/me/passkeys/${encodeURIComponent(id)}`;
    checked(await coreAdminHttp.delete(path), (v) => object(v) && v.status === 'revoked', path);
  },
  async identities(): Promise<CoreExternalIdentity[]> {
    const path = '/auth/me/identities';
    return checked<{ items: CoreExternalIdentity[] }>(
      await coreAdminHttp.get(path),
      (v) => object(v) && Array.isArray(v.items) && v.items.every(isIdentity),
      path,
    ).items;
  },
  async providers(): Promise<CoreLinkProvider[]> {
    const path = '/auth/providers';
    const data = checked<{ items: CoreLinkProvider[] }>(
      await coreAdminHttp.get(path),
      (v) =>
        object(v) &&
        Array.isArray(v.items) &&
        v.items.every(
          (i) => object(i) && typeof i.code === 'string' && typeof i.loginEnabled === 'boolean',
        ),
      path,
    );
    return data.items.filter((i) => provider(i.code));
  },
  async startLink(code: CoreExternalProvider): Promise<CoreExternalStart> {
    const path = `/auth/me/identities/${code}/link/start`;
    return checked(
      await coreAdminHttp.post(path),
      (v) => object(v) && text(v.authorizationUrl) && text(v.expiresAt),
      path,
    );
  },
  async completeLink(
    provider: CoreExternalProvider,
    state: string,
    code: string,
  ): Promise<CoreExternalIdentity> {
    const path = `/auth/me/identities/${provider}/link/callback?${new URLSearchParams({ state, code })}`;
    return checked(
      await coreAdminHttp.get(path),
      (v) => isIdentity(v) && v.provider === provider,
      path,
    );
  },
  async unlink(code: CoreExternalProvider): Promise<void> {
    const path = `/auth/me/identities/${code}`;
    checked(await coreAdminHttp.delete(path), (v) => object(v) && v.status === 'unlinked', path);
  },
};
