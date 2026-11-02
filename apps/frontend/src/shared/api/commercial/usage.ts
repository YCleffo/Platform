import type { HttpClient } from '@/shared/api/http';
import type { AccountUsage, EffectiveEntitlements } from '@/shared/types';
import { isAccountUsage, isEffectiveEntitlements } from '../guards';

export interface CommercialUsageApi {
  entitlements(accountId: string): Promise<EffectiveEntitlements>;
  usage(accountId: string): Promise<AccountUsage>;
}

export function createCommercialUsageApi(http: HttpClient): CommercialUsageApi {
  return {
    entitlements: (accountId) =>
      http.get<EffectiveEntitlements>(`/accounts/${encodeURIComponent(accountId)}/entitlements`, { validate: isEffectiveEntitlements }),
    usage: (accountId) =>
      http.get<AccountUsage>(`/accounts/${encodeURIComponent(accountId)}/usage`, { validate: isAccountUsage }),
  };
}
