import type { HttpClient } from '../http';
import {
  isCommercialAccountDetail,
  isCommercialAccountList,
  isCommercialMembershipList,
  isCreateAccountResult,
  isCurrentUser,
} from '../guards';
import type {
  CommercialAccountDetail,
  CommercialAccountList,
  CommercialMembershipList,
  CreateAccountPayload,
  CreateAccountResult,
  CurrentUser,
} from '@/shared/types';

export interface CommercialAccountApi {
  me(): Promise<CurrentUser>;
  listAccounts(limit?: number, offset?: number): Promise<CommercialAccountList>;
  getAccount(accountId: string): Promise<CommercialAccountDetail>;
  listMemberships(accountId: string, limit?: number, offset?: number): Promise<CommercialMembershipList>;
  createAccount(payload: CreateAccountPayload, idempotencyKey: string): Promise<CreateAccountResult>;
}

export function createCommercialAccountApi(http: HttpClient): CommercialAccountApi {
  return {
    me: () => http.get<CurrentUser>('/me', { validate: isCurrentUser }),
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
  };
}
