import type { HttpClient } from '@/shared/api/http';
import type { PersonalDataExport } from '@/shared/types';
import { isPersonalDataExport } from '../guards';

export interface CommercialPrivacyApi {
  exportMyData(): Promise<PersonalDataExport>;
}

export function createCommercialPrivacyApi(http: HttpClient): CommercialPrivacyApi {
  return {
    exportMyData: () => http.get<PersonalDataExport>('/privacy/me/export', { validate: isPersonalDataExport }),
  };
}
