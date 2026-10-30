import { getAccessToken, runUnauthorizedHandler } from '@/core/auth/session';
import { createHttpClient } from '../http';
import { createCommercialAccountApi } from './account';
import { createCommercialAuthApi, refreshAccessToken } from './auth';
import { createCommercialCatalogApi } from './catalog';

const http = createHttpClient({
  baseUrl: '/api/control',
  getAccessToken,
  refreshTokens: refreshAccessToken,
  onUnauthorized: runUnauthorizedHandler,
});

export const commercialAuth = createCommercialAuthApi(http);
export const commercialAccount = createCommercialAccountApi(http);
export const commercialCatalog = createCommercialCatalogApi(http);
