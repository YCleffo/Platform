import type { HttpClient } from '@/shared/api/http';
import type { AccountAnalytics } from '@/shared/types';
import { isAccountAnalytics } from '../guards';

export interface CommercialAnalyticsApi {
  get(accountId: string, days: number): Promise<AccountAnalytics>;
}

export function createCommercialAnalyticsApi(http: HttpClient): CommercialAnalyticsApi {
  return {
    get: (accountId, days) => http.get<AccountAnalytics>(
      `/accounts/${encodeURIComponent(accountId)}/analytics?days=${encodeURIComponent(String(days))}`,
      { validate: isAccountAnalytics },
    ),
  };
}
