import type { HttpClient } from '../http';
import { isCommercialAccountList, isCommercialAccountSummary, isCreateAccountResult, isCurrentUser } from '../guards';
import type {
  CommercialAccountList,
  CommercialAccountSummary,
  CreateAccountPayload,
  CreateAccountResult,
  CurrentUser,
} from '@/shared/types';

export interface CommercialAccountApi {
  me(): Promise<CurrentUser>;
  listAccounts(limit?: number, offset?: number): Promise<CommercialAccountList>;
  getAccount(accountId: string): Promise<CommercialAccountSummary>;
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
      http.get<CommercialAccountSummary>(`/accounts/${accountId}`, { validate: isCommercialAccountSummary }),
    createAccount: (payload, idempotencyKey) =>
      http.post<CreateAccountResult>('/accounts', payload, {
        idempotencyKey,
        validate: isCreateAccountResult,
      }),
  };
}
