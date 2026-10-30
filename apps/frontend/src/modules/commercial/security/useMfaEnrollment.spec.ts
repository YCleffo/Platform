import { beforeEach, describe, expect, it, vi } from 'vitest';
import { commercialAuth } from '@/core/api';
import { useMfaEnrollment } from './useMfaEnrollment';
import { ApiError } from '@/shared/api/errors';

vi.mock('@/core/api', () => ({
  commercialAuth: {
    beginTotpSetup: vi.fn(),
    confirmTotpSetup: vi.fn(),
  },
  commercialAccount: {
    me: vi.fn(),
    listAccounts: vi.fn(),
    getAccount: vi.fn(),
    listMemberships: vi.fn(),
    createAccount: vi.fn(),
    changeMembershipRole: vi.fn(),
    removeMembership: vi.fn(),
  },
  commercialCatalog: { listPlans: vi.fn() },
}));

describe('useMfaEnrollment', () => {
  beforeEach(() => {
    vi.resetAllMocks();
  });

  it('keeps recovery codes one-time and drops the secret after confirmation', async () => {
    vi.mocked(commercialAuth.beginTotpSetup).mockResolvedValue({
      factor_id: 'factor-1',
      secret: 'SECRET',
      otpauth_uri: 'otpauth://totp/Platform:user?secret=SECRET',
    });
    vi.mocked(commercialAuth.confirmTotpSetup).mockResolvedValue({ recovery_codes: ['code-1', 'code-2'] });

    const state = useMfaEnrollment();
    await expect(state.begin()).resolves.toBe(true);
    expect(state.setup.value?.secret).toBe('SECRET');

    await expect(state.confirm('123456')).resolves.toBe(true);
    expect(state.setup.value).toBeNull();
    expect(state.recoveryCodes.value).toEqual(['code-1', 'code-2']);

    state.reset();
    expect(state.recoveryCodes.value).toEqual([]);
    expect(state.setup.value).toBeNull();
  });

  it('keeps the setup state when the TOTP code is rejected', async () => {
    vi.mocked(commercialAuth.beginTotpSetup).mockResolvedValue({
      factor_id: 'factor-1',
      secret: 'SECRET',
      otpauth_uri: 'otpauth://totp/Platform:user?secret=SECRET',
    });
    vi.mocked(commercialAuth.confirmTotpSetup).mockRejectedValue(
      new ApiError(400, 'totp_code_invalid', 'invalid'),
    );

    const state = useMfaEnrollment();
    await state.begin();
    await expect(state.confirm('000000')).rejects.toBeInstanceOf(ApiError);

    expect(state.setup.value?.factor_id).toBe('factor-1');
    expect(state.recoveryCodes.value).toEqual([]);
    expect(state.error.value).toBeInstanceOf(ApiError);
  });
});
