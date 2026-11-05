import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { nextTick } from 'vue';
import { commercialAccount } from '@/core/api';
import { beginSession, endSession } from '@/core/auth/session';
import { useAccountStore } from './accountStore';
import { ApiError } from '@/shared/api/errors';
import type {
  CommercialAccountDetail,
  CommercialAccountList,
  CommercialAccountSummary,
} from '@/shared/types';

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

const detail = (id: string): CommercialAccountDetail => ({
  ...account(id),
  permissions: {
    read_account: true,
    view_members: true,
    manage_members: true,
    manage_ownership: true,
    manage_billing: true,
    manage_technical: true,
  },
});

const page = (
  items: CommercialAccountSummary[],
  total: number,
  limit = 20,
  offset = 0,
): CommercialAccountList => ({ items, total, limit, offset });

describe('accountStore', () => {
  it('refreshes the current account without unmounting its form, but clears a denied refresh', async () => {
    const store = useAccountStore();
    vi.mocked(commercialAccount.getAccount).mockResolvedValue(detail('refresh'));
    await store.loadAccount('refresh');
    let finish!: (value: CommercialAccountDetail) => void;
    vi.mocked(commercialAccount.getAccount).mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
    const pending = store.refreshAccount('refresh');
    expect(store.accountDetail?.id).toBe('refresh');
    expect(store.detailLoading).toBe(false);
    finish({ ...detail('refresh'), legal_name: 'Updated company' }); await pending;
    expect(store.accountDetail?.legal_name).toBe('Updated company');
    vi.mocked(commercialAccount.getAccount).mockRejectedValueOnce(new ApiError(403, 'forbidden', 'denied'));
    await expect(store.refreshAccount('refresh')).rejects.toBeInstanceOf(ApiError);
    expect(store.accountDetail).toBeNull();
  });
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

  it('ignores a stale list response from an earlier request', async () => {
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

  it('loads account detail and exposes role capabilities', async () => {
    vi.mocked(commercialAccount.getAccount).mockResolvedValue(detail('a'));

    const store = useAccountStore();
    await store.loadAccount('a');

    expect(store.selectedAccountId).toBe('a');
    expect(store.accountDetail?.id).toBe('a');
    expect(store.accountDetail?.permissions.view_members).toBe(true);
    expect(store.detailError).toBeNull();
    expect(store.detailLoading).toBe(false);
  });

  it('clears the previous detail immediately when switching accounts', async () => {
    let resolveB: (value: CommercialAccountDetail) => void = () => {};
    const pendingB = new Promise<CommercialAccountDetail>((resolve) => {
      resolveB = resolve;
    });
    vi.mocked(commercialAccount.getAccount)
      .mockResolvedValueOnce(detail('a'))
      .mockReturnValueOnce(pendingB);

    const store = useAccountStore();
    await store.loadAccount('a');
    expect(store.accountDetail?.id).toBe('a');

    const loadingB = store.loadAccount('b');
    expect(store.accountDetail).toBeNull();
    expect(store.selectedAccountId).toBe('b');

    resolveB(detail('b'));
    await loadingB;
    expect(store.accountDetail?.id).toBe('b');
  });

  it('ignores a stale detail response', async () => {
    let resolveA: (value: CommercialAccountDetail) => void = () => {};
    const pendingA = new Promise<CommercialAccountDetail>((resolve) => {
      resolveA = resolve;
    });
    vi.mocked(commercialAccount.getAccount)
      .mockReturnValueOnce(pendingA)
      .mockResolvedValueOnce(detail('b'));

    const store = useAccountStore();
    const first = store.loadAccount('a');
    const second = store.loadAccount('b');
    await second;
    resolveA(detail('a'));
    await first;

    expect(store.accountDetail?.id).toBe('b');
    expect(store.selectedAccountId).toBe('b');
  });

  it('stores detail errors without exposing stale data', async () => {
    vi.mocked(commercialAccount.getAccount).mockRejectedValue(
      new ApiError(404, 'resource_not_found', 'not found'),
    );

    const store = useAccountStore();
    await store.loadAccount('private');

    expect(store.accountDetail).toBeNull();
    expect(store.detailError).not.toBeNull();
    expect(store.detailErrorMessage).not.toBeNull();
  });

  it('clears list and detail on logout / session end', async () => {
    vi.mocked(commercialAccount.listAccounts).mockResolvedValue(page([account('1')], 1));
    vi.mocked(commercialAccount.getAccount).mockResolvedValue(detail('1'));

    const store = useAccountStore();
    await store.loadPage(0);
    await store.loadAccount('1');
    expect(store.accountDetail).not.toBeNull();

    endSession();
    await nextTick();

    expect(store.accounts).toHaveLength(0);
    expect(store.total).toBe(0);
    expect(store.accountDetail).toBeNull();
    expect(store.selectedAccountId).toBeNull();
  });

  it('drops detail when a different user signs in', async () => {
    vi.mocked(commercialAccount.getAccount).mockResolvedValue(detail('1'));

    const store = useAccountStore();
    await store.loadAccount('1');

    beginSession({ access_token: 'access-b', refresh_token: 'refresh-b' });
    await nextTick();

    expect(store.accountDetail).toBeNull();
    expect(store.selectedAccountId).toBeNull();
  });
});
