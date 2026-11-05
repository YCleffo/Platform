// Core security UI with explicit HTTP fixtures. This does not prove server authorization or live TOTP.
import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import { launchBrowser, navigateClientSide, stableScreenshot } from './e2e-support.mjs';
const base = process.env.BASE_URL ?? 'http://127.0.0.1:5173';
const browser = await launchBrowser(); const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
await context.addInitScript(() => {
  localStorage.setItem('platform.locale', 'en'); localStorage.setItem('platform.theme', 'dark');
  Object.defineProperty(navigator, 'clipboard', { value: { async writeText(value) { window.__fixtureClipboard = value; } } });
});
const page = await context.newPage(); const errors = []; const states = []; const mutations = [];
page.on('pageerror', e => errors.push(e.message));
const now = '2026-11-03T00:00:00Z';
const session = (id, current) => ({ id, current, clientType: 'web', deviceName: current ? 'Current browser' : 'Another laptop',
  browser: { name: 'Chrome', version: null }, os: { name: 'Windows', version: null }, ipAddress: '192.0.2.20',
  approximateLocation: { countryCode: 'RU', region: null, city: null, approximate: true }, createdAt: now, lastSeenAt: now, expiresAt: '2099-01-01T00:00:00Z' });
let sessions = [session('current', true), session('other', false)]; let enabled = false; let recent = false; let invalidCode = false; let remaining = 0;
await context.route('**/api/**', async route => {
  const request = route.request(); const path = new URL(request.url()).pathname; if (!path.startsWith('/api/')) return route.continue();
  let data = { items: [], pagination: { total: 0, limit: 25, offset: 0 } };
  const respond = (status, body) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
  if (path === '/api/auth/login') data = { status: 'authenticated', accessToken: 'fixture-access', refreshToken: 'fixture-refresh', sessionId: 'current', accessExpiresAt: '2099-01-01T00:00:00Z', sessionExpiresAt: '2099-01-01T00:00:00Z' };
  else if (path === '/api/auth/me') data = { account: { id: 'core-fixture', personId: null }, roles: ['core.superadmin'], permissions: [], locale: 'en', timezone: 'UTC', sessionId: 'current' };
  else if (path === '/api/auth/providers') data = { items: [] };
  else if (path === '/api/auth/mfa') data = { totpEnabled: enabled, verifiedAt: enabled ? now : null, recoveryCodesRemaining: remaining };
  else if (path === '/api/auth/sessions') data = sessions;
  else if (path.startsWith('/api/auth/sessions/') && request.method() !== 'GET') {
    mutations.push({ path, method: request.method() }); sessions = sessions.filter(item => item.current); data = { revokedCount: 1 };
  } else if (path.startsWith('/api/auth/mfa/') && request.method() !== 'GET') {
    if (recent) return respond(403, { status: 'error', error: { code: 'auth.recent_required', message: 'recent authentication required' } });
    if (invalidCode && path !== '/api/auth/mfa/totp/setup') return respond(400, { status: 'error', error: { code: 'auth.mfa.invalid', message: 'invalid code' } });
    mutations.push({ path, method: request.method(), body: request.postDataJSON() });
    if (path.endsWith('/setup')) data = { setupId: 'setup', issuer: 'Platform', accountLabel: 'Synthetic operator', secret: 'JBSWY3DPEHPK3PXP', provisioningUri: 'otpauth://totp/Platform:fixture?secret=JBSWY3DPEHPK3PXP&issuer=Platform', expiresAt: '2099-01-01T00:00:00Z' };
    else if (path.endsWith('/confirm') || path.endsWith('/regenerate')) { enabled = true; remaining = 2; data = { recoveryCodes: ['FIXTURE-ONLY-CODE-ONE', 'FIXTURE-ONLY-CODE-TWO'] }; }
    else if (request.method() === 'DELETE') { enabled = false; remaining = 0; data = { message: 'updated' }; }
  }
  return respond(200, { status: 'success', data });
});
mkdirSync('screenshots', { recursive: true });
async function inspect(label) {
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, label + ' overflow');
  const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze(); assert.deepEqual(axe.violations.map(v => ({ id: v.id, nodes: v.nodes.map(n => ({ target: n.target, reason: n.failureSummary })) })), [], label + ' axe'); states.push(label);
}
try {
  await page.goto(base + '/admin/login'); await page.locator('#admin-identifier').fill('synthetic@example.test'); await page.locator('#admin-password').fill('Synthetic-fixture-password'); await page.locator('form button[type=submit]').click(); await page.waitForURL(url => url.pathname === '/admin');
  await navigateClientSide(page, '/admin/security/sessions'); await page.getByText('Another laptop', { exact: true }).waitFor();
  assert.equal(await page.locator('.core-sessions__item').count(), 2); assert.equal(await page.locator('.core-sessions__item button').count(), 1);
  await inspect('sessions desktop; current device cannot be revoked');
  await page.locator('.core-sessions__item button').click(); await page.getByRole('dialog').getByRole('button', { name: 'Cancel', exact: true }).click(); assert.equal(mutations.length, 0);
  await page.locator('.core-sessions__item button').click(); await page.getByRole('dialog').getByRole('button', { name: 'Revoke', exact: true }).click(); await page.getByText('Session revoked.', { exact: true }).waitFor(); assert.equal(await page.locator('.core-sessions__item').count(), 1);
  states.push('session confirmation cancel and revoke use exact Core endpoint');
  await stableScreenshot(page, 'screenshots/core-security-sessions-desktop.png');
  sessions.push(session('other-two', false));
  await navigateClientSide(page, '/admin/security'); await page.locator('#admin-current-password').waitFor();
  await navigateClientSide(page, '/admin/security/sessions'); await page.getByText('Another laptop', { exact: true }).waitFor();
  for (const width of [320, 393, 768]) { await page.setViewportSize({ width, height: 1000 }); await inspect('sessions width ' + width); }
  await page.getByRole('button', { name: 'Revoke other sessions', exact: true }).click(); await page.getByRole('dialog').getByRole('button', { name: 'Revoke', exact: true }).click();
  await page.getByText('Other sessions revoked.', { exact: true }).waitFor();
  // The mutation acknowledgement precedes the authoritative list reload.
  await page.waitForFunction(() => document.querySelectorAll('.core-sessions__item').length === 1);
  assert.equal(await page.locator('.core-sessions__item').count(), 1);
  await page.setViewportSize({ width: 1440, height: 1000 });
  await navigateClientSide(page, '/admin/security/mfa'); await page.getByRole('button', { name: 'Connect authenticator', exact: true }).click();
  await page.locator('.core-mfa__secret').waitFor(); await inspect('setup: local QR, manual key and expiry');
  await page.setViewportSize({ width: 320, height: 1000 }); await inspect('setup dialog width 320'); await page.setViewportSize({ width: 1440, height: 1000 });
  invalidCode = true; await page.locator('#core-mfa-code').fill('123456'); await page.getByRole('dialog').getByRole('button', { name: 'Confirm code', exact: true }).click(); await page.getByRole('dialog').getByRole('alert').waitFor(); assert.equal(await page.locator('.core-mfa__secret').count(), 1);
  invalidCode = false; await page.locator('#core-mfa-code').fill('654321'); await page.getByRole('dialog').getByRole('button', { name: 'Confirm code', exact: true }).click(); await page.getByText('FIXTURE-ONLY-CODE-ONE', { exact: true }).waitFor();
  await page.keyboard.press('Escape'); assert.equal(await page.getByRole('dialog').count(), 1); await inspect('recovery codes require acknowledgement');
  await page.getByRole('button', { name: 'Copy codes', exact: true }).click(); await page.getByRole('button', { name: 'Copied', exact: true }).waitFor();
  assert.equal(await page.evaluate(() => window.__fixtureClipboard), 'FIXTURE-ONLY-CODE-ONE\nFIXTURE-ONLY-CODE-TWO');
  await page.getByRole('button', { name: 'I have saved the codes', exact: true }).click(); await page.getByText('FIXTURE-ONLY-CODE-ONE', { exact: true }).waitFor({ state: 'hidden' }); assert.equal(await page.locator('.core-mfa__secret').count(), 0);
  for (const width of [320, 393, 768, 1440]) { await page.setViewportSize({ width, height: 1000 }); await inspect('MFA enabled width ' + width); }
  await page.setViewportSize({ width: 393, height: 1000 }); await stableScreenshot(page, 'screenshots/core-security-mfa-mobile.png');
  await page.setViewportSize({ width: 1440, height: 1000 }); await page.getByRole('button', { name: 'Light theme', exact: true }).click();
  await page.locator('.admin__locale').click(); await page.emulateMedia({ reducedMotion: 'reduce' });
  for (const width of [320, 393, 1440]) { await page.setViewportSize({ width, height: 1000 }); await inspect('MFA RU light reduced motion width ' + width); }
  await page.setViewportSize({ width: 320, height: 1000 }); await page.evaluate(() => document.documentElement.style.setProperty('--ui-font-scale', '1.4'));
  await inspect('MFA RU light 140% text width 320');
  await navigateClientSide(page, '/admin/security/sessions'); await page.locator('.core-sessions__item').waitFor(); await inspect('sessions RU light 140% text width 320');
  await navigateClientSide(page, '/admin/security/mfa'); await page.getByRole('button', { name: 'Новые коды восстановления', exact: true }).waitFor();
  await page.evaluate(() => document.documentElement.style.setProperty('--ui-font-scale', '1')); await page.setViewportSize({ width: 1440, height: 1000 });
  await stableScreenshot(page, 'screenshots/core-security-mfa-desktop-ru.png');
  await page.locator('.admin__locale').click(); await page.getByRole('button', { name: 'Dark theme', exact: true }).click();
  await page.getByRole('button', { name: 'New recovery codes', exact: true }).click(); await page.locator('#core-mfa-code').fill('123456'); await page.getByRole('dialog').getByRole('button', { name: 'Confirm code', exact: true }).click(); await page.getByText('FIXTURE-ONLY-CODE-ONE', { exact: true }).waitFor(); await page.getByRole('button', { name: 'I have saved the codes', exact: true }).click();
  await page.getByRole('button', { name: 'Disable MFA', exact: true }).click(); await page.locator('#core-mfa-code').fill('123456'); await page.getByRole('dialog').getByRole('button', { name: 'Disable MFA', exact: true }).click(); await page.getByText('Two-factor authentication disabled.', { exact: true }).waitFor();
  assert.deepEqual(mutations.find(item => item.method === 'DELETE' && item.path === '/api/auth/mfa/totp')?.body, { password: null, code: '123456' });
  recent = true; await page.getByRole('button', { name: 'Connect authenticator', exact: true }).click(); await page.getByRole('button', { name: 'Sign out and sign in again', exact: true }).waitFor(); await inspect('expired recent authentication has a safe explicit sign-in action');
  const storage = await page.evaluate(() => JSON.stringify({ local: { ...localStorage }, session: { ...sessionStorage } }));
  assert.ok(!storage.includes('FIXTURE-ONLY') && !storage.includes('JBSWY3DP') && !storage.includes('654321'));
  const mutationCount = mutations.length; await page.getByRole('button', { name: 'Sign out and sign in again', exact: true }).click();
  await page.waitForURL(url => url.pathname === '/admin/login' && url.searchParams.get('redirect') === '/admin/security/mfa'); assert.equal(mutations.length, mutationCount);
  assert.deepEqual(errors, []); writeFileSync('screenshots/core-security-results.json', JSON.stringify({ fixtureOnly: true, passed: true, states }, null, 2)); console.log(JSON.stringify({ fixtureOnly: true, passed: true, states }));
} catch (error) { await stableScreenshot(page, 'screenshots/core-security-failure.png'); throw error; }
finally { await context.close(); await browser.close(); }
