import { describe, expect, it, vi, beforeEach } from 'vitest';
import {
  coreServiceCredentialsApi,
  isServiceCredentialSummary,
  isIssuedServiceCredential,
} from './serviceCredentials';
import { coreAdminHttp } from './adminHttp';
import { ApiResponseError } from '../errors';

vi.mock('./adminHttp', () => ({
  coreAdminHttp: {
    get: vi.fn(),
    post: vi.fn(),
  },
}));

describe('coreServiceCredentialsApi', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  const validSummary = {
    id: 'cred-1',
    name: 'CI Pipeline Agent',
    prefix: 'plat_live_abc123',
    scopes: ['core.audit.read', 'commercial.invoices.read'],
    status: 'active' as const,
    createdAt: '2026-11-23T00:00:00Z',
    expiresAt: '2027-11-23T00:00:00Z',
    lastUsedAt: null,
    rotationGraceUntil: null,
  };

  const validIssued = {
    ...validSummary,
    secretToken: 'plat_live_abc123_supersecretkey987654321',
  };

  it('validates summary and issued structures', () => {
    expect(isServiceCredentialSummary(validSummary)).toBe(true);
    expect(isIssuedServiceCredential(validSummary)).toBe(false);
    expect(isIssuedServiceCredential(validIssued)).toBe(true);
  });

  it('lists service credentials for an account', async () => {
    vi.mocked(coreAdminHttp.get).mockResolvedValueOnce({ items: [validSummary] });
    const list = await coreServiceCredentialsApi.list('acc-1');
    expect(list).toHaveLength(1);
    expect(list[0]?.prefix).toBe('plat_live_abc123');
    expect(coreAdminHttp.get).toHaveBeenCalledWith('/core/accounts/acc-1/service-credentials');
  });

  it('issues a new credential and returns secretToken', async () => {
    vi.mocked(coreAdminHttp.post).mockResolvedValueOnce(validIssued);
    const issued = await coreServiceCredentialsApi.issue('acc-1', {
      name: 'CI Pipeline Agent',
      scopes: ['core.audit.read'],
    });
    expect(issued.secretToken).toBe('plat_live_abc123_supersecretkey987654321');
  });

  it('rotates credential with grace period', async () => {
    vi.mocked(coreAdminHttp.post).mockResolvedValueOnce({
      ...validIssued,
      rotationGraceUntil: '2026-11-24T00:00:00Z',
    });
    const rotated = await coreServiceCredentialsApi.rotate('acc-1', 'cred-1', {
      gracePeriodHours: 24,
    });
    expect(rotated.rotationGraceUntil).toBe('2026-11-24T00:00:00Z');
  });

  it('revokes credential and throws if response invalid', async () => {
    vi.mocked(coreAdminHttp.post).mockResolvedValueOnce({ status: 'revoked' });
    await expect(coreServiceCredentialsApi.revoke('acc-1', 'cred-1')).resolves.toBeUndefined();

    vi.mocked(coreAdminHttp.post).mockResolvedValueOnce({ status: 'error' });
    await expect(coreServiceCredentialsApi.revoke('acc-1', 'cred-1')).rejects.toThrow(
      ApiResponseError,
    );
  });
});
