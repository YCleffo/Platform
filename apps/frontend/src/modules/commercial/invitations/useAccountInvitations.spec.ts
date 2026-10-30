import { beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';
import { commercialInvitation } from '@/core/api';
import { endSession } from '@/core/auth/session';
import { useAccountInvitations } from './useAccountInvitations';
import type { AccountInvitation, AccountInvitationList } from '@/shared/types';

vi.mock('@/core/api', () => ({
  commercialAuth: {},
  commercialAccount: {},
  commercialCatalog: {},
  commercialInvitation: {
    list: vi.fn(),
    create: vi.fn(),
    revoke: vi.fn(),
    resend: vi.fn(),
    preview: vi.fn(),
    accept: vi.fn(),
    decline: vi.fn(),
  },
}));

const invitation = (id: string): AccountInvitation => ({
  id,
  email: `${id}@example.test`,
  role: 'technical',
  status: 'pending',
  created_at: '2026-10-30T15:00:00Z',
  expires_at: '2026-11-06T15:00:00Z',
  invited_by: '018f0000-0000-7000-8000-000000000001',
  inviter_email: 'owner@example.test',
});

const page = (items: AccountInvitation[], total: number, limit = 20, offset = 0): AccountInvitationList => ({
  items,
  total,
  limit,
  offset,
});

function run(enabled = true) {
  const scope = effectScope();
  const state = scope.run(() => useAccountInvitations(ref('account-1'), ref(enabled)));
  if (state === undefined) {
    throw new Error('Effect scope failed.');
  }
  return { scope, state };
}

describe('useAccountInvitations', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    endSession();
  });

  it('loads a page and paginates', async () => {
    vi.mocked(commercialInvitation.list).mockImplementation(async (_id, limit = 20, offset = 0) =>
      page([invitation(String(offset + 1))], 45, limit, offset),
    );
    const { scope, state } = run();

    await vi.waitFor(() => expect(state.total.value).toBe(45));
    expect(state.invitations.value).toHaveLength(1);
    expect(state.page.value).toBe(1);

    state.nextPage();
    await vi.waitFor(() => expect(state.offset.value).toBe(20));
    expect(state.page.value).toBe(2);

    state.prevPage();
    await vi.waitFor(() => expect(state.offset.value).toBe(0));
    scope.stop();
  });

  it('does not request when disabled', async () => {
    const { scope, state } = run(false);
    await nextTick();

    expect(commercialInvitation.list).not.toHaveBeenCalled();
    expect(state.invitations.value).toHaveLength(0);
    scope.stop();
  });

  it('clears invitations on session end', async () => {
    vi.mocked(commercialInvitation.list).mockResolvedValue(page([invitation('1')], 1));
    const { scope, state } = run();
    await vi.waitFor(() => expect(state.invitations.value).toHaveLength(1));

    endSession();
    await nextTick();

    expect(state.invitations.value).toHaveLength(0);
    expect(state.total.value).toBe(0);
    scope.stop();
  });

  it('ignores a stale page response', async () => {
    let resolveFirst: (value: AccountInvitationList) => void = () => {};
    const first = new Promise<AccountInvitationList>((resolve) => {
      resolveFirst = resolve;
    });
    vi.mocked(commercialInvitation.list)
      .mockResolvedValueOnce(page([], 0))
      .mockReturnValueOnce(first)
      .mockResolvedValueOnce(page([invitation('second')], 40, 20, 20));
    const { scope, state } = run();
    await vi.waitFor(() => expect(state.loading.value).toBe(false));

    const stale = state.loadPage(0);
    const fresh = state.loadPage(20);
    await fresh;
    resolveFirst(page([invitation('first')], 40, 20, 0));
    await stale;

    expect(state.invitations.value[0]?.id).toBe('second');
    expect(state.offset.value).toBe(20);
    scope.stop();
  });
});
