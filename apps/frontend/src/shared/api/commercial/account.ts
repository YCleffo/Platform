import type { HttpClient } from '../http';
import {
  isCatalogReviewed,
  isCommercialAccountDetail,
  isCommercialAccountList,
  isCommercialMembershipList,
  isCreateAccountResult,
  isCurrentUser,
} from '../guards';
import type {
  AccountRole,
  CatalogReviewed,
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
  reviewCatalog(accountId: string): Promise<CatalogReviewed>;
  addMembership(accountId: string, userId: string, role: AccountRole): Promise<{id:string}>;
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
    reviewCatalog: (accountId) =>
      http.post<CatalogReviewed>(
        `/accounts/${accountId}/onboarding/catalog-reviewed`,
        {},
        { validate: isCatalogReviewed },
      ),
    changeMembershipRole: (accountId, membershipId, role) =>
      http.patch<void>(`/accounts/${accountId}/memberships/${membershipId}`, { role }),
    addMembership: (accountId, userId, role) => http.post<{id:string}>(`/accounts/${accountId}/memberships`, {user_id:userId,role}, {validate:(value):value is {id:string}=>typeof value==='object'&&value!==null&&typeof (value as {id?:unknown}).id==='string'}),
    removeMembership: (accountId, membershipId) =>
      http.delete<void>(`/accounts/${accountId}/memberships/${membershipId}`),
  };
}
