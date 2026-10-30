import type { Ref } from 'vue';
import { commercialAccount } from '@/core/api';
import { useSensitiveAction } from '@/shared/composables/useSensitiveAction';
import type { AccountRole } from '@/shared/types';

export function useMembershipActions(accountId: Ref<string>, onChanged: () => Promise<void>) {
  const sensitive = useSensitiveAction();

  async function changeRole(membershipId: string, role: AccountRole): Promise<boolean> {
    return sensitive.run(async () => {
      await commercialAccount.changeMembershipRole(accountId.value, membershipId, role);
      await onChanged();
    });
  }

  async function remove(membershipId: string): Promise<boolean> {
    return sensitive.run(async () => {
      await commercialAccount.removeMembership(accountId.value, membershipId);
      await onChanged();
    });
  }

  return { changeRole, remove, ...sensitive };
}
