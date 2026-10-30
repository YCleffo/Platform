import { beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';
import { commercialAccount } from '@/core/api';
import { useAccountMemberships } from './useAccountMemberships';
import { ApiError } from '@/shared/api/errors';
import type { CommercialMembershipList } from '@/shared/types';

vi.mock('@/core/api', () => ({
  commercialAuth: {
    login: vi.fn(),
    register: vi.fn(),
    resendVerification: vi.fn(),
    verifyEmail: vi.fn(),
    verifyMfaLogin: vi.fn(),
    logout: vi.fn(),
  },
  commercialAccount: {
    me: vi.fn(),
    listAccounts: vi.fn(),
    getAccount: vi.fn(),
    listMemberships: vi.fn(),
    createAccount: vi.fn(),
  },
  commercialCatalog: { listPlans: vi.fn() },
}));

const member = (id: string) => ({
  id,
  user_id: `user-${id}`,
  email: `${id}@example.test`,
  role: 'owner' as const,
  created_at: '2026-01-01T00:00:00Z',
});

const page = (
  items: ReturnType<typeof member>[],
  total: number,
  limit = 20,
  offset = 0,
): CommercialMembershipList => ({ items, total, limit, offset });

function runComposable(enabled = true) {
  const scope = effectScope();
  const state = scope.run(() => useAccountMemberships(ref('account-1'), ref(enabled)));
  if (state === undefined) {
    throw new Error('Composable scope failed.');
  }
  return { scope, state };
}

describe('useAccountMemberships', () => {
  beforeEach(() => {
    vi.resetAllMocks();
  });

  it('loads an empty membership list', async () => {
    vi.mocked(commercialAccount.listMemberships).mockResolvedValue(page([], 0));
    const { scope, state } = runComposable();

    await vi.waitFor(() => expect(state.loading.value).toBe(false));

    expect(state.memberships.value).toHaveLength(0);
    expect(state.total.value).toBe(0);
    scope.stop();
  });

  it('paginates memberships', async () => {
    vi.mocked(commercialAccount.listMemberships).mockImplementation(async (_id, limit = 20, offset = 0) =>
      page([], 45, limit, offset),
    );
    const { scope, state } = runComposable();

    await vi.waitFor(() => expect(state.total.value).toBe(45));
    expect(state.hasNext.value).toBe(true);

    state.nextPage();
    await vi.waitFor(() => expect(state.offset.value).toBe(20));
    expect(state.hasPrev.value).toBe(true);

    state.prevPage();
    await vi.waitFor(() => expect(state.offset.value).toBe(0));
    scope.stop();
  });

  it('does not request when access is disabled', async () => {
    const { scope, state } = runComposable(false);
    await nextTick();

    expect(commercialAccount.listMemberships).not.toHaveBeenCalled();
    expect(state.memberships.value).toHaveLength(0);
    scope.stop();
  });

  it('surfaces a permission or not-found error', async () => {
    vi.mocked(commercialAccount.listMemberships).mockRejectedValue(
      new ApiError(404, 'resource_not_found', 'not found'),
    );
    const { scope, state } = runComposable();

    await vi.waitFor(() => expect(state.errorMessage.value).not.toBeNull());
    expect(state.memberships.value).toHaveLength(0);
    scope.stop();
  });

  it('ignores a stale membership response', async () => {
    let resolveFirst: (value: CommercialMembershipList) => void = () => {};
    const first = new Promise<CommercialMembershipList>((resolve) => {
      resolveFirst = resolve;
    });
    vi.mocked(commercialAccount.listMemberships)
      .mockResolvedValueOnce(page([], 0))
      .mockReturnValueOnce(first)
      .mockResolvedValueOnce(page([member('b')], 1, 20, 20));

    const { scope, state } = runComposable();
    await vi.waitFor(() => expect(state.loading.value).toBe(false));

    const stale = state.loadPage(0);
    const fresh = state.loadPage(20);
    await fresh;
    resolveFirst(page([member('a')], 1, 20, 0));
    await stale;

    expect(state.offset.value).toBe(20);
    expect(state.memberships.value[0]?.id).toBe('b');
    scope.stop();
  });
});
