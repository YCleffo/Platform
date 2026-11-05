// UI contract/UX tests with explicit HTTP fixtures; no working account is changed.
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import { launchBrowser, navigateClientSide, selectDropdown, stableScreenshot } from './e2e-support.mjs';
const base = process.env.BASE_URL ?? 'http://127.0.0.1:5173'; const browser = await launchBrowser();
const context = await browser.newContext({ viewport: { width: 1440, height: 1100 } });
await context.addInitScript(() => { localStorage.setItem('platform.locale', 'en'); localStorage.setItem('platform.theme', 'dark'); });
const page = await context.newPage(); const errors = []; const states = []; const patches = [];
page.on('pageerror', error => errors.push(error.message));
const defaults = { interface: { animations: true, transparency: true }, notifications: { browser: true, sound: true, email: false }, privacy: { show_online_status: false } };
let effective = structuredClone(defaults); const preferences = { locale: null, timezone: null }; let failSettings = false; let failWithdraw = false;
const sha = text => createHash('sha256').update(text).digest('hex');
const definition = (code, withdrawable = true) => ({ code, purpose_code: code, current_document_version: 'v1', current_document_sha256: sha('Editable document version 1'), withdrawable, representative_allowed: false, document: { title: code === 'updates' ? 'Optional product updates' : 'Required document', text: 'Editable document version 1' } });
let definitions = [definition('updates'), definition('required', false)];
const consent = code => { const d = definitions.find(item => item.code === code); return { id: '01900000-0000-7000-8000-000000000001', consent_code: code, purpose_code: code, document_version: d.current_document_version, document_sha256: d.current_document_sha256, locale: 'en-US', source: 'self_api', granted_at: '2026-11-03T00:00:00Z', withdrawn_at: null, current: true }; };
let consents = [consent('required')];
await context.route('**/api/**', async route => {
  const request = route.request(); const path = new URL(request.url()).pathname; if (!path.startsWith('/api/')) return route.continue();
  const respond = (status, body) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
  let data = { items: [], pagination: { total: 0, limit: 25, offset: 0 } };
  if (path === '/api/auth/login') data = { status: 'authenticated', accessToken: 'fixture', refreshToken: 'fixture-refresh', sessionId: 'fixture-session', accessExpiresAt: '2099-01-01T00:00:00Z', sessionExpiresAt: '2099-01-01T00:00:00Z' };
  else if (path === '/api/auth/logout') return route.fulfill({ status: 204 });
  else if (path === '/api/auth/providers') data = { items: [] };
  else if (path === '/api/auth/me') data = { account: { id: 'fixture-account', personId: 'fixture-person' }, roles: [], permissions: [], ...preferences, sessionId: 'fixture-session' };
  else if (path === '/api/auth/me/preferences') { const body = request.postDataJSON(); patches.push({ path, body }); Object.assign(preferences, body); data = preferences; }
  else if (path === '/api/auth/me/settings') {
    if (request.method() === 'PATCH') {
      if (failSettings) return respond(503, { status: 'error', error: { code: 'http.unavailable' } });
      const body = request.postDataJSON(); patches.push({ path, body });
      for (const group of Object.keys(body)) for (const [key, value] of Object.entries(body[group])) effective[group][key] = value === null ? defaults[group][key] : value;
    }
    data = { schema_version: 2, settings: effective };
  } else if (path === '/api/auth/me/consents') data = { definitions, consents };
  else if (path.startsWith('/api/auth/me/consents/')) {
    const code = decodeURIComponent(path.split('/').at(-1)); const d = definitions.find(item => item.code === code);
    if (request.method() === 'POST') {
      const body = request.postDataJSON(); if (body.document_version !== d.current_document_version || body.document_sha256 !== d.current_document_sha256) return respond(409, { status: 'error', error: { code: 'http.conflict' } });
      data = consent(code); consents = consents.filter(item => item.consent_code !== code).concat(data);
    } else {
      if (failWithdraw) return respond(503, { status: 'error', error: { code: 'http.unavailable' } });
      assert.equal(d.withdrawable, true); const row = consents.find(item => item.consent_code === code); row.current = false; row.withdrawn_at = '2026-11-03T00:05:00Z'; data = null;
    }
  }
  return respond(200, { status: 'success', data });
});
mkdirSync('screenshots', { recursive: true });
async function inspect(label) {
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, label + ' overflow');
  const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
  assert.deepEqual(axe.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) })), [], label + ' axe'); states.push(label);
}
async function login() {
  await page.goto(base + '/admin/login?redirect=/admin/profile'); await page.locator('#admin-identifier').fill('scaffold@example.test'); await page.locator('#admin-password').fill('Synthetic-scaffold-password'); await page.locator('form button[type=submit]').click(); await page.waitForURL(url => url.pathname === '/admin/profile'); await page.locator('#core-profile-email').waitFor();
}
try {
  await login(); await inspect('preferences loaded without platform administration permissions');
  const browserAppearance = await page.evaluate(() => localStorage.getItem('platform.appearance.v1'));
  await selectDropdown(page, 'core-profile-locale', 'ru-RU'); await selectDropdown(page, 'core-profile-timezone', 'Asia/Yekaterinburg');
  await page.getByRole('button', { name: 'Save language and time zone', exact: true }).click(); await page.getByText('Changes saved.', { exact: true }).waitFor();
  assert.deepEqual(preferences, { locale: 'ru-RU', timezone: 'Asia/Yekaterinburg' });
  await page.locator('#core-profile-email').check(); await page.getByRole('button', { name: 'Save preferences', exact: true }).click();
  await page.locator('.core-preferences__save').getByText('Changes saved.', { exact: true }).waitFor();
  assert.deepEqual(patches.filter(item => item.path.endsWith('/settings')).at(-1).body, { notifications: { email: true } });
  failSettings = true; await page.locator('#core-profile-sound').uncheck(); await page.getByRole('button', { name: 'Save preferences', exact: true }).click();
  await page.locator('.core-preferences__save [role=alert]').waitFor(); assert.equal(await page.locator('#core-profile-sound').isChecked(), false); assert.equal(effective.notifications.sound, true);
  failSettings = false; await page.getByRole('button', { name: 'Save preferences', exact: true }).click(); await page.locator('.core-preferences__save [role=alert]').waitFor({ state: 'hidden' });
  await page.locator('#core-profile-animations').uncheck(); await page.locator('#core-profile-transparency').uncheck(); await page.getByRole('button', { name: 'Save preferences', exact: true }).click();
  await page.waitForFunction(() => document.documentElement.dataset.motion === 'reduced' && document.documentElement.dataset.transparency === 'opaque');
  assert.equal(await page.evaluate(() => localStorage.getItem('platform.appearance.v1')), browserAppearance);
  states.push('sparse saves; failed save preserves edits; account motion/transparency apply without writing browser preferences');
  await page.getByRole('button', { name: 'Restore defaults', exact: true }).click(); await page.getByRole('dialog').getByRole('button', { name: 'Restore defaults', exact: true }).click(); await page.getByText('Default preferences restored.', { exact: true }).waitFor();
  assert.deepEqual(effective, defaults); assert.equal(preferences.timezone, 'Asia/Yekaterinburg');
  for (const width of [320, 393, 768, 1440]) { await page.setViewportSize({ width, height: 1100 }); await inspect('preferences EN dark width ' + width); }
  await stableScreenshot(page, 'screenshots/core-profile-preferences-desktop.png');
  await navigateClientSide(page, '/admin/profile/consents'); await page.getByText('Optional product updates', { exact: true }).waitFor();
  const card = page.locator('.core-consents__grid .app-card').first(); await card.getByRole('button', { name: 'Read document', exact: true }).click();
  assert.equal(await page.getByRole('button', { name: 'Give consent', exact: true }).isDisabled(), true); await page.locator('#core-consent-accepted').check();
  definitions[0] = { ...definitions[0], current_document_version: 'v2', current_document_sha256: sha('Editable document version 2'), document: { title: 'Optional product updates', text: 'Editable document version 2' } };
  await page.getByRole('button', { name: 'Give consent', exact: true }).click(); await page.getByText('The document changed. Read the current version and confirm your choice again.', { exact: true }).waitFor(); assert.equal(consents.length, 1);
  await card.getByRole('button', { name: 'Read document', exact: true }).click(); await page.locator('#core-consent-accepted').check(); await page.getByRole('button', { name: 'Give consent', exact: true }).click(); await page.getByText('Consent recorded.', { exact: true }).waitFor();
  assert.equal(consents.find(item => item.consent_code === 'updates').document_version, 'v2');
  assert.equal(await page.locator('.core-consents__grid .app-card').nth(1).getByRole('button', { name: 'Withdraw consent', exact: true }).count(), 0);
  await card.getByRole('button', { name: 'Withdraw consent', exact: true }).click(); await page.getByRole('dialog').getByRole('button', { name: 'Cancel', exact: true }).click(); assert.equal(consents.find(item => item.consent_code === 'updates').current, true);
  await card.getByRole('button', { name: 'Withdraw consent', exact: true }).click(); failWithdraw = true; await page.getByRole('dialog').getByRole('button', { name: 'Withdraw consent', exact: true }).click(); await page.getByRole('dialog').getByRole('alert').waitFor();
  failWithdraw = false; await page.getByRole('dialog').getByRole('button', { name: 'Withdraw consent', exact: true }).click(); await page.getByText('Consent withdrawn.', { exact: true }).waitFor();
  await inspect('consent review/version conflict/review again/grant/withdraw retry and protected definition');
  await page.setViewportSize({ width: 1440, height: 1100 }); await page.getByRole('button', { name: 'Light theme', exact: true }).click(); await page.locator('.admin__locale').click();
  await page.getByText('Согласие отозвано.', { exact: true }).waitFor();
  assert.equal(await page.getByText('Consent withdrawn.', { exact: true }).count(), 0);
  await page.emulateMedia({ reducedMotion: 'reduce' }); await page.evaluate(() => document.documentElement.style.setProperty('--ui-font-scale', '1.4'));
  for (const width of [320, 393, 1440]) { await page.setViewportSize({ width, height: 1100 }); await inspect('consents RU light 140% reduced motion width ' + width); }
  await stableScreenshot(page, 'screenshots/core-profile-consents-desktop-ru.png');
  definitions = []; await navigateClientSide(page, '/admin/profile'); await page.locator('#core-profile-email').waitFor(); await navigateClientSide(page, '/admin/profile/consents');
  await page.getByText('Новости и обновления продукта', { exact: true }).waitFor(); await page.setViewportSize({ width: 393, height: 1100 });
  await page.getByRole('button', { name: 'Прочитать документ', exact: true }).first().click(); await inspect('editable scaffold templates, no fake published consent'); await stableScreenshot(page, 'screenshots/core-profile-template-mobile.png');
  assert.equal(await page.getByRole('button', { name: 'Дать согласие', exact: true }).count(), 0); await page.getByRole('button', { name: 'Закрыть документ', exact: true }).click();
  await page.getByRole('button', { name: 'Выйти', exact: true }).click(); await page.waitForURL(url => url.pathname === '/admin/login'); assert.equal(await page.locator('.core-preferences').count(), 0);
  assert.deepEqual(errors, []); writeFileSync('screenshots/core-profile-ui-results.json', JSON.stringify({ passed: true, fixtureOnly: true, states }, null, 2)); console.log(JSON.stringify({ passed: true, fixtureOnly: true, states }));
} catch (error) { await stableScreenshot(page, 'screenshots/core-profile-failure.png'); throw error; }
finally { await context.close(); await browser.close(); }
