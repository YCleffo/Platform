import { getAccessToken, getSessionEpoch, runUnauthorizedHandler } from '@/core/auth/session';
import { createCommercialAccountApi } from '@/shared/api/commercial/account';
import { createCommercialAuthApi } from '@/shared/api/commercial/auth';
import { createCommercialCatalogApi } from '@/shared/api/commercial/catalog';
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
