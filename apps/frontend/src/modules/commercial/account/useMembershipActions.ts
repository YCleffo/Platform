import type { Ref } from 'vue';
import { commercialAccount } from '@/core/api';
import { useSensitiveAction } from '@/shared/composables/useSensitiveAction';
import type { AccountRole } from '@/shared/types';
import { getSessionEpoch } from '@/core/auth/session';
import { SupersededOperationError } from '@/shared/api/errors';

export function useMembershipActions(accountId: Ref<string>, onChanged: () => Promise<void>) {
  const sensitive = useSensitiveAction();

  async function addMember(userId:string,role:AccountRole):Promise<boolean> {
    const target=accountId.value;const epoch=getSessionEpoch();
    return sensitive.run(async()=>{
      await commercialAccount.addMembership(target,userId,role);
      if(accountId.value!==target||getSessionEpoch()!==epoch)throw new SupersededOperationError();
      await onChanged();
    });
  }

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

  return { changeRole, remove, addMember, ...sensitive };
}
