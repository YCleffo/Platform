import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { nextTick } from 'vue';
import { commercialAccount } from '@/core/api';
import { beginSession, endSession } from '@/core/auth/session';
import { useAccountStore } from './accountStore';
import { ApiError } from '@/shared/api/errors';
import type { CommercialAccountList, CommercialAccountSummary } from '@/shared/types';

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
    createAccount: vi.fn(),
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

describe('accountStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    endSession();
    beginSession({ access_token: 'access-a', refresh_token: 'refresh-a' });
    vi.resetAllMocks();
  });

  it('loads a page of accounts', async () => {
    vi.mocked(commercialAccount.listAccounts).mockResolvedValue(page([account('1')], 1));

    const store = useAccountStore();
    await store.loadPage(0);

    expect(store.accounts).toHaveLength(1);
    expect(store.total).toBe(1);
    expect(store.loading).toBe(false);
    expect(store.errorMessage).toBeNull();
  });

  it('handles an empty list', async () => {
    vi.mocked(commercialAccount.listAccounts).mockResolvedValue(page([], 0));

    const store = useAccountStore();
    await store.loadPage(0);

    expect(store.accounts).toHaveLength(0);
    expect(store.total).toBe(0);
    expect(store.hasNext).toBe(false);
  });

  it('exposes a localized error on API failure', async () => {
    vi.mocked(commercialAccount.listAccounts).mockRejectedValue(new ApiError(500, 'server_error', 'boom'));

    const store = useAccountStore();
    await store.loadPage(0);

    expect(store.errorMessage).not.toBeNull();
    expect(store.accounts).toHaveLength(0);
  });

  it('paginates using limit/offset/total', async () => {
    vi.mocked(commercialAccount.listAccounts).mockImplementation(async (limit = 20, offset = 0) =>
      page([], 45, limit, offset),
    );

    const store = useAccountStore();
    await store.loadPage(0);
    expect(store.page).toBe(1);
    expect(store.pageCount).toBe(3);
    expect(store.hasNext).toBe(true);
    expect(store.hasPrev).toBe(false);

    store.nextPage();
    await vi.waitFor(() => expect(store.offset).toBe(20));
    expect(store.page).toBe(2);
    expect(store.hasPrev).toBe(true);

    store.prevPage();
    await vi.waitFor(() => expect(store.offset).toBe(0));
  });

  it('ignores a stale response from an earlier request', async () => {
    let resolveFirst: (value: CommercialAccountList) => void = () => {};
    const first = new Promise<CommercialAccountList>((resolve) => {
      resolveFirst = resolve;
    });
    vi.mocked(commercialAccount.listAccounts)
      .mockReturnValueOnce(first)
      .mockResolvedValueOnce(page([account('2')], 1, 20, 20));

    const store = useAccountStore();
    const stale = store.loadPage(0);
    const fresh = store.loadPage(20);
    await fresh;
    resolveFirst(page([account('1')], 1, 20, 0));
    await stale;

    expect(store.accounts[0]?.id).toBe('2');
    expect(store.offset).toBe(20);
  });

  it('clears all account state when the session ends', async () => {
    vi.mocked(commercialAccount.listAccounts).mockResolvedValue(page([account('1')], 1));

    const store = useAccountStore();
    await store.loadPage(0);
    store.selectAccount('1');
    expect(store.selected?.id).toBe('1');

    endSession();
    await nextTick();

    expect(store.accounts).toHaveLength(0);
    expect(store.selected).toBeNull();
    expect(store.total).toBe(0);
  });

  it('drops the selected account when a different user signs in', async () => {
    vi.mocked(commercialAccount.listAccounts).mockResolvedValue(page([account('1')], 1));

    const store = useAccountStore();
    await store.loadPage(0);
    store.selectAccount('1');

    beginSession({ access_token: 'access-b', refresh_token: 'refresh-b' });
    await nextTick();

    expect(store.selected).toBeNull();
    expect(store.accounts).toHaveLength(0);
  });
});
