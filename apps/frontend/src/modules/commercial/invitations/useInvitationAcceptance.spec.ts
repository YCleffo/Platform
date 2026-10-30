import { beforeEach, describe, expect, it, vi } from 'vitest';
import { commercialInvitation } from '@/core/api';
import { endSession } from '@/core/auth/session';
import { useInvitationAcceptance } from './useInvitationAcceptance';
import { clearInvitationToken, setInvitationToken } from './invitationSession';
import type { InvitationPreview } from '@/shared/types';

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

const preview: InvitationPreview = {
  status: 'pending',
  organization_name: 'Academy',
  role: 'technical',
  expires_at: '2026-11-06T15:00:00Z',
  email: 'i***@example.test',
};

describe('useInvitationAcceptance', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    clearInvitationToken();
    window.history.replaceState(null, '', '/invitation');
    endSession();
  });

  it('captures the fragment token and previews the invitation', async () => {
    window.history.replaceState(null, '', '/invitation#token=fragment-token');
    vi.mocked(commercialInvitation.preview).mockResolvedValue(preview);

    const state = useInvitationAcceptance();
    await state.load();

    expect(commercialInvitation.preview).toHaveBeenCalledWith('fragment-token');
    expect(state.preview.value?.organization_name).toBe('Academy');
    expect(window.location.hash).toBe('');
  });

  it('accepts and clears the token from memory', async () => {
    setInvitationToken('accept-token');
    vi.mocked(commercialInvitation.preview).mockResolvedValue(preview);
    vi.mocked(commercialInvitation.accept).mockResolvedValue({
      account_id: '018f0000-0000-7000-8000-000000000001',
      role: 'technical',
      status: 'accepted',
    });

    const state = useInvitationAcceptance();
    await state.load();
    const result = await state.accept();

    expect(result?.status).toBe('accepted');
    expect(state.hasToken.value).toBe(false);
    void state.load();
    expect(commercialInvitation.preview).toHaveBeenCalledTimes(1);
  });

  it('declines without creating a membership', async () => {
    setInvitationToken('decline-token');
    vi.mocked(commercialInvitation.preview).mockResolvedValue(preview);
    vi.mocked(commercialInvitation.decline).mockResolvedValue({ status: 'declined' });

    const state = useInvitationAcceptance();
    await state.load();
    await expect(state.decline()).resolves.toBe(true);

    expect(state.declined.value).toBe(true);
    expect(state.hasToken.value).toBe(false);
  });

  it('surfaces an action error without clearing the token', async () => {
    setInvitationToken('error-token');
    vi.mocked(commercialInvitation.preview).mockResolvedValue(preview);
    vi.mocked(commercialInvitation.accept).mockRejectedValue(new Error('denied'));

    const state = useInvitationAcceptance();
    await state.load();
    await expect(state.accept()).resolves.toBeNull();

    expect(state.actionError.value).not.toBeNull();
    expect(state.hasToken.value).toBe(true);
  });

  it('ignores a preview response after the session changed', async () => {
    setInvitationToken('epoch-token');
    let resolvePreview: (value: InvitationPreview) => void = () => {};
    const pending = new Promise<InvitationPreview>((resolve) => {
      resolvePreview = resolve;
    });
    vi.mocked(commercialInvitation.preview).mockReturnValueOnce(pending);

    const state = useInvitationAcceptance();
    const load = state.load();
    endSession();
    resolvePreview(preview);
    await load;

    expect(state.preview.value).toBeNull();
  });
});
