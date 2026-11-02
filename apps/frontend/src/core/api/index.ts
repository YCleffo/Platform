import { getAccessToken, getSessionEpoch, runUnauthorizedHandler } from '@/core/auth/session';
import { createCommercialAccountApi } from '@/shared/api/commercial/account';
import { createCommercialAnalyticsApi } from '@/shared/api/commercial/analytics';
import { createCommercialAuditApi } from '@/shared/api/commercial/audit';
import { createCommercialAuthApi } from '@/shared/api/commercial/auth';
import { createCommercialBillingApi } from '@/shared/api/commercial/billing';
import { createCommercialCatalogApi } from '@/shared/api/commercial/catalog';
import { createCommercialInvitationApi } from '@/shared/api/commercial/invitations';
import { createCommercialPrivacyApi } from '@/shared/api/commercial/privacy';
import { createCommercialUsageApi } from '@/shared/api/commercial/usage';
import { createHttpClient } from '@/shared/api/http';
import { refreshAccessToken } from './refresh';

// Composition root for the Commercial Control Plane API.
// The transport itself lives in shared/api and is unaware of the session store.
const http = createHttpClient({
  baseUrl: '/api/control',
  getAccessToken,
  getSessionEpoch,
  refreshTokens: refreshAccessToken,
  onUnauthorized: runUnauthorizedHandler,
});

export const commercialAuth = createCommercialAuthApi(http);
export const commercialAccount = createCommercialAccountApi(http);
export const commercialCatalog = createCommercialCatalogApi(http);
export const commercialInvitation = createCommercialInvitationApi(http);
export const commercialBilling = createCommercialBillingApi(http);
export const commercialUsage = createCommercialUsageApi(http);
export const commercialAudit = createCommercialAuditApi(http);
export const commercialAnalytics = createCommercialAnalyticsApi(http);
export const commercialPrivacy = createCommercialPrivacyApi(http);
