import type { HttpClient } from '../http';
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
    me: () => http.get<CurrentUser>('/me'),
    listAccounts: (limit = 50, offset = 0) =>
      http.get<CommercialAccountList>('/accounts', { query: { limit, offset } }),
    getAccount: (accountId) => http.get<CommercialAccountSummary>(`/accounts/${accountId}`),
    createAccount: (payload, idempotencyKey) =>
      http.post<CreateAccountResult>('/accounts', payload, { idempotencyKey }),
  };
}
