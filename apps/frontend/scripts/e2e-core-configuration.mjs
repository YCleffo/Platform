// Real Core/PG/Redis + browser. Mutations require both isolated runtime proofs.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { request } from 'playwright';
import { evaluateCoreMutationGate } from './core-e2e-guard.mjs';
import { captureAuthenticated, measureAuthenticatedNavigation, observeAuthenticated } from './frontend09-authenticated-evidence.mjs';
import {
  launchBrowser,
  navigateClientSide,
  selectDropdown,
  stableScreenshot,
} from './e2e-support.mjs';
const env = process.env;
if (process.argv.includes('--check-guard')) {
  assert.equal((await evaluateCoreMutationGate(env)).allowed, false);
  console.log('Configuration mutations refuse without disposable runtime proof.');
  process.exit(0);
}
const probe = await request.newContext({ ignoreHTTPSErrors: true });
try {
  const gate = await evaluateCoreMutationGate(env, async (url) => {
    const r = await probe.get(url);
    return { status: r.status(), json: () => r.json() };
  });
  assert.equal(gate.allowed, true, gate.reasons.join('; '));
} finally {
  await probe.dispose();
}
function fixture(command, ...args) {
  assert.ok(env.CORE_TEST_CONTAINER);
  try {
    return JSON.parse(
      execFileSync(
        'docker',
        [
          'exec',
          env.CORE_TEST_CONTAINER,
          'php',
          'tests/e2e/core-auth-fixture.php',
          command,
          ...args,
        ],
        { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
      ),
    );
  } catch {
    throw new Error('Isolated configuration fixture failed; private output suppressed.');
  }
}
const actors = fixture('seed').accounts;
const actor = actors.operator;
const { moduleCode } = fixture('configuration-prepare', actor.email);
const state = () => fixture('configuration-state', actor.email);
const browser = await launchBrowser();
const context = await browser.newContext({
  ignoreHTTPSErrors: true,
  viewport: { width: 1440, height: 1000 },
});
await context.addInitScript(() => {
  if (localStorage.getItem('platform.locale') === null) localStorage.setItem('platform.locale', 'en');
  if (localStorage.getItem('platform.theme') === null) localStorage.setItem('platform.theme', 'dark');
});
const page = await context.newPage();
const errors = [];
const states = [];
const navigation = [];
let access;
page.on('pageerror', (e) => errors.push(e.message));
const button = (name, dialog = false) =>
  (dialog ? page.getByRole('dialog') : page).getByRole('button', { name, exact: true });
async function loginOperator(recoveryCode) {
  await page.locator('#admin-identifier').fill(actor.email);
  await page.locator('#admin-password').fill(actor.password);
  await page.locator('form button[type=submit]').click();
  await page.locator('#admin-totp').waitFor();
  await button('Recovery code').click();
  await page.locator('#admin-recovery').fill(recoveryCode);
  const verified = page.waitForResponse((response) => new URL(response.url()).pathname === '/api/auth/mfa/verify');
  await page.locator('form button[type=submit]').click();
  const response = await verified;
  assert.equal(response.status(), 200, 'Core operator MFA login failed');
  access = (await response.json()).data.accessToken;
}
async function assertLoginRedirect(expected) {
  await page.waitForURL((url) => url.pathname === '/admin/login' && url.searchParams.get('redirect') === expected);
}
async function save() {
  await button('Save changes', true).click();
  await page.getByRole('dialog').waitFor({ state: 'hidden' });
}
async function setting(key, value, { publish = false, number = false } = {}) {
  await button('Add setting').click();
  await page.locator('#config-key').fill(key);
  if (number)
    await selectDropdown(
      page,
      await page.locator('button[id^="value-type-"]').getAttribute('id'),
      'number',
    );
  await page.locator('input[id^="value-text-"]').fill(value);
  if (publish) {
    await page.locator('#config-public').check();
    await page.locator('#config-ack').check();
  }
  await save();
}
async function api(path, { method = 'GET', data, token = access, status = 200 } = {}) {
  const r = await context.request.fetch(env.CORE_API_URL + '/api' + path, {
    method,
    data,
    headers: token ? { Authorization: `Bearer ${token}` } : {},
    timeout: 15000,
  });
  assert.equal(r.status(), status, path);
  return r;
}

async function auditWorkspace() {
  await observeAuthenticated(page);
  const sidebar = page.locator('.admin__sidebar nav');
  const sidebarLink = (path) => sidebar.locator(`a[href="${path}"]`);
  const heading = page.locator('.admin__topbar').getByRole('heading', { level: 1 });
  const sections = ['/admin', '/admin/organizations', '/admin/persons', '/admin/accounts', '/admin/rbac', '/admin/modules', '/admin/settings', '/admin/profile', '/admin/security', '/admin/privacy', '/admin/audit'];
  const language = page.locator('.admin__topbar-actions button').filter({ has: page.locator('.admin__locale') });
  const theme = page.locator('.admin__topbar-actions button').filter({ has: page.locator('svg') }).first();
  async function check(path, link) {
    await page.waitForURL((url) => url.pathname === path);
    await page.getByRole('main').waitFor();
    await link.and(page.locator('[aria-current="page"]')).waitFor();
    assert.ok((await heading.innerText()).trim(), `Missing heading for ${path}`);
  }
  async function setLanguage(value) {
    await language.click();
    await page.waitForFunction((expected) => document.documentElement.lang === expected, value);
  }
  async function setTheme(value) {
    await theme.click();
    await page.waitForFunction((expected) => document.documentElement.dataset.theme === expected && !document.documentElement.hasAttribute('data-theme-changing'), value);
  }
  for (const path of sections) {
    const link = sidebarLink(path);
    await link.focus();
    await link.press('Enter');
    await check(path, link);
    await setLanguage('ru');
    await setTheme('light');
    await check(path, link);
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1), `Core layout overflows on ${path}`);
    await setLanguage('en');
    await setTheme('dark');
    await check(path, link);
    navigation.push({ path, action: 'keyboard, active link, heading, locale and theme' });
  }
  await page.goBack();
  await check('/admin/privacy', sidebarLink('/admin/privacy'));
  await page.goForward();
  await check('/admin/audit', sidebarLink('/admin/audit'));
  const sessions = '/admin/security/sessions';
  const mfa = '/admin/security/mfa';
  const securityLink = sidebarLink('/admin/security');
  await sidebarLink('/admin/security').click();
  await page.locator(`.core-security__tabs a[href="${sessions}"]`).click();
  await check(sessions, securityLink);
  await page.locator(`.core-security__tabs a[href="${mfa}"]`).press('Enter');
  await check(mfa, securityLink);
  await page.locator(`.core-security__tabs a[href="${mfa}"][aria-current="page"]`).waitFor();
  await page.goBack();
  await check(sessions, securityLink);
  await page.locator(`.core-security__tabs a[href="${sessions}"][aria-current="page"]`).waitFor();
  await page.goForward();
  await check(mfa, securityLink);
  await page.goBack();
  await check(sessions, securityLink);
  await page.reload();
  await assertLoginRedirect(sessions);
  await loginOperator(actor.recoveryCodes[1]);
  await check(sessions, sidebarLink('/admin/security'));
  navigation.push({ path: sessions, action: 'nested Sessions/MFA, Back/Forward, guarded reload, MFA return and active parent link' });
  await measureAuthenticatedNavigation(page, {
    suite: 'core-admin',
    targets: ['/admin', '/admin/modules', '/admin/settings'].map((path) => ({
      path,
      locator: () => sidebarLink(path),
      ready: () => sidebarLink(path).and(page.locator('[aria-current="page"]')),
    })),
  });
  await sidebarLink('/admin/settings').click();
  await check('/admin/settings', sidebarLink('/admin/settings'));
  const capture = { suite: 'core-admin', screen: 'settings', mask: ['.admin__identity'] };
  const variants = [
    [320, 740, 'ru', 'light'], [393, 852, 'en', 'dark'], [430, 932, 'en', 'light'],
    [768, 1024, 'ru', 'dark'], [1024, 768, 'en', 'light'], [1440, 900, 'ru', 'dark'],
    [1920, 1080, 'ru', 'light'], [844, 390, 'en', 'dark'],
  ];
  for (const [width, height, locale, appearance] of variants) {
    await page.setViewportSize({ width, height });
    if ((await page.locator('html').getAttribute('lang')) !== locale) await setLanguage(locale);
    if ((await page.locator('html').getAttribute('data-theme')) !== appearance) await setTheme(appearance);
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1), `Core settings overflows at ${width}x${height}`);
    await captureAuthenticated(page, capture);
  }
  await page.setViewportSize({ width: 1440, height: 1000 });
  await button('Add setting').click();
  const dialog = page.getByRole('dialog');
  const input = dialog.locator('#config-key');
  await input.fill('invalid key!');
  assert.equal(await input.getAttribute('aria-invalid'), 'true');
  const preferences = await context.newPage();
  try {
    await preferences.goto(env.APP_URL + '/settings');
    await selectDropdown(preferences, 'appearance-language', 'ru');
    await preferences.getByRole('radio', { name: 'Светлая', exact: true }).check();
    await page.waitForFunction(() => document.documentElement.lang === 'ru' && document.documentElement.dataset.theme === 'light');
    assert.equal(await input.inputValue(), 'invalid key!');
    assert.equal(await input.getAttribute('aria-invalid'), 'true');
    assert.equal(await input.evaluate((element) => element === document.activeElement), true);
    await captureAuthenticated(page, { ...capture, screen: 'settings-invalid-draft' });
    await selectDropdown(preferences, 'appearance-language', 'en');
    await preferences.getByRole('radio', { name: 'Dark', exact: true }).check();
    await page.waitForFunction(() => document.documentElement.lang === 'en' && document.documentElement.dataset.theme === 'dark');
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    await dialog.waitFor({ state: 'hidden' });
    states.push('Real Core settings dialog keeps invalid draft, validation and focus through cross-tab RU/EN and Light/Dark');
  } finally {
    await preferences.close();
  }
}
const prefix = 'test.configuration.browser.';
mkdirSync('screenshots', { recursive: true });
try {
  await page.goto(env.APP_URL + '/admin/settings');
  await assertLoginRedirect('/admin/settings');
  await loginOperator(actor.recoveryCodes[0]);
  await page.waitForURL((u) => u.pathname === '/admin/settings');
  await auditWorkspace();
  await api('/core/modules/' + moduleCode + '/disable', { method: 'POST', data: {} });
  await setting(prefix + 'public', 'Published title', { publish: true });
  await setting(prefix + 'private', 'Internal marker');
  const rows = state().system;
  assert.equal(JSON.parse(rows.find((r) => r.key === prefix + 'public').value), 'Published title');
  assert.equal(rows.find((r) => r.key === prefix + 'public').is_public, true);
  assert.equal(rows.find((r) => r.key === prefix + 'private').is_public, false);
  states.push('MFA operator login and typed public/private settings persist in PostgreSQL');
  await button('Public configuration').click();
  await page.getByRole('heading', { name: prefix + 'public', exact: true }).waitFor();
  assert.equal(await page.getByText('Internal marker', { exact: true }).count(), 0);
  const publicReply = (await (await api('/public/config', { token: null })).json()).data.settings;
  assert.equal(publicReply[prefix + 'public'], 'Published title');
  assert.equal(prefix + 'private' in publicReply, false);
  states.push(
    'anonymous public API preview exposes the published value without private configuration',
  );
  await navigateClientSide(page, '/admin/modules');
  await page.locator('#config-module-search').fill(moduleCode);
  await button('Open module').click();
  await page.getByRole('heading', { name: 'Configuration browser module', exact: true }).waitFor();
  await button('Enable module').click();
  await page.locator('#config-module-ack').check();
  await button('Confirm', true).click();
  await page.getByRole('dialog').waitFor({ state: 'hidden' });
  assert.equal(state().module.enabled, true);
  await setting(prefix + 'limit', '25', { number: true });
  const card = page
    .locator('.config-card')
    .filter({ has: page.getByRole('heading', { name: prefix + 'limit', exact: true }) });
  await card.getByRole('button', { name: 'Edit', exact: true }).click();
  await page.getByRole('dialog', { name: 'Edit setting', exact: true }).waitFor();
  await page.locator('input[id^="value-text-"]').fill('30');
  await save();
  assert.equal(JSON.parse(state().settings.find((r) => r.key === prefix + 'limit').value), 30);
  states.push(
    'module detail, confirmed enable and individual module setting read/update match PostgreSQL',
  );
  await stableScreenshot(page, 'screenshots/core-config-real-module.png');
  await navigateClientSide(page, '/admin/settings');
  await button('Feature flags').click();
  await button('Add feature flag').click();
  await page.locator('#config-flag-code').fill(prefix + 'flag');
  await selectDropdown(page, 'config-flag-module', moduleCode);
  await page.locator('#config-flag-enabled').check();
  await page.locator('#config-flag-rollout').focus();
  await page.keyboard.press('End');
  await button('Add item', true).click();
  await page.locator('input[id^="value-key-"]').fill('label');
  await page.locator('input[id^="value-text-"]').fill('Real feature');
  await page.locator('#config-flag-ack').check();
  await save();
  let flag = state().flags.find((r) => r.code === prefix + 'flag');
  assert.equal(flag.enabled, true);
  assert.equal(flag.rollout_percent, 100);
  assert.deepEqual(JSON.parse(flag.config), { label: 'Real feature' });
  await page
    .locator('.config-card')
    .filter({ has: page.getByRole('heading', { name: prefix + 'flag', exact: true }) })
    .getByRole('button', { name: 'Edit', exact: true })
    .click();
  assert.equal(await page.locator('#config-flag-enabled').isChecked(), true);
  await button('Cancel', true).click();
  states.push(
    'related-module feature flag form persists boolean, rollout and structured config; fresh edit reloads current DTO',
  );
  await stableScreenshot(page, 'screenshots/core-config-real-flags.png');
  const basic = (
    await (
      await api('/auth/login', {
        method: 'POST',
        data: {
          identifierType: 'email',
          identifier: actors.reset.email,
          password: actors.reset.password,
        },
        token: null,
      })
    ).json()
  ).data;
  assert.equal(basic.status, 'authenticated');
  for (const path of ['/core/modules', '/core/settings/system', '/core/feature-flags'])
    await api(path, { token: basic.accessToken, status: 403 });
  await api('/core/settings/system/' + prefix + 'forbidden', {
    method: 'PUT',
    data: { value: true },
    token: basic.accessToken,
    status: 403,
  });
  await api('/auth/logout', { method: 'POST', data: {}, token: basic.accessToken, status: 204 });
  states.push('basic account receives 403 for configuration reads and mutations');
  const times = [];
  await Promise.all(
    Array.from({ length: 20 }, async () => {
      for (let i = 0; i < 5; i++) {
        const start = performance.now();
        await api(
          i % 2 ? '/core/modules/' + moduleCode : '/core/settings/system/' + prefix + 'public',
        );
        times.push(performance.now() - start);
      }
    }),
  );
  times.sort((a, b) => a - b);
  const load = {
    requests: 100,
    concurrency: 20,
    errors: 0,
    p95Ms: Math.round(times[Math.ceil(times.length * 0.95) - 1]),
  };
  await page
    .locator('.config-card')
    .filter({ has: page.getByRole('heading', { name: prefix + 'flag', exact: true }) })
    .getByRole('button', { name: 'Delete', exact: true })
    .click();
  await page.getByRole('dialog', { name: 'Delete feature flag', exact: true }).waitFor();
  await page.locator('#config-flag-ack').check();
  await button('Delete', true).click();
  await page.getByRole('dialog').waitFor({ state: 'hidden' });
  assert.equal(state().flags.length, 0);
  await button('System settings').click();
  for (const key of [prefix + 'public', prefix + 'private']) {
    await page
      .locator('.config-card')
      .filter({ has: page.getByRole('heading', { name: key, exact: true }) })
      .getByRole('button', { name: 'Delete', exact: true })
      .click();
    await page.getByRole('dialog', { name: 'Delete setting', exact: true }).waitFor();
    await page.locator('#config-ack').check();
    await button('Delete', true).click();
    await page.getByRole('dialog').waitFor({ state: 'hidden' });
  }
  assert.equal(state().system.length, 0);
  assert.equal(
    prefix + 'public' in
      (await (await api('/public/config', { token: null })).json()).data.settings,
    false,
  );
  states.push('confirmed deletion removes only selected entries and withdraws the public value');
  await navigateClientSide(page, '/admin/modules');
  await page.locator('#config-module-search').fill(moduleCode);
  await button('Open module').click();
  await page.getByRole('heading', { name: 'Configuration browser module', exact: true }).waitFor();
  await page
    .locator('.config-card')
    .filter({ has: page.getByRole('heading', { name: prefix + 'limit', exact: true }) })
    .getByRole('button', { name: 'Delete', exact: true })
    .click();
  await page.getByRole('dialog', { name: 'Delete setting', exact: true }).waitFor();
  await page.locator('#config-ack').check();
  await button('Delete', true).click();
  await page.getByRole('dialog').waitFor({ state: 'hidden' });
  assert.equal(state().settings.length, 0);
  await button('Disable module').click();
  await page.locator('#config-module-ack').check();
  await button('Confirm', true).click();
  await page.getByRole('dialog').waitFor({ state: 'hidden' });
  assert.equal(state().module.enabled, false);
  states.push('module setting deletion and confirmed disable complete the configuration lifecycle');
  await button('Sign out').click();
  await page.waitForURL((u) => u.pathname === '/admin/login');
  assert.deepEqual(errors, []);
  const result = { passed: true, realBackend: true, load, states, navigation };
  writeFileSync('screenshots/core-config-real-results.json', JSON.stringify(result, null, 2));
  console.log(JSON.stringify(result));
} catch (error) {
  await page.screenshot({ path: 'screenshots/core-config-real-failure.png' }).catch(() => {});
  throw error;
} finally {
  await browser.close();
}
