import type { HttpClient } from '../http';
import {
  isCommercialAccountDetail,
  isCommercialAccountList,
  isCommercialMembershipList,
  isCreateAccountResult,
  isCurrentUser,
} from '../guards';
import type {
  AccountRole,
  CommercialAccountDetail,
  CommercialAccountList,
  CommercialMembershipList,
  CreateAccountPayload,
  CreateAccountResult,
  CurrentUser,
} from '@/shared/types';

export interface CommercialAccountApi {
  me(signal?: AbortSignal): Promise<CurrentUser>;
  listAccounts(limit?: number, offset?: number): Promise<CommercialAccountList>;
  getAccount(accountId: string): Promise<CommercialAccountDetail>;
  listMemberships(accountId: string, limit?: number, offset?: number): Promise<CommercialMembershipList>;
  createAccount(payload: CreateAccountPayload, idempotencyKey: string): Promise<CreateAccountResult>;
  changeMembershipRole(accountId: string, membershipId: string, role: AccountRole): Promise<void>;
  removeMembership(accountId: string, membershipId: string): Promise<void>;
}

export function createCommercialAccountApi(http: HttpClient): CommercialAccountApi {
  return {
    me: (signal) => http.get<CurrentUser>('/me', { validate: isCurrentUser, signal }),
    listAccounts: (limit = 20, offset = 0) =>
      http.get<CommercialAccountList>('/accounts', {
        query: { limit, offset },
        validate: isCommercialAccountList,
      }),
    getAccount: (accountId) =>
      http.get<CommercialAccountDetail>(`/accounts/${accountId}`, { validate: isCommercialAccountDetail }),
    listMemberships: (accountId, limit = 50, offset = 0) =>
      http.get<CommercialMembershipList>(`/accounts/${accountId}/memberships`, {
        query: { limit, offset },
        validate: isCommercialMembershipList,
      }),
    createAccount: (payload, idempotencyKey) =>
      http.post<CreateAccountResult>('/accounts', payload, {
        idempotencyKey,
        validate: isCreateAccountResult,
      }),
    changeMembershipRole: (accountId, membershipId, role) =>
      http.patch<void>(`/accounts/${accountId}/memberships/${membershipId}`, { role }),
    removeMembership: (accountId, membershipId) =>
      http.delete<void>(`/accounts/${accountId}/memberships/${membershipId}`),
  };
}
