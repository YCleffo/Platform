import type { Ref } from 'vue';
import { commercialInvitation } from '@/core/api';
import { useSensitiveAction } from '@/shared/composables/useSensitiveAction';
import type { CreateInvitationPayload } from '@/shared/types';

export function useInvitationActions(accountId: Ref<string>, onChanged: () => Promise<void>) {
  const sensitive = useSensitiveAction();

  async function create(payload: CreateInvitationPayload, idempotencyKey: string): Promise<boolean> {
    return sensitive.run(async () => {
      await commercialInvitation.create(accountId.value, payload, idempotencyKey);
      await onChanged();
    });
  }

  async function resend(invitationId: string): Promise<boolean> {
    return sensitive.run(async () => {
      await commercialInvitation.resend(accountId.value, invitationId);
      await onChanged();
    });
  }

  async function revoke(invitationId: string): Promise<boolean> {
    return sensitive.run(async () => {
      await commercialInvitation.revoke(accountId.value, invitationId);
      await onChanged();
    });
  }

  return { create, resend, revoke, ...sensitive };
}
