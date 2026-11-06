import { randomUUID } from 'node:crypto';
import { waitForVerificationToken } from '../../apps/frontend/scripts/e2e-support.mjs';

const api = process.env.CONTROL_API_URL ?? 'http://127.0.0.1:8090';
const mailpit = process.env.MAILPIT_URL ?? 'http://127.0.0.1:8025';
const email = process.env.DEMO_EMAIL ?? 'demo-owner@example.test';
const password = process.env.DEMO_PASSWORD;
const hostname = new URL(api).hostname;
if (!['127.0.0.1', 'localhost', '::1'].includes(hostname)) {
  throw new Error('Demo Commercial seed refuses a non-local CONTROL_API_URL.');
}
if (!password || password.length < 12) {
  throw new Error('Set DEMO_PASSWORD to a local demo password of at least 12 characters.');
}

async function login() {
  const response = await fetch(`${api}/api/control/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password }),
  });
  if (response.status === 200) return response.json();
  if (response.status !== 401) {
    throw new Error(`Demo login failed: HTTP ${response.status} ${await response.text()}`);
  }
  await fetch(`${mailpit}/api/v1/messages`, { method: 'DELETE' }).catch(() => undefined);
  const registered = await fetch(`${api}/api/control/auth/register`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password }),
  });
  if (registered.status === 202) {
    const token = await waitForVerificationToken(mailpit, email);
    const verified = await fetch(`${api}/api/control/auth/verify`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token }),
    });
    if (verified.status !== 200) throw new Error(`Demo verification failed: HTTP ${verified.status}`);
  } else if (registered.status === 429) {
    throw new Error('Registration is rate-limited. Wait five minutes and rerun the local demo seed.');
  } else if (registered.status === 409 || registered.status === 400) {
    const body = await registered.json().catch(() => null);
    if (registered.status === 400 && body?.error?.code !== 'email_unavailable') {
      throw new Error(`Demo registration failed: HTTP 400 ${JSON.stringify(body)}`);
    }
    const resent = await fetch(`${api}/api/control/auth/verification/resend`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email }),
    });
    if (resent.status !== 202) {
      throw new Error(`Existing demo user needs verification, but resend failed: HTTP ${resent.status}`);
    }
    const token = await waitForVerificationToken(mailpit, email);
    const verified = await fetch(`${api}/api/control/auth/verify`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token }),
    });
    if (verified.status !== 200) {
      throw new Error(`Existing demo user verification failed: HTTP ${verified.status}`);
    }
  } else {
    throw new Error(`Demo registration failed: HTTP ${registered.status} ${await registered.text()}`);
  }
  const retry = await fetch(`${api}/api/control/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password }),
  });
  if (retry.status !== 200) throw new Error(`Demo login after verification failed: HTTP ${retry.status}`);
  return retry.json();
}

const tokens = await login();
const headers = { Authorization: `Bearer ${tokens.access_token}`, 'Content-Type': 'application/json' };

async function accounts() {
  const response = await fetch(`${api}/api/control/accounts?limit=100`, { headers });
  if (!response.ok) throw new Error(`Account list failed: HTTP ${response.status}`);
  const body = await response.json();
  return body.items ?? body.accounts ?? [];
}

async function ensureAccount(code, displayName) {
  const existing = (await accounts()).find((account) => account.code === code);
  if (existing !== undefined) return existing;
  const response = await fetch(`${api}/api/control/accounts`, {
    method: 'POST',
    headers: { ...headers, 'Idempotency-Key': randomUUID() },
    body: JSON.stringify({ code, display_name: displayName, legal_name: null }),
  });
  if (response.status !== 201 && response.status !== 409) {
    throw new Error(`Create ${code} failed: HTTP ${response.status} ${await response.text()}`);
  }
  const created = (await accounts()).find((account) => account.code === code);
  if (created === undefined) throw new Error(`Created account ${code} is not visible.`);
  return created;
}

const workspace = await ensureAccount('demo-workspace', 'Demo Workspace');
const lab = await ensureAccount('demo-lab', 'Demo Lab');
console.log(JSON.stringify({
  seeded: true,
  login: email,
  accounts: [workspace.code, lab.code].filter(Boolean),
  note: 'Local demo data only. Password stays in DEMO_PASSWORD.',
}));
