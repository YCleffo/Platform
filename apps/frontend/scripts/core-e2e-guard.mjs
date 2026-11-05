import { createHash } from 'node:crypto';

/** Check both the direct Core API and the frontend proxy before the first mutation. */
export async function evaluateCoreMutationGate(env, fetchRuntime = fetch) {
  const reasons = [];
  if (env.E2E_RUNTIME !== 'isolated-ci') reasons.push('E2E_RUNTIME must be isolated-ci');
  if (env.E2E_ISOLATED_DB !== '1') reasons.push('E2E_ISOLATED_DB must be 1');
  if (env.E2E_ALLOW_MUTATIONS !== '1') reasons.push('E2E_ALLOW_MUTATIONS must be 1');
  if (!env.E2E_PASSWORD) reasons.push('E2E_PASSWORD must be supplied without a default');
  if (!env.E2E_ISOLATION_PROOF || env.E2E_ISOLATION_PROOF.length < 32) reasons.push('A fresh isolation nonce is required');
  const hosts = (env.E2E_ALLOWED_HOSTS ?? '').split(',').map(host => host.trim()).filter(Boolean);
  for (const key of ['APP_URL', 'CORE_API_URL', 'MAILPIT_URL']) {
    try {
      const url = new URL(env[key]);
      if (!['http:', 'https:'].includes(url.protocol) || !hosts.includes(url.hostname)
        || url.username || url.password || url.search || url.hash || url.pathname !== '/') reasons.push(`${key} must be an allowlisted origin`);
    } catch { reasons.push(`${key} must be an explicit URL`); }
  }
  if (reasons.length === 0) {
    const expected = createHash('sha256').update(env.E2E_ISOLATION_PROOF).digest('hex');
    for (const origin of [env.CORE_API_URL, env.APP_URL]) {
      try {
        const response = await fetchRuntime(`${origin.replace(/\/$/, '')}/api/health/isolation`, { signal: AbortSignal.timeout(5000) });
        const body = await response.json();
        if (response.status !== 200 || body?.status !== 'success' || body?.data?.nonce !== expected) {
          reasons.push('Core or frontend proxy did not prove the disposable database nonce');
        }
      } catch { reasons.push('Core isolation proof is unavailable'); }
    }
  }
  return { allowed: reasons.length === 0, reasons };
}
