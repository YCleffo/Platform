import { ApiResponseError } from '../errors';
import { coreAdminHttp } from './adminHttp';

export type OrganizationStatus = 'active' | 'suspended' | 'archived';
export type AccountModerationStatus = 'pending' | 'active' | 'disabled' | 'archived';

export interface PlatformOrganization {
  id: string;
  name: string;
  slug: string;
  status: OrganizationStatus;
  memberCount: number;
  accountCount: number;
  createdAt: string;
  updatedAt: string;
}

export interface PlatformAccountSummary {
  id: string;
  organizationId: string;
  organizationName: string;
  email: string;
  displayName: string;
  moderationStatus: AccountModerationStatus;
  moderatedAt: string | null;
  moderationReason: string | null;
  createdAt: string;
}

const object = (v: unknown): v is Record<string, unknown> =>
  typeof v === 'object' && v !== null && !Array.isArray(v);
const text = (v: unknown): v is string => typeof v === 'string' && v !== '';
const nullableText = (v: unknown): v is string | null => v === null || (typeof v === 'string' && v !== '');
const numberVal = (v: unknown): v is number => typeof v === 'number' && Number.isFinite(v);

export function isPlatformOrganization(v: unknown): v is PlatformOrganization {
  return (
    object(v) &&
    text(v.id) &&
    text(v.name) &&
    text(v.slug) &&
    (v.status === 'active' || v.status === 'suspended' || v.status === 'archived') &&
    numberVal(v.memberCount) &&
    numberVal(v.accountCount) &&
    text(v.createdAt) &&
    text(v.updatedAt)
  );
}

export function isPlatformAccountSummary(v: unknown): v is PlatformAccountSummary {
  return (
    object(v) &&
    text(v.id) &&
    text(v.organizationId) &&
    text(v.organizationName) &&
    text(v.email) &&
    text(v.displayName) &&
    (v.moderationStatus === 'pending' ||
      v.moderationStatus === 'active' ||
      v.moderationStatus === 'disabled' ||
      v.moderationStatus === 'archived') &&
    nullableText(v.moderatedAt) &&
    nullableText(v.moderationReason) &&
    text(v.createdAt)
  );
}

function checked<T>(value: unknown, valid: (v: unknown) => boolean, path: string): T {
  if (!valid(value)) throw new ApiResponseError(path);
  return value as T;
}

export const platformAdminApi = {
  async listOrganizations(query?: {
    status?: OrganizationStatus;
    search?: string;
  }): Promise<PlatformOrganization[]> {
    const params = new URLSearchParams();
    if (query?.status) params.set('status', query.status);
    if (query?.search) params.set('q', query.search);
    const queryString = params.toString();
    const path = `/core/organizations${queryString ? `?${queryString}` : ''}`;
    const res = await coreAdminHttp.get(path);
    return checked<{ items: PlatformOrganization[] }>(
      res,
      (v) => object(v) && Array.isArray(v.items) && v.items.every(isPlatformOrganization),
      path,
    ).items;
  },

  async getOrganization(orgId: string): Promise<PlatformOrganization> {
    const path = `/core/organizations/${encodeURIComponent(orgId)}`;
    const res = await coreAdminHttp.get(path);
    return checked<PlatformOrganization>(res, isPlatformOrganization, path);
  },

  async createOrganization(payload: {
    name: string;
    slug: string;
  }): Promise<PlatformOrganization> {
    const path = '/core/organizations';
    const res = await coreAdminHttp.post(path, payload);
    return checked<PlatformOrganization>(res, isPlatformOrganization, path);
  },

  async updateOrganization(
    orgId: string,
    payload: {
      name?: string;
      status?: OrganizationStatus;
    },
  ): Promise<PlatformOrganization> {
    const path = `/core/organizations/${encodeURIComponent(orgId)}`;
    const res = await coreAdminHttp.patch(path, payload);
    return checked<PlatformOrganization>(res, isPlatformOrganization, path);
  },

  async listAccounts(query?: {
    organizationId?: string;
    moderationStatus?: AccountModerationStatus;
    search?: string;
  }): Promise<PlatformAccountSummary[]> {
    const params = new URLSearchParams();
    if (query?.organizationId) params.set('organizationId', query.organizationId);
    if (query?.moderationStatus) params.set('status', query.moderationStatus);
    if (query?.search) params.set('q', query.search);
    const queryString = params.toString();
    const path = `/core/accounts${queryString ? `?${queryString}` : ''}`;
    const res = await coreAdminHttp.get(path);
    return checked<{ items: PlatformAccountSummary[] }>(
      res,
      (v) => object(v) && Array.isArray(v.items) && v.items.every(isPlatformAccountSummary),
      path,
    ).items;
  },

  async moderateAccount(
    accountId: string,
    payload: {
      action: 'approve' | 'disable' | 'enable' | 'archive';
      reason?: string;
    },
  ): Promise<PlatformAccountSummary> {
    const path = `/core/accounts/${encodeURIComponent(accountId)}/moderation`;
    const res = await coreAdminHttp.post(path, payload);
    return checked<PlatformAccountSummary>(res, isPlatformAccountSummary, path);
  },
};
