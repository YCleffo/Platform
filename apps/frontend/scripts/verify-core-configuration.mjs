// Explicit UI fixtures; real Core/PG integration lives in e2e-core-configuration.mjs.
import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import {
  launchBrowser,
  navigateClientSide,
  selectDropdown,
  stableScreenshot,
} from './e2e-support.mjs';
const base = process.env.BASE_URL ?? 'http://localhost:5173';
const browser = await launchBrowser();
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
await context.addInitScript(() => {
  localStorage.setItem('platform.locale', 'en');
  localStorage.setItem('platform.theme', 'dark');
});
const page = await context.newPage();
const errors = [];
const missing = [];
const states = [];
page.on('pageerror', (e) => errors.push(e.message));
page.on('console', (m) => {
  if (m.text().includes('Not found') && m.text().includes('key')) missing.push(m.text());
});
const date = '2026-11-04T00:00:00Z';
const module = {
  id: 'module-1',
  code: 'education',
  name: 'Education',
  description: 'Learning module',
  version: '1.2.3',
  settingsSchemaVersion: 2,
  enabled: false,
  installedAt: date,
  enabledAt: null,
  disabledAt: date,
};
const settings = [];
const moduleSettings = [];
const flags = [];
let serial = 0;
let uncertain = false;
let readonly = false;
let writes = 0;
await context.route('**/api/**', async (route) => {
  const req = route.request();
  const p = new URL(req.url()).pathname;
  if (!p.startsWith('/api/')) return route.continue();
  const method = req.method();
  const body = () => req.postDataJSON();
  const send = (code, data) =>
    route.fulfill({ status: code, contentType: 'application/json', body: JSON.stringify(data) });
  const ok = (data) => send(200, { status: 'success', data });
  if (p === '/api/auth/login')
    return ok({
      status: 'authenticated',
      accessToken: 'fixture',
      refreshToken: 'fixture',
      sessionId: 'fixture',
      accessExpiresAt: '2099-01-01',
      sessionExpiresAt: '2099-01-01',
    });
  if (p === '/api/auth/me')
    return ok({
      account: { id: 'account', personId: null },
      roles: readonly ? [] : ['core.superadmin'],
      permissions: readonly
        ? ['core.settings.read', 'core.modules.read', 'core.feature_flags.read']
        : [],
      locale: 'en',
      timezone: 'UTC',
      sessionId: 'fixture',
    });
  if (p === '/api/auth/providers') return ok({ items: [] });
  if (p === '/api/auth/logout') return route.fulfill({ status: 204 });
  if (p === '/api/auth/me/settings')
    return ok({
      schema_version: 2,
      settings: {
        interface: { animations: true, transparency: true },
        notifications: { browser: false, sound: false, email: false },
        privacy: { show_online_status: false },
      },
    });
  if (p === '/api/public/config') {
    assert.equal(req.headers().authorization, undefined);
    return ok({
      settings: Object.fromEntries(settings.filter((s) => s.isPublic).map((s) => [s.key, s.value])),
    });
  }
  if (p === '/api/core/modules') return ok([module]);
  if (p === '/api/core/modules/education') return ok(module);
  if (p.match(/\/education\/(enable|disable)$/)) {
    writes++;
    module.enabled = p.endsWith('/enable');
    return ok({ ...module, changed: true });
  }
  const flag = p.match(/^\/api\/core\/feature-flags(?:\/([^/]+))?$/);
  const setting = p.match(
    /^\/api\/core\/(settings\/system|modules\/education\/settings)(?:\/([^/]+))?$/,
  );
  if (flag || setting) {
    const list = flag ? flags : setting[1] === 'settings/system' ? settings : moduleSettings;
    const key = decodeURIComponent((flag?.[1] ?? setting?.[2]) || '');
    const field = flag ? 'code' : 'key';
    const current = list.find((row) => row[field] === key);
    if (method === 'GET')
      return key
        ? current
          ? ok(current)
          : send(404, { error: { code: 'http.not_found' } })
        : ok(list);
    if (readonly) return send(403, { error: { code: 'http.forbidden' } });
    writes++;
    if (method === 'DELETE') {
      if (current) list.splice(list.indexOf(current), 1);
      return ok({ deleted: true, [field]: key });
    }
    const row = {
      id: current?.id ?? 'entry-' + serial++,
      [field]: key,
      ...body(),
      createdAt: date,
      updatedAt: date,
      ...(setting?.[1].startsWith('modules/') ? { moduleCode: 'education' } : {}),
    };
    if (current) Object.assign(current, row);
    else list.push(row);
    if (uncertain) {
      uncertain = false;
      return send(503, { error: { code: 'unavailable' } });
    }
    return ok(row);
  }
  return ok({ items: [], pagination: { total: 0, limit: 25, offset: 0 } });
});
async function inspect(name) {
  assert.equal(
    await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1),
    false,
    name + ' overflow',
  );
  const a = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze();
  assert.deepEqual(
    a.violations.map((v) => ({ id: v.id, targets: v.nodes.map((n) => n.target) })),
    [],
    name,
  );
  states.push(name);
}
const button = (name, dialog = false) =>
  (dialog ? page.getByRole('dialog') : page).getByRole('button', { name, exact: true });
async function click(name, dialog = false) {
  await button(name, dialog).click();
}
async function save() {
  await click('Save changes', true);
  await page.getByRole('dialog').waitFor({ state: 'hidden' });
}
async function textValue(value) {
  await page.locator('input[id^="value-text-"]').first().fill(value);
}
async function setting(key, value, publish = false) {
  await click('Add setting');
  await page.locator('#config-key').fill(key);
  await textValue(value);
  if (publish) {
    await page.locator('#config-public').check();
    assert.equal(await button('Save changes', true).isDisabled(), true);
    await page.locator('#config-ack').check();
  }
  await save();
}
async function login() {
  await page.goto(base + '/admin/login');
  await page.locator('#admin-identifier').fill('fixture@example.test');
  await page.locator('#admin-password').fill('fixture-only-password');
  await page.locator('form button[type=submit]').click();
  await page.waitForURL((u) => u.pathname === '/admin');
}
mkdirSync('screenshots', { recursive: true });
try {
  await login();
  await page.locator('.admin-dashboard__card[href="/admin/privacy?tab=holds"]').click();
  await page.waitForURL((url) => url.pathname === '/admin/privacy' && url.searchParams.get('tab') === 'holds');
  await page.locator('.admin-tabs__nav [aria-current="page"]').filter({ hasText: 'Legal holds' }).waitFor();
  await page.goBack();
  await page.waitForURL((url) => url.pathname === '/admin');
  await page.locator('.admin-dashboard__card').filter({ hasText: 'Feature flags' }).click();
  await page.waitForURL((url) => url.pathname === '/admin/settings');
  assert.equal(new URL(page.url()).searchParams.get('tab'), 'flags', 'dashboard shortcut opens the feature flags tab');
  await page.getByRole('button', { name: 'Feature flags', exact: true }).and(page.locator('[aria-current="page"]')).waitFor();
  await click('System settings');
  await page.getByRole('button', { name: 'System settings', exact: true }).and(page.locator('[aria-current="page"]')).waitFor();
  await page.goBack();
  await page.getByRole('button', { name: 'Feature flags', exact: true }).and(page.locator('[aria-current="page"]')).waitFor();
  await page.goForward();
  await page.getByRole('button', { name: 'System settings', exact: true }).and(page.locator('[aria-current="page"]')).waitFor();
  states.push('Dashboard feature flags shortcut, active tab and browser history');
  await navigateClientSide(page, '/admin/settings');
  await setting('ui.title', 'Public title', true);
  await setting('ui.internal', 'Internal marker');
  await inspect('typed system setting save and explicit public acknowledgement');
  await click('Public configuration');
  await page.getByRole('heading', { name: 'ui.title', exact: true }).waitFor();
  assert.equal(await page.getByText('Internal marker', { exact: true }).count(), 0);
  await inspect('anonymous public preview contains only published values');
  await stableScreenshot(page, 'screenshots/core-config-public.png');
  await navigateClientSide(page, '/admin/modules');
  await click('Open module');
  await page.getByRole('heading', { name: 'Education', exact: true }).waitFor();
  await click('Enable module');
  await page.locator('#config-module-ack').check();
  await click('Confirm', true);
  await page.getByRole('dialog').waitFor({ state: 'hidden' });
  assert.equal(module.enabled, true);
  await setting('ui.limit', '25');
  await page
    .locator('.config-card')
    .filter({ has: page.getByRole('heading', { name: 'ui.limit', exact: true }) })
    .getByRole('button', { name: 'Edit', exact: true })
    .click();
  await page.getByRole('dialog', { name: 'Edit setting', exact: true }).waitFor();
  const type = await page.locator('button[id^="value-type-"]').getAttribute('id');
  await selectDropdown(page, type, 'number');
  await textValue('25');
  await save();
  assert.equal(moduleSettings[0].value, 25);
  assert.equal('isPublic' in moduleSettings[0], false);
  await inspect('module detail, confirmed lifecycle and typed module setting lookup/save');
  await stableScreenshot(page, 'screenshots/core-config-module.png');
  await navigateClientSide(page, '/admin/settings');
  await click('Feature flags');
  await click('Add feature flag');
  await page.locator('#config-flag-code').fill('ui.feature');
  await selectDropdown(page, 'config-flag-module', 'education');
  await page.locator('#config-flag-enabled').check();
  await page.locator('#config-flag-rollout').focus();
  await page.keyboard.press('End');
  await click('Add item', true);
  await page.locator('input[id^="value-key-"]').fill('label');
  await textValue('New experience');
  await page.locator('#config-flag-ack').check();
  await save();
  assert.equal(flags[0].moduleCode, 'education');
  assert.equal(flags[0].rolloutPercent, 100);
  assert.deepEqual(flags[0].config, { label: 'New experience' });
  await inspect(
    'feature form uses related module, switch, keyboard range and structured properties',
  );
  await click('Edit');
  await inspect('feature edit retains current values');
  await click('Cancel', true);
  for (const width of [320, 393, 768, 1440]) {
    await page.setViewportSize({ width, height: 1000 });
    await inspect('feature flags EN dark ' + width);
  }
  await stableScreenshot(page, 'screenshots/core-config-flags.png');
  await click('System settings');
  await page.getByRole('heading', { name: 'ui.title', exact: true }).waitFor();
  uncertain = true;
  await page
    .locator('.config-card')
    .filter({ has: page.getByRole('heading', { name: 'ui.title', exact: true }) })
    .getByRole('button', { name: 'Edit', exact: true })
    .click();
  await page.getByRole('dialog', { name: 'Edit setting', exact: true }).waitFor();
  await textValue('Changed but response lost');
  await page.locator('#config-ack').check();
  const count = writes;
  await click('Save changes', true);
  await page.getByRole('dialog').getByRole('alert').first().waitFor();
  await click('Cancel', true);
  assert.equal(await button('Add setting').isDisabled(), true);
  assert.equal(writes, count + 1);
  await click('Refresh');
  await page.waitForFunction(() =>
    [...document.querySelectorAll('button')].some(
      (b) => b.textContent.trim() === 'Add setting' && !b.disabled,
    ),
  );
  assert.equal(await button('Add setting').isDisabled(), false);
  await inspect('unknown write is not repeated and requires authoritative list review');
  await page
    .locator('.config-card')
    .filter({ has: page.getByRole('heading', { name: 'ui.internal', exact: true }) })
    .getByRole('button', { name: 'Delete', exact: true })
    .click();
  assert.equal(await button('Delete', true).isDisabled(), true);
  await page.locator('#config-ack').check();
  await click('Delete', true);
  await page.getByRole('dialog').waitFor({ state: 'hidden' });
  assert.equal(
    settings.some((s) => s.key === 'ui.internal'),
    false,
  );
  await inspect('delete requires acknowledgement and uses selected key');
  await page.getByRole('button', { name: 'Light theme', exact: true }).click();
  await page.locator('.admin__locale').click();
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.evaluate(() => document.documentElement.style.setProperty('--ui-font-scale', '1.4'));
  for (const width of [320, 393, 768, 1440]) {
    await page.setViewportSize({ width, height: 1000 });
    await inspect('settings RU light 140% ' + width);
  }
  await click('Добавить настройку');
  await page.setViewportSize({ width: 393, height: 1000 });
  await inspect('setting dialog RU mobile 140%');
  await stableScreenshot(page, 'screenshots/core-config-setting-mobile.png');
  await click('Отмена', true);
  await page.getByRole('button', { name: 'Выйти', exact: true }).click();
  await page.waitForURL((u) => u.pathname === '/admin/login');
  readonly = true;
  await page.evaluate(() => localStorage.setItem('platform.locale', 'en'));
  await login();
  await page.locator('.admin-dashboard__card').filter({ hasText: 'Feature flags' }).waitFor();
  assert.equal(await page.locator('.admin-dashboard__card[href="/admin/organizations"]').count(), 0);
  await navigateClientSide(page, '/admin/settings');
  await page.getByRole('heading', { name: 'ui.title', exact: true }).waitFor();
  assert.equal(await button('Add setting').count(), 0);
  assert.equal(await button('Delete').count(), 0);
  await click('Feature flags');
  assert.equal(await button('Add feature flag').count(), 0);
  await inspect('read-only configuration has no mutation controls');
  assert.deepEqual(errors, []);
  assert.deepEqual(missing, []);
  const result = { passed: true, fixtureOnly: true, states };
  writeFileSync('screenshots/core-config-ui-results.json', JSON.stringify(result, null, 2));
  console.log(JSON.stringify(result));
} catch (error) {
  await page.screenshot({ path: 'screenshots/core-config-failure.png' }).catch(() => {});
  throw error;
} finally {
  await browser.close();
}
