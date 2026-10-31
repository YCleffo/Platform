import { createHash } from 'node:crypto';

/**
 * Mutating E2E is refused unless an explicitly approved isolated runtime is configured.
 * A single environment variable is not enough: runtime marker, isolated DB marker,
 * write permission, explicit URLs, an allowlisted host, non-default credentials and
 * a server-issued isolation nonce are all required. The CI job provisions a disposable
 * Docker stack and passes the nonce to the test control plane; the guard verifies it
 * over HTTP before mutating any data.
 */
export async function evaluateMutationGate(env, { verifyRuntime = true } = {}) {
  const reasons = [];
  if (env.E2E_RUNTIME !== 'isolated-ci') reasons.push('E2E_RUNTIME must be "isolated-ci"');
  if (env.E2E_ISOLATED_DB !== '1') reasons.push('E2E_ISOLATED_DB must be "1"');
  if (env.E2E_ALLOW_MUTATIONS !== '1') reasons.push('E2E_ALLOW_MUTATIONS must be "1"');
  if (!env.E2E_PASSWORD) reasons.push('E2E_PASSWORD is required (no default credentials)');
  if (!env.E2E_ISOLATION_PROOF || env.E2E_ISOLATION_PROOF.length < 32) {
    reasons.push('E2E_ISOLATION_PROOF must be a runtime-provided nonce (at least 32 characters)');
  }

  const allowedHosts = (env.E2E_ALLOWED_HOSTS ?? '')
    .split(',')
    .map((host) => host.trim())
    .filter(Boolean);
  if (allowedHosts.length === 0) reasons.push('E2E_ALLOWED_HOSTS is required');

  for (const key of ['APP_URL', 'CONTROL_API_URL', 'MAILPIT_URL']) {
    const value = env[key];
    if (!value) {
      reasons.push(`${key} is required`);
      continue;
    }
    try {
      const url = new URL(value);
      if (url.protocol !== 'http:' && url.protocol !== 'https:') reasons.push(`${key} must use http(s)`);
      if (!allowedHosts.includes(url.hostname)) reasons.push(`${key} host "${url.hostname}" is not allowlisted`);
      if (isLocalHost(url.hostname)) reasons.push(`${key} must point at an isolated CI runtime, not a local working environment`);
    } catch {
      reasons.push(`${key} is not a valid URL`);
    }
  }

  if (verifyRuntime && reasons.length === 0) {
    try {
      const response = await fetch(`${env.CONTROL_API_URL}/health/isolation`, {
        signal: AbortSignal.timeout(5_000),
      });
      const body = await response.json().catch(() => null);
      const expected = createHash('sha256').update(env.E2E_ISOLATION_PROOF).digest('hex');
      if (response.status !== 200 || body?.nonce !== expected) {
        reasons.push('the target runtime did not prove the E2E isolation nonce');
      }
    } catch {
      reasons.push('the target runtime isolation proof is unavailable');
    }
  }

  return { allowed: reasons.length === 0, reasons };
}

function isLocalHost(hostname) {
  const host = hostname.toLowerCase();
  return (
    host === 'localhost' ||
    host === '::1' ||
    host === '[::1]' ||
    host === '0.0.0.0' ||
    host.startsWith('127.') ||
    host.endsWith('.localhost') ||
    host.startsWith('10.') ||
    host.startsWith('192.168.') ||
    /^172\.(1[6-9]|2\d|3[01])\./.test(host)
  );
}
