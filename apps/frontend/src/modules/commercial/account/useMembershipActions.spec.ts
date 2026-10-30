import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { commercialAccount } from '@/core/api';
import { useMembershipActions } from './useMembershipActions';
import { ApiError } from '@/shared/api/errors';

vi.mock('@/core/api', () => ({
  commercialAuth: {},
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

describe('useMembershipActions', () => {
  beforeEach(() => {
    vi.resetAllMocks();
  });

  it('changes a role and refreshes the account context', async () => {
    vi.mocked(commercialAccount.changeMembershipRole).mockResolvedValue(undefined);
    const onChanged = vi.fn(async () => {});
    const state = useMembershipActions(ref('account-1'), onChanged);

    await expect(state.changeRole('membership-1', 'admin')).resolves.toBe(true);
    expect(commercialAccount.changeMembershipRole).toHaveBeenCalledWith('account-1', 'membership-1', 'admin');
    expect(onChanged).toHaveBeenCalledTimes(1);
  });

  it('retries after a successful step-up', async () => {
    vi.mocked(commercialAccount.changeMembershipRole)
      .mockRejectedValueOnce(new ApiError(403, 'recent_mfa_required', 'step up'))
      .mockResolvedValueOnce(undefined);
    const onChanged = vi.fn(async () => {});
    const state = useMembershipActions(ref('account-1'), onChanged);

    const run = state.changeRole('membership-1', 'billing');
    await vi.waitFor(() => expect(state.reauthOpen.value).toBe(true));
    await state.resume();

    await expect(run).resolves.toBe(true);
    expect(commercialAccount.changeMembershipRole).toHaveBeenCalledTimes(2);
    expect(onChanged).toHaveBeenCalledTimes(1);
  });

  it('flags MFA enrollment when the account has no MFA', async () => {
    vi.mocked(commercialAccount.removeMembership).mockRejectedValue(
      new ApiError(403, 'mfa_enrollment_required', 'enroll'),
    );
    const onChanged = vi.fn(async () => {});
    const state = useMembershipActions(ref('account-1'), onChanged);

    await expect(state.remove('membership-1')).resolves.toBe(false);
    expect(state.needsMfaEnrollment.value).toBe(true);
    expect(onChanged).not.toHaveBeenCalled();
  });

  it('removes a member and refreshes the account context', async () => {
    vi.mocked(commercialAccount.removeMembership).mockResolvedValue(undefined);
    const onChanged = vi.fn(async () => {});
    const state = useMembershipActions(ref('account-1'), onChanged);

    await expect(state.remove('membership-1')).resolves.toBe(true);
    expect(commercialAccount.removeMembership).toHaveBeenCalledWith('account-1', 'membership-1');
    expect(onChanged).toHaveBeenCalledTimes(1);
  });

  it('reports a last-owner rejection without retrying', async () => {
    vi.mocked(commercialAccount.removeMembership).mockRejectedValue(
      new ApiError(409, 'operation_rejected', 'last owner'),
    );
    const onChanged = vi.fn(async () => {});
    const state = useMembershipActions(ref('account-1'), onChanged);

    await expect(state.remove('membership-1')).resolves.toBe(false);
    expect(state.error.value).toBeInstanceOf(ApiError);
    expect(state.reauthOpen.value).toBe(false);
    expect(onChanged).not.toHaveBeenCalled();
  });
});
