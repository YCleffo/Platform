// Real Core HTTP/PostgreSQL/Redis and authenticator codes on a proven disposable runtime.
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { request as playwrightRequest } from 'playwright';
import { evaluateCoreMutationGate } from './core-e2e-guard.mjs';
import { launchBrowser, navigateClientSide, stableScreenshot } from './e2e-support.mjs';
const env = process.env;
if (process.argv.includes('--check-guard')) { const gate = await evaluateCoreMutationGate(env); assert.equal(gate.allowed, false); console.log('Core Security mutation guard refuses without a proven disposable runtime.'); process.exit(0); }
// CI serves the disposable frontend with a self-signed certificate; scope that exception here.
const proofClient = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
let gate;
try { gate = await evaluateCoreMutationGate(env, async url => {
  const response = await proofClient.get(url, { timeout: 5000 }); return { status: response.status(), json: () => response.json() };
}); } finally { await proofClient.dispose(); }
assert.equal(gate.allowed, true, gate.reasons.join('; '));
function fixture(command, email) {
  assert.ok(env.CORE_TEST_CONTAINER);
  try { return JSON.parse(execFileSync('docker', ['exec', env.CORE_TEST_CONTAINER, 'php', 'tests/e2e/core-auth-fixture.php', command, ...(email ? [email] : [])], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })); }
  catch { throw new Error('Isolated Core fixture failed. Private output suppressed.'); }
}
const actor = fixture('seed').accounts.reset;
const browser = await launchBrowser(); const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } });
await context.addInitScript(() => { localStorage.setItem('platform.locale', 'en'); localStorage.setItem('platform.theme', 'dark'); });
const page = await context.newPage(); const errors = []; const states = []; let access;
page.on('pageerror', error => errors.push(error.message));
page.on('response', async response => {
  if (/\/api\/auth\/(login|mfa\/verify|refresh)$/.test(new URL(response.url()).pathname) && response.ok()) {
    const result = await response.json().catch(() => null); if (result?.data?.accessToken) access = result.data.accessToken;
  }
});
async function request(path, { method = 'GET', body, token = access, status = 200 } = {}) {
  let response;
  try { response = await context.request.fetch(env.CORE_API_URL + '/api' + path, { method, headers: { 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) }, data: body, timeout: 10000 }); }
  catch { throw new Error('Isolated Core API unavailable; headers and private data suppressed.'); }
  assert.equal(response.status(), status, path + ': unexpected HTTP status');
  if (status !== 200) return;
  return (await response.json()).data;
}
function totpGenerator(secret) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; const bits = [...secret].map(char => alphabet.indexOf(char).toString(2).padStart(5, '0')).join('');
  const key = Buffer.from(bits.match(/.{8}/g).map(byte => parseInt(byte, 2))); let previous = -1;
  return async () => {
    const counter = Math.max(Math.floor(Date.now() / 30000), previous + 1); const wait = counter * 30000 + 250 - Date.now();
    if (wait > 0) await new Promise(resolve => setTimeout(resolve, wait)); previous = counter;
    const buffer = Buffer.alloc(8); buffer.writeBigUInt64BE(BigInt(counter)); const mac = createHmac('sha1', key).update(buffer).digest();
    return String((mac.readUInt32BE(mac.at(-1) & 15) & 0x7fffffff) % 1000000).padStart(6, '0');
  };
}
async function login(code) {
  await page.goto(env.APP_URL + '/admin/login?redirect=/admin/security/mfa'); await page.locator('#admin-identifier').fill(actor.email); await page.locator('#admin-password').fill(actor.password);
  await page.locator('form button[type=submit]').click();
  if (code) { await page.locator('#admin-totp').fill(await code()); await page.locator('form button[type=submit]').click(); }
  await page.waitForURL(url => url.pathname === '/admin/security/mfa');
}
mkdirSync('screenshots', { recursive: true });
try {
  await login(); const second = await request('/auth/login', { method: 'POST', token: null, body: { identifierType: 'email', identifier: actor.email, password: actor.password } });
  const third = await request('/auth/login', { method: 'POST', token: null, body: { identifierType: 'email', identifier: actor.email, password: actor.password } });
  await navigateClientSide(page, '/admin/security/sessions'); await page.locator('.core-sessions__item').nth(2).waitFor();
  const list = await request('/auth/sessions'); const target = list.findIndex(item => item.id === second.sessionId); assert.ok(target >= 0);
  await page.locator('.core-sessions__item').nth(target).getByRole('button', { name: 'Revoke', exact: true }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Revoke', exact: true }).click(); await page.getByText('Session revoked.', { exact: true }).waitFor();
  await request('/auth/me', { token: second.accessToken, status: 401 }); await request('/auth/me', { token: third.accessToken });
  await page.getByRole('button', { name: 'Revoke other sessions', exact: true }).click(); await page.getByRole('dialog').getByRole('button', { name: 'Revoke', exact: true }).click();
  await page.getByText('Other sessions revoked.', { exact: true }).waitFor(); await request('/auth/me', { token: third.accessToken, status: 401 });
  states.push('addressed session and remaining other sessions revoked through UI; current session remains valid');
  await navigateClientSide(page, '/admin/security/mfa'); await page.getByRole('button', { name: 'Connect authenticator', exact: true }).click(); await page.locator('.core-mfa__secret').waitFor();
  const next = totpGenerator((await page.locator('.core-mfa__secret').textContent()).trim()); const first = await next();
  await page.locator('#core-mfa-code').fill(first); await page.getByRole('dialog').getByRole('button', { name: 'Confirm code', exact: true }).click();
  await page.locator('.core-mfa__codes li').first().waitFor(); assert.ok((await page.locator('.core-mfa__codes li').count()) > 0);
  await page.getByRole('button', { name: 'I have saved the codes', exact: true }).click();
  assert.equal((await request('/auth/mfa')).totpEnabled, true);
  await page.getByRole('button', { name: 'New recovery codes', exact: true }).click(); await page.locator('#core-mfa-code').fill(first);
  await page.getByRole('dialog').getByRole('button', { name: 'Confirm code', exact: true }).click(); await page.getByRole('dialog').getByRole('alert').waitFor();
  await page.locator('#core-mfa-code').fill(await next()); await page.getByRole('dialog').getByRole('button', { name: 'Confirm code', exact: true }).click();
  await page.locator('.core-mfa__codes li').first().waitFor(); const savedCodes = await page.locator('.core-mfa__codes li').allTextContents();
  await page.getByRole('button', { name: 'I have saved the codes', exact: true }).click();
  const db = fixture('state', actor.email); assert.equal(Number(db.factors), 1); assert.equal(Number(db.codes), savedCodes.length);
  states.push('real TOTP enrollment, replay rejection, recovery-code rotation and persisted factor/code counts');
  const previousAccess = access;
  await page.getByRole('button', { name: 'Sign out', exact: true }).click(); await page.waitForURL(url => url.pathname === '/admin/login');
  await login(next); await request('/auth/me', { token: previousAccess, status: 401 });
  states.push('fresh browser sign-in requires the real authenticator; prior access token is rejected');
  await stableScreenshot(page, 'screenshots/core-security-real-enabled.png');
  await page.getByRole('button', { name: 'Disable MFA', exact: true }).click(); await page.locator('#core-disable-password').fill(actor.password); await page.locator('#core-mfa-code').fill(await next());
  await page.getByRole('dialog').getByRole('button', { name: 'Disable MFA', exact: true }).click(); await page.getByText('Two-factor authentication disabled.', { exact: true }).waitFor();
  assert.equal((await request('/auth/mfa')).totpEnabled, false); const disabled = fixture('state', actor.email); assert.equal(Number(disabled.factors), 0); assert.equal(Number(disabled.codes), 0);
  await request('/auth/me'); fixture('age-session', actor.email);
  await page.getByRole('button', { name: 'Connect authenticator', exact: true }).click(); await page.getByRole('button', { name: 'Sign out and sign in again', exact: true }).waitFor();
  states.push('disable removes factors/recovery codes; current stamp/refresh stays usable; actual expired recent authentication refused');
  assert.deepEqual(errors, []); writeFileSync('screenshots/core-security-real-results.json', JSON.stringify({ passed: true, realBackend: true, states }, null, 2)); console.log(JSON.stringify({ passed: true, realBackend: true, states }));
} catch (error) { await stableScreenshot(page, 'screenshots/core-security-real-failure.png'); throw error; }
finally { await context.close(); await browser.close(); }
