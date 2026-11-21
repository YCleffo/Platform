// Real HTTP + PostgreSQL + SMTP + MFA. This covers a zero-price lifecycle, not a paid provider sandbox.
import assert from 'node:assert/strict';
import { createHmac, randomBytes } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { mkdirSync, writeFileSync } from 'node:fs';
import { evaluateMutationGate } from './e2e-guard.mjs';
import { createCommercialAccount, dialog, launchBrowser, navigateClientSide, registerAndVerify, selectDropdown, stableScreenshot } from './e2e-support.mjs';

const env = process.env;
if (process.argv.includes('--check-guard')) {
  const gate = await evaluateMutationGate(env, { verifyRuntime: false });
  assert.equal(gate.allowed, false, 'Billing mutations must refuse a non-isolated runtime');
  console.log('Billing mutation guard refuses without an isolated runtime.');
  process.exit(0);
}
const gate = await evaluateMutationGate(env);
assert.equal(gate.allowed, true, gate.reasons.join('; '));
const APP = env.APP_URL; const API = env.CONTROL_API_URL;
const postgres = execFileSync('docker', ['compose', '--profile', 'control-test', 'ps', '-q', 'control-postgres-test'], { encoding: 'utf8', cwd: fileURLToPath(new URL('../../../', import.meta.url)) }).trim();
assert.ok(postgres);
const meta = JSON.parse(execFileSync('docker', ['inspect', postgres], { encoding: 'utf8' }))[0];
assert.equal(meta.Config.Labels['com.docker.compose.service'], 'control-postgres-test');
assert.equal(meta.Config.Labels['com.docker.compose.project'], env.COMPOSE_PROJECT_NAME ?? 'platform');
assert.ok(meta.Config.Env.includes('POSTGRES_DB=platform_commercial_test'));
const suffix = randomBytes(6).toString('hex');
const sql = `BEGIN; DO $$ BEGIN IF current_database()<>'platform_commercial_test' THEN RAISE EXCEPTION 'Isolated billing fixture required'; END IF; END $$;
WITH plan AS (INSERT INTO commercial.plans(code,version,name) VALUES('browser.free.${suffix}',1,'Browser zero-price plan') RETURNING id)
INSERT INTO commercial.plan_prices(plan_id,currency,amount_minor,billing_period) SELECT id,'RUB',0,'month' FROM plan RETURNING id;
UPDATE commercial.plans SET status='published',published_at=now() WHERE code='browser.free.${suffix}'; COMMIT;`;
const priceId = execFileSync('docker', ['exec', '-i', postgres, 'sh', '-c', 'exec psql -X -qAt -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"'], { input: sql, encoding: 'utf8' }).trim();
assert.match(priceId, /^[0-9a-f-]{36}$/);
const email = `billing-${suffix}@example.test`;
await registerAndVerify({ api: API, mailpit: env.MAILPIT_URL, email, password: env.E2E_PASSWORD });
const browser = await launchBrowser(env.E2E_BROWSER_HOST_RULES ? { args: [`--host-resolver-rules=${env.E2E_BROWSER_HOST_RULES}`] } : {});
const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } });
await context.addInitScript(() => { localStorage.setItem('platform.locale', 'en'); localStorage.setItem('platform.theme', 'dark'); });
const page = await context.newPage(); const errors = []; const states = [];
const apiShapes = [];
let access;
page.on('pageerror', error => errors.push(error.message));
page.on('response', async response => {
  const path = new URL(response.url()).pathname;
  if (path.startsWith('/api/control/accounts/')) {
    const body = await response.json().catch(() => null);
    if (body) apiShapes.push({ path: path.replace(/[0-9a-f-]{36}/gi, ':id'), method: response.request().method(), status: response.status(),
      fields: Object.fromEntries(Object.entries(body).map(([key, value]) => [key, value === null ? 'null' : Array.isArray(value) ? 'array' : typeof value])), error: body.error?.code });
  }
  if (/\/api\/control\/auth\/(login|mfa\/login\/verify)$/.test(new URL(response.url()).pathname) && response.ok()) {
    const body = await response.json().catch(() => null);
    if (body?.access_token) access = body.access_token;
  }
});
mkdirSync('screenshots', { recursive: true });
function codeFactory(secret) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const bits = [...secret].map(char => alphabet.indexOf(char).toString(2).padStart(5, '0')).join('');
  const key = Buffer.from(bits.match(/.{8}/g).map(byte => parseInt(byte, 2)));
  let previous = 0;
  return async () => {
    const counter = Math.max(Math.floor(Date.now() / 30000), previous + 1);
    const wait = counter * 30000 + 250 - Date.now();
    if (wait > 0) await new Promise(resolve => setTimeout(resolve, wait));
    previous = counter; const buffer = Buffer.alloc(8); buffer.writeBigUInt64BE(BigInt(counter));
    const mac = createHmac('sha1', key).update(buffer).digest(); const offset = mac.at(-1) & 15;
    return String((mac.readUInt32BE(offset) & 0x7fffffff) % 1000000).padStart(6, '0');
  };
}
async function login(nextCode) {
  await page.goto(APP + '/login'); await page.locator('#login-email').fill(email); await page.locator('#login-password').fill(env.E2E_PASSWORD);
  await page.locator('form button[type=submit]').click();
  if (nextCode) {
    await page.locator('#mfa-code').fill(await nextCode());
    const submitBtn = page.locator('form button[type=submit]');
    if (await submitBtn.isVisible().catch(() => false)) {
      await submitBtn.click().catch(() => {});
    }
  }
  await page.waitForURL(url => url.pathname === '/cabinet');
}
async function get(path) {
  assert.ok(access);
  let response;
  try { response = await fetch(API + path, { headers: { Authorization: `Bearer ${access}` }, signal: AbortSignal.timeout(10000) }); }
  catch { throw new Error('The isolated billing API is unavailable. Request headers are suppressed.'); }
  assert.equal(response.status, 200); return response.json();
}
try {
  await login(); await navigateClientSide(page, '/settings/security');
  await page.getByRole('button', { name: 'Connect authenticator', exact: true }).click();
  const setup = dialog(page, 'Connect authenticator'); await setup.locator('.mfa-setup__secret').waitFor();
  const nextCode = codeFactory((await setup.locator('.mfa-setup__secret').textContent()).trim());
  await setup.locator('#totp-code').fill(await nextCode()); await setup.getByRole('button', { name: 'Confirm code', exact: true }).click();
  await setup.getByRole('button', { name: 'I have saved the codes', exact: true }).click();
  await navigateClientSide(page, '/cabinet');
  const accountId = await createCommercialAccount(page, `billing-${suffix}`, `Billing ${suffix}`);
  await navigateClientSide(page, `/accounts/${accountId}/billing`);
  await page.locator('#billing-legal-name').fill('Real isolated billing company');
  await page.locator('#billing-contact-email').fill(email); await page.locator('#billing-city').fill('Saved test city');
  await page.getByRole('button', { name: 'Save billing profile', exact: true }).click();
  const reauth = dialog(page, 'Confirm your identity'); await reauth.waitFor();
  await reauth.locator('#reauth-password').fill(env.E2E_PASSWORD); await reauth.locator('#reauth-code').fill(await nextCode());
  await reauth.getByRole('button', { name: 'Confirm identity', exact: true }).click();
  await page.getByText('Billing profile saved.', { exact: true }).waitFor();
  await page.getByRole('button', { name: 'Confirm catalog review', exact: true }).click();
  await page.getByText('Catalog review confirmed. Subscription is available.', { exact: true }).waitFor();
  await selectDropdown(page, 'billing-plan', priceId);
  await page.getByRole('button', { name: 'Create subscription and continue', exact: true }).click();
  await page.locator('.billing__badges').getByText('Active', { exact: true }).waitFor();
  const state = await get(`/api/control/accounts/${accountId}/billing-state`);
  assert.ok(state.subscription_id); assert.equal(state.pending_invoice_id, null); assert.equal(state.pending_payment_id, null);
  const payment = await get(`/api/control/accounts/${accountId}/payments/${state.latest_payment_id}`);
  assert.equal(payment.status, 'succeeded'); assert.equal(payment.amount_minor, 0); assert.equal(payment.attempts.length, 0);
  assert.equal((await get(`/api/control/accounts/${accountId}/entitlements`)).subscription.id, state.subscription_id);
  states.push('real SMTP registration, MFA enrollment/step-up, profile/catalog, zero-price invoice/payment and active entitlement; no provider charge');
  await page.reload(); await page.waitForURL(url => url.pathname === '/login');
  await login(nextCode); await navigateClientSide(page, `/accounts/${accountId}/billing`);
  assert.equal(await page.locator('#billing-legal-name').inputValue(), 'Real isolated billing company');
  assert.equal(await page.locator('#billing-city').inputValue(), 'Saved test city');
  assert.equal((await get(`/api/control/accounts/${accountId}/billing-state`)).subscription_id, state.subscription_id);
  await page.getByRole('button', { name: 'Cancel auto-renewal', exact: true }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Cancel auto-renewal', exact: true }).click();
  await page.getByText('Auto-renewal is disabled.', { exact: true }).waitFor();
  assert.equal((await get(`/api/control/accounts/${accountId}/subscriptions/${state.subscription_id}`)).status, 'active');
  await stableScreenshot(page, 'screenshots/billing-real-zero-price.png');
  await page.getByRole('button', { name: 'End access now', exact: true }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'End access now', exact: true }).click();
  await page.getByText('Subscription cancelled.', { exact: true }).waitFor();
  assert.equal((await get(`/api/control/accounts/${accountId}/entitlements`)).subscription, null);
  states.push('reload and MFA login restore exact profile/subscription; auto-renew cancellation keeps active access; immediate cancellation removes entitlement');
  assert.deepEqual(errors, []); writeFileSync('screenshots/billing-real-results.json', JSON.stringify({ passed: true, boundary: 'real zero-price lifecycle; paid provider not exercised', states }, null, 2));
  console.log(JSON.stringify({ passed: true, boundary: 'real zero-price lifecycle; paid provider not exercised', states }));
} catch (error) {
  console.error('Real billing browser state:', new URL(page.url()).pathname, await page.locator('[role=alert]').allTextContents(), errors, apiShapes.slice(-8));
  await stableScreenshot(page, 'screenshots/billing-real-failure.png'); throw error;
} finally { await context.close(); await browser.close(); }
