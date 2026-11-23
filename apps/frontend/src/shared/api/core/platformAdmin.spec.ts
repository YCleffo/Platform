import { describe, expect, it, vi, beforeEach } from 'vitest';
import {
  platformAdminApi,
  isPlatformOrganization,
  isPlatformAccountSummary,
  isPlatformSuspiciousSession,
  isServiceHealthItem,
} from './platformAdmin';
import { coreAdminHttp } from './adminHttp';
import { ApiResponseError } from '../errors';

vi.mock('./adminHttp', () => ({
  coreAdminHttp: {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
  },
}));

describe('platformAdminApi', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  const validOrg = {
    id: 'org-1',
    name: 'Global Tech Corp',
    slug: 'global-tech',
    status: 'active' as const,
    memberCount: 42,
    accountCount: 15,
    createdAt: '2026-11-23T00:00:00Z',
    updatedAt: '2026-11-23T00:00:00Z',
  };

  const validAccount = {
    id: 'acc-1',
    organizationId: 'org-1',
    organizationName: 'Global Tech Corp',
    email: 'user@global.test',
    displayName: 'User Global',
    moderationStatus: 'active' as const,
    moderatedAt: '2026-11-23T00:00:00Z',
    moderationReason: 'Approved by superadmin',
    createdAt: '2026-11-23T00:00:00Z',
  };

  it('validates organizations and accounts', () => {
    expect(isPlatformOrganization(validOrg)).toBe(true);
    expect(isPlatformOrganization({ ...validOrg, memberCount: '42' })).toBe(false);
    expect(isPlatformAccountSummary(validAccount)).toBe(true);
    expect(isPlatformAccountSummary({ ...validAccount, moderationStatus: 'unknown' })).toBe(false);
  });

  it('lists organizations with query filters', async () => {
    vi.mocked(coreAdminHttp.get).mockResolvedValueOnce({ items: [validOrg] });
    const orgs = await platformAdminApi.listOrganizations({ status: 'active', search: 'global' });
    expect(orgs).toHaveLength(1);
    expect(coreAdminHttp.get).toHaveBeenCalledWith('/core/organizations?status=active&q=global');
  });

  it('moderates platform account', async () => {
    vi.mocked(coreAdminHttp.post).mockResolvedValueOnce({
      ...validAccount,
      moderationStatus: 'disabled',
      moderationReason: 'Policy violation',
    });
    const moderated = await platformAdminApi.moderateAccount('acc-1', {
      action: 'disable',
      reason: 'Policy violation',
    });
    expect(moderated.moderationStatus).toBe('disabled');
    expect(coreAdminHttp.post).toHaveBeenCalledWith('/core/accounts/acc-1/moderation', {
      action: 'disable',
      reason: 'Policy violation',
    });
  });

  it('throws on invalid API payload', async () => {
    vi.mocked(coreAdminHttp.get).mockResolvedValueOnce({ notAnOrg: true });
    await expect(platformAdminApi.getOrganization('org-1')).rejects.toThrow(ApiResponseError);
  });

  it('lists suspicious sessions and revokes them', async () => {
    const sessions = await platformAdminApi.listSuspiciousSessions();
    expect(sessions.length).toBeGreaterThan(0);
    expect(isPlatformSuspiciousSession(sessions[0])).toBe(true);

    await platformAdminApi.revokeSession('sess-1');
    expect(coreAdminHttp.post).toHaveBeenCalledWith('/core/security/sessions/sess-1/revoke');
  });

  it('retrieves system service health items', async () => {
    vi.mocked(coreAdminHttp.get).mockResolvedValue({ status: 'ok' });
    const health = await platformAdminApi.getServicesHealth();
    expect(health.length).toBeGreaterThanOrEqual(4);
    expect(health.every(isServiceHealthItem)).toBe(true);
    expect(health.some((h) => h.name.includes('PostgreSQL'))).toBe(true);
  });
});
