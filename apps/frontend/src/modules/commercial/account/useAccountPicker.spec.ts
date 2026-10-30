import { beforeEach, describe, expect, it, vi } from 'vitest';
import { commercialAccount } from '@/core/api';
import { useAccountPicker } from './useAccountPicker';
import type { CommercialAccountList, CommercialAccountSummary } from '@/shared/types';

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

const account = (id: string): CommercialAccountSummary => ({
  id,
  code: `code-${id}`,
  display_name: `Account ${id}`,
  legal_name: null,
  status: 'active',
  onboarding_state: 'profile_required',
  role: 'owner',
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
});

const page = (
  items: CommercialAccountSummary[],
  total: number,
  limit = 20,
  offset = 0,
): CommercialAccountList => ({ items, total, limit, offset });

describe('useAccountPicker', () => {
  beforeEach(() => {
    vi.resetAllMocks();
  });

  it('paginates accounts through the server', async () => {
    vi.mocked(commercialAccount.listAccounts).mockImplementation(async (limit = 20, offset = 0) =>
      page([account(String(offset + 1))], 45, limit, offset),
    );

    const state = useAccountPicker();
    await state.loadPage(0);
    expect(state.total.value).toBe(45);
    expect(state.page.value).toBe(1);
    expect(state.hasNext.value).toBe(true);

    state.nextPage();
    await vi.waitFor(() => expect(state.offset.value).toBe(20));
    expect(state.page.value).toBe(2);

    state.prevPage();
    await vi.waitFor(() => expect(state.offset.value).toBe(0));
  });

  it('ignores a stale page response', async () => {
    let resolveFirst: (value: CommercialAccountList) => void = () => {};
    const first = new Promise<CommercialAccountList>((resolve) => {
      resolveFirst = resolve;
    });
    vi.mocked(commercialAccount.listAccounts)
      .mockReturnValueOnce(first)
      .mockResolvedValueOnce(page([account('second')], 40, 20, 20));

    const state = useAccountPicker();
    const stale = state.loadPage(0);
    const fresh = state.loadPage(20);
    await fresh;
    resolveFirst(page([account('first')], 40, 20, 0));
    await stale;

    expect(state.accounts.value[0]?.id).toBe('second');
    expect(state.offset.value).toBe(20);
  });

  it('handles an empty list', async () => {
    vi.mocked(commercialAccount.listAccounts).mockResolvedValue(page([], 0));

    const state = useAccountPicker();
    await state.loadPage(0);

    expect(state.accounts.value).toHaveLength(0);
    expect(state.hasNext.value).toBe(false);
  });
});
