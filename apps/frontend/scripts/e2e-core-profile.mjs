// Real Core/PG/Redis profile lifecycle; every mutation requires proof from API and frontend proxy.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { request as playwrightRequest } from 'playwright';
import { evaluateCoreMutationGate } from './core-e2e-guard.mjs';
import { launchBrowser, navigateClientSide, selectDropdown, stableScreenshot } from './e2e-support.mjs';
const env = process.env;
if (process.argv.includes('--check-guard')) { const gate = await evaluateCoreMutationGate(env); assert.equal(gate.allowed, false); console.log('Core profile mutations refuse without disposable runtime proof.'); process.exit(0); }
const proof = await playwrightRequest.newContext({ ignoreHTTPSErrors: true }); let gate;
try { gate = await evaluateCoreMutationGate(env, async url => { const response = await proof.get(url); return { status: response.status(), json: () => response.json() }; }); } finally { await proof.dispose(); }
assert.equal(gate.allowed, true, gate.reasons.join('; '));
function fixture(command, email) {
  assert.ok(env.CORE_TEST_CONTAINER);
  try { return JSON.parse(execFileSync('docker', ['exec', env.CORE_TEST_CONTAINER, 'php', 'tests/e2e/core-auth-fixture.php', command, ...(email ? [email] : [])], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })); }
  catch { throw new Error('Disposable fixture failed; private output suppressed.'); }
}
const actor = fixture('seed').accounts.reset;
const browser = await launchBrowser(); const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1100 } });
await context.addInitScript(() => { localStorage.setItem('platform.locale', 'en'); localStorage.setItem('platform.theme', 'dark'); localStorage.setItem('platform.appearance.v1', JSON.stringify({ animations: true })); });
const page = await context.newPage(); const errors = []; const states = []; let access;
page.on('pageerror', error => errors.push(error.message));
page.on('response', async response => { if (/\/api\/auth\/(login|refresh)$/.test(new URL(response.url()).pathname) && response.ok()) { const result = await response.json().catch(() => null); if (result?.data?.accessToken) access = result.data.accessToken; } });
async function api(path, method = 'GET', data) {
  let response; try { response = await context.request.fetch(env.CORE_API_URL + '/api' + path, { method, headers: { Authorization: `Bearer ${access}` }, data, timeout: 10000 }); } catch { throw new Error('Isolated profile API unavailable; private output suppressed.'); }
  assert.equal(response.status(), 200, path + ': expected success'); return (await response.json()).data;
}
async function login() {
  await page.goto(env.APP_URL + '/admin/login?redirect=/admin/profile'); await page.locator('#admin-identifier').fill(actor.email); await page.locator('#admin-password').fill(actor.password); await page.locator('form button[type=submit]').click(); await page.waitForURL(url => url.pathname === '/admin/profile'); await page.locator('#core-profile-email').waitFor();
}
mkdirSync('screenshots', { recursive: true });
try {
  await login(); await selectDropdown(page, 'core-profile-locale', 'ru-RU'); await selectDropdown(page, 'core-profile-timezone', 'Asia/Yekaterinburg'); await page.getByRole('button', { name: 'Save language and time zone', exact: true }).click(); await page.getByText('Changes saved.', { exact: true }).waitFor();
  await page.locator('#core-profile-email').check(); await page.locator('#core-profile-animations').uncheck(); await page.locator('#core-profile-transparency').uncheck(); await page.getByRole('button', { name: 'Save preferences', exact: true }).click();
  await page.waitForFunction(() => document.documentElement.dataset.motion === 'reduced' && document.documentElement.dataset.transparency === 'opaque');
  const persisted = fixture('profile-state', actor.email); assert.deepEqual(persisted.preferences, { locale: 'ru-RU', timezone: 'Asia/Yekaterinburg' }); assert.equal(JSON.parse(persisted.settings.settings).notifications.email, true); assert.equal(persisted.settings.schema_version, 2);
  states.push('preferences and sparse schema-v2 settings saved through UI and verified in PostgreSQL');
  await navigateClientSide(page, '/settings'); await page.locator('#appearance-font-scale').waitFor(); assert.equal(await page.evaluate(() => document.documentElement.dataset.motion), 'full');
  await navigateClientSide(page, '/admin/profile'); await page.locator('#core-profile-email').waitFor(); assert.equal(await page.locator('#core-profile-email').isChecked(), true); assert.equal(await page.evaluate(() => document.documentElement.dataset.motion), 'reduced');
  await navigateClientSide(page, '/admin/profile/consents'); const card = page.locator('.core-consents__grid .app-card').first(); await card.getByRole('button', { name: 'Read document', exact: true }).click();
  await page.locator('#core-consent-accepted').check(); await page.getByRole('button', { name: 'Give consent', exact: true }).click(); await page.getByText('Consent recorded.', { exact: true }).waitFor();
  const grant = fixture('profile-state', actor.email); assert.equal(grant.consents.length, 1); assert.equal(grant.consents[0].document_version, 'test-v1'); assert.equal(grant.consents[0].withdrawn_at, null);
  await card.getByRole('button', { name: 'Withdraw consent', exact: true }).click(); await page.getByRole('dialog').getByRole('button', { name: 'Cancel', exact: true }).click(); assert.equal(fixture('profile-state', actor.email).consents[0].withdrawn_at, null);
  await card.getByRole('button', { name: 'Withdraw consent', exact: true }).click(); await page.getByRole('dialog').getByRole('button', { name: 'Withdraw consent', exact: true }).click(); await page.getByText('Consent withdrawn.', { exact: true }).waitFor(); assert.ok(fixture('profile-state', actor.email).consents[0].withdrawn_at);
  states.push('registered document review/grant/cancel withdrawal/withdraw and durable consent record verified');
  await navigateClientSide(page, '/admin/profile'); await page.locator('#core-profile-email').waitFor(); await page.getByRole('button', { name: 'Restore defaults', exact: true }).click(); await page.getByRole('dialog').getByRole('button', { name: 'Restore defaults', exact: true }).click(); await page.getByText('Default preferences restored.', { exact: true }).waitFor();
  const reset = fixture('profile-state', actor.email); assert.equal(reset.settings, null); assert.equal(reset.preferences.timezone, 'Asia/Yekaterinburg'); assert.equal(await page.evaluate(() => document.documentElement.dataset.motion), 'full');
  await Promise.all([api('/auth/me/preferences', 'PATCH', { locale: 'en-US' }), api('/auth/me/preferences', 'PATCH', { timezone: 'Europe/Moscow' })]);
  const me = await api('/auth/me'); assert.equal(me.locale, 'en-US'); assert.equal(me.timezone, 'Europe/Moscow');
  await page.getByRole('button', { name: 'Sign out', exact: true }).click(); await page.waitForURL(url => url.pathname === '/admin/login'); await login();
  assert.ok((await page.locator('#core-profile-timezone').textContent()).includes('Europe/Moscow')); assert.equal(await page.locator('#core-profile-email').isChecked(), false);
  states.push('null reset removes sparse row; locale/timezone survive; concurrent partial updates and a fresh login restore authoritative values');
  await stableScreenshot(page, 'screenshots/core-profile-real.png'); assert.deepEqual(errors, []);
  writeFileSync('screenshots/core-profile-real-results.json', JSON.stringify({ passed: true, realBackend: true, states }, null, 2)); console.log(JSON.stringify({ passed: true, realBackend: true, states }));
} catch (error) { await stableScreenshot(page, 'screenshots/core-profile-real-failure.png'); throw error; }
finally { await context.close(); await browser.close(); }
