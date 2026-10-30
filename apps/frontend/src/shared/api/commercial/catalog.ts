import type { HttpClient } from '../http';
import { isPlanCatalogResponse } from '../guards';
import type { PlanCatalogResponse } from '@/shared/types';

export interface CommercialCatalogApi {
  listPlans(): Promise<PlanCatalogResponse>;
}

export function createCommercialCatalogApi(http: HttpClient): CommercialCatalogApi {
  return {
    listPlans: () =>
      http.get<PlanCatalogResponse>('/catalog/plans', { validate: isPlanCatalogResponse }),
  };
}
