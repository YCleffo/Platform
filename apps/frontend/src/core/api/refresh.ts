import { getRefreshToken, rotateSession } from '@/core/auth/session';
import { ApiError, NetworkError } from '@/shared/api/errors';
import type { RefreshOutcome } from '@/shared/api/http';
import { isSessionTokens } from '@/shared/api/guards';

const REFRESH_URL = '/api/control/auth/refresh';

/**
 * Performs a single refresh attempt for the given session epoch.
 * Never retries on its own and never restores a session that was replaced or cleared meanwhile.
 */
export async function refreshAccessToken(expectedEpoch: number): Promise<RefreshOutcome> {
  const refreshToken = getRefreshToken();
  if (refreshToken === null) {
    return { status: 'expired' };
  }

  let response: Response;
  try {
    response = await fetch(REFRESH_URL, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ refresh_token: refreshToken }),
    });
  } catch {
    return { status: 'unavailable', error: new NetworkError() };
  }

  if (response.status === 204 || response.status === 401 || response.status === 403) {
    return { status: 'expired' };
  }
  if (response.status === 429 || response.status >= 500) {
    return {
      status: 'unavailable',
      error: new ApiError(response.status, 'refresh_unavailable', 'The session could not be refreshed.'),
    };
  }
  if (!response.ok) {
    return { status: 'expired' };
  }

  let payload: unknown = null;
  try {
    payload = JSON.parse(await response.text()) as unknown;
  } catch {
    payload = null;
  }

  if (!isSessionTokens(payload)) {
    return { status: 'expired' };
  }
  if (!rotateSession(payload, expectedEpoch)) {
    return { status: 'stale' };
  }
  return { status: 'refreshed', tokens: payload };
}
