// Read-only style matrix. HTTP fixtures include populated, empty and safe error states.
// This checks rendered typography/layout; it does not prove backend business lifecycles.
import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync } from 'node:fs';
import { launchBrowser, navigateClientSide, stableScreenshot } from './e2e-support.mjs';

const base = process.env.BASE_URL ?? 'http://localhost:5173';
const browser = await launchBrowser();
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
await context.addInitScript(() => {
  localStorage.setItem('platform.locale', 'en');
  localStorage.setItem('platform.theme', 'dark');
});
const page = await context.newPage();
const errors = [];
const problems = [];
const states = [];
const fonts = new Set();
page.on('pageerror', (error) => errors.push(error.message));
page.on('request', (req) => {
  if (/\.(woff2?|ttf)(?:\?|$)/.test(req.url())) fonts.add(req.url());
});
const uuid = (n) => `01900000-0000-7000-8000-${String(n).padStart(12, '0')}`;
const now = '2026-11-04T00:00:00Z';
const expiry = '2099-01-01T00:00:00Z';
const account = {
  id: uuid(1),
  code: 'fixture',
  display_name: 'Style fixture',
  legal_name: null,
  status: 'active',
  onboarding_state: 'ready_for_subscription',
  role: 'owner',
  created_at: now,
  updated_at: now,
  permissions: {
    read_account: true,
    view_members: true,
    manage_members: true,
    manage_ownership: true,
    manage_billing: true,
    manage_technical: true,
  },
};
const requestRow = {
  id: uuid(2),
  subjectPersonId: uuid(3),
  representationId: null,
  intakeChannel: 'paper',
  requestType: 'access',
  status: 'submitted',
  submittedAt: now,
  completedAt: null,
  resolutionCode: null,
  deadlines: [],
};
const module = {
  id: uuid(4),
  code: 'education',
  name: 'Education',
  description: 'Module configuration',
  version: '1.0.0',
  settingsSchemaVersion: 1,
  enabled: true,
  installedAt: now,
  enabledAt: now,
  disabledAt: null,
};
const setting = {
  id: uuid(5),
  key: 'appearance.example',
  value: { marker: 'a-long-local-configuration-value-'.repeat(6), version: 1 },
  isPublic: false,
  description: 'Internal configuration',
  createdAt: now,
  updatedAt: now,
};
let mfa = false;
await context.route('**/api/**', async (route) => {
  const req = route.request();
  const path = new URL(req.url()).pathname;
  if (!path.startsWith('/api/')) return route.continue();
  const send = (data) => route.fulfill({ status: 200, json: data });
  const core = (data) => send({ status: 'success', data });
  if (path === '/api/auth/login')
    return core({
      status: 'authenticated',
      accessToken: 'fixture',
      refreshToken: 'fixture',
      sessionId: 'fixture',
      accessExpiresAt: expiry,
      sessionExpiresAt: expiry,
    });
  if (path === '/api/auth/logout' || path === '/api/control/auth/logout')
    return route.fulfill({ status: 204 });
  if (path === '/api/auth/me')
    return core({
      account: { id: uuid(6), personId: null },
      roles: ['core.superadmin'],
      permissions: [],
      sessionId: 'fixture',
      locale: 'en',
      timezone: 'UTC',
    });
  if (
    path === '/api/auth/providers' ||
    path === '/api/auth/me/passkeys' ||
    path === '/api/auth/me/identities'
  )
    return core({ items: [] });
  if (path === '/api/auth/me/settings')
    return core({
      schema_version: 2,
      settings: {
        interface: { animations: true, transparency: true },
        notifications: { browser: true, sound: true, email: false },
        privacy: { show_online_status: false },
      },
    });
  if (path === '/api/auth/me/consents') return core({ definitions: [], consents: [] });
  if (path === '/api/auth/mfa')
    return core({ totpEnabled: false, verifiedAt: null, recoveryCodesRemaining: 0 });
  if (path === '/api/auth/sessions') return core([]);
  if (path === '/api/auth/me/privacy/representations') return core([]);
  if (path === '/api/auth/me/privacy/requests') return core([requestRow]);
  if (
    path === `/api/auth/me/privacy/requests/${requestRow.id}` ||
    path === `/api/core/privacy/requests/${requestRow.id}`
  )
    return core(requestRow);
  if (path === '/api/core/privacy/requests/options') return core({ purposeCodes: [] });
  if (path.endsWith('/blocks')) return core([]);
  if (path === '/api/core/privacy/requests')
    return core({ items: [requestRow], pagination: { total: 1, limit: 25, offset: 0 } });
  if (path === '/api/core/modules') return core([module]);
  if (path === '/api/core/modules/education') return core(module);
  if (path === '/api/core/settings/system') return core([setting]);
  if (path === '/api/core/feature-flags') return core([]);
  if (path === '/api/public/config') return core({ settings: {} });
  if (path.startsWith('/api/core/'))
    return core({ items: [], pagination: { total: 0, limit: 25, offset: 0 } });
  if (path === '/api/control/auth/login')
    return send(
      mfa
        ? { status: 'mfa_required', challenge_token: 'fixture', expires_in: 300 }
        : { access_token: 'fixture', refresh_token: 'fixture' },
    );
  if (path === '/api/control/me')
    return send({
      id: uuid(7),
      email: 'style@example.test',
      status: 'active',
      email_verified_at: now,
      last_login_at: now,
      created_at: now,
      updated_at: now,
    });
  if (path === '/api/control/accounts')
    return send({ items: [account], total: 1, limit: 20, offset: 0 });
  if (path === `/api/control/accounts/${account.id}`) return send(account);
  if (path === '/api/control/catalog/plans')
    return send({
      plans: [
        {
          id: uuid(21), code: 'start', version: 1, name: 'Старт',
          prices: [{ id: uuid(22), currency: 'RUB', amount_minor: 99000, billing_period: 'month' }],
          features: { seats: 3, analytics: false, sso: false, support: 'email', audit_retention_days: 30 },
        },
        {
          id: uuid(23), code: 'business', version: 1, name: 'Бизнес',
          prices: [{ id: uuid(24), currency: 'RUB', amount_minor: 299000, billing_period: 'month' }],
          features: { seats: 25, analytics: true, sso: true, support: 'priority', audit_retention_days: 180 },
        },
        {
          id: uuid(25), code: 'enterprise', version: 1, name: 'Корпоративный',
          prices: [{ id: uuid(26), currency: 'RUB', amount_minor: 999000, billing_period: 'month' }],
          features: { seats: 100, analytics: true, sso: true, support: 'dedicated', audit_retention_days: 365 },
        },
      ],
    });
  if (
    path.endsWith('/memberships') ||
    path.endsWith('/invitations') ||
    path.endsWith('/sessions') ||
    path.endsWith('/payment-methods')
  )
    return send({ items: [], total: 0, limit: 25, offset: 0 });
  return route.fulfill({
    status: 404,
    json: { error: { code: 'resource_not_found', message: 'Synthetic unavailable state' } },
  });
});

async function inspect(label) {
  await page.evaluate(() => document.fonts.ready);
  const result = await page.evaluate(() => {
    const probe = document.createElement('span');
    probe.style.cssText = 'position:fixed;visibility:hidden;pointer-events:none';
    document.body.append(probe);
    const root = getComputedStyle(document.documentElement);
    const types = Array.from(root)
      .filter((name) => /^--type-.+-size$/.test(name))
      .map((name) => {
        const prefix = name.slice(0, -5);
        probe.style.fontSize = `var(${name})`;
        probe.style.lineHeight = `var(${prefix}-line)`;
        probe.style.fontWeight = `var(${prefix}-weight)`;
        const c = getComputedStyle(probe);
        return {
          name: prefix,
          size: parseFloat(c.fontSize),
          line: parseFloat(c.lineHeight),
          weight: c.fontWeight,
        };
      });
    probe.remove();
    const selected = new Set(
      document.querySelectorAll('input:not([type=checkbox]):not([type=range]),textarea,select'),
    );
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    while (walker.nextNode()) {
      const node = walker.currentNode;
      if (
        node.textContent.trim() &&
        node.parentElement &&
        !node.parentElement.closest('script,style,svg,.visually-hidden')
      )
        selected.add(node.parentElement);
    }
    const invalid = [];
    const roleChecks = [
      ['body', '--type-body-medium'],
      ['.admin-dashboard__title,.config-head h2,.core-preferences h2', '--type-h2'],
      ['.config-card h3', '--type-h3'],
      [
        '.config-note,.config-value-text,.app-input__control--m .app-input__native',
        '--type-body-medium',
      ],
      ['.app-input__control--l .app-input__native', '--type-body-large'],
      [
        '.app-input__label,.app-checkbox__label,.app-switch__label,.core-preferences__fields label',
        '--type-body-medium-emphasis',
      ],
    ];
    for (const [selector, prefix] of roleChecks) {
      const expected = types.find((type) => type.name === prefix);
      for (const el of document.querySelectorAll(selector)) {
        if (!el.getClientRects().length) continue;
        const c = getComputedStyle(el);
        if (
          !expected ||
          Math.abs(expected.size - parseFloat(c.fontSize)) >= 0.03 ||
          Math.abs(expected.line - parseFloat(c.lineHeight)) >= 0.03 ||
          expected.weight !== c.fontWeight
        ) {
          invalid.push({
            role: prefix,
            selector,
            size: c.fontSize,
            line: c.lineHeight,
            weight: c.fontWeight,
          });
        }
      }
    }
    let checked = 0;
    for (const el of selected) {
      const c = getComputedStyle(el);
      if (!el.getClientRects().length || c.visibility !== 'visible' || c.display === 'none')
        continue;
      const size = parseFloat(c.fontSize);
      const line = parseFloat(c.lineHeight);
      checked++;
      const match = types.some(
        (type) =>
          Math.abs(type.size - size) < 0.03 &&
          Math.abs(type.line - line) < 0.03 &&
          type.weight === c.fontWeight,
      );
      if (!match || !/Inter|Space Mono/.test(c.fontFamily))
        invalid.push({
          tag: el.tagName,
          class: el.className,
          size,
          line,
          weight: c.fontWeight,
          family: c.fontFamily,
        });
    }
    return {
      invalid,
      checked,
      overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
      fontLoaded: document.fonts.check('14px Inter'),
    };
  });
  if (result.invalid.length || result.overflow || !result.fontLoaded)
    problems.push({ label, ...result });
  states.push({ label, checked: result.checked });
}

async function matrix(label) {
  for (const width of [320, 393, 768, 1440]) {
    // Desktop exposes the same theme/locale actions directly in all application shells.
    await page.setViewportSize({ width: 1440, height: 1000 });
    const dialogOpen = (await page.getByRole('dialog').count()) > 0;
    const language = dialogOpen
      ? await page.locator('html').getAttribute('lang')
      : width === 393
        ? 'ru'
        : 'en';
    if ((await page.locator('html').getAttribute('lang')) !== language) {
      await page
        .locator('button')
        .filter({ hasText: language === 'ru' ? /^EN$/ : /^RU$/ })
        .first()
        .click();
      await page.waitForFunction(
        (language) => document.documentElement.lang === language,
        language,
      );
    }
    const theme = dialogOpen
      ? await page.locator('html').getAttribute('data-theme')
      : width < 768
        ? 'light'
        : 'dark';
    if ((await page.locator('html').getAttribute('data-theme')) !== theme) {
      // Both application shells expose the theme action by its current localized title.
      const name =
        language === 'ru'
          ? theme === 'light'
            ? 'Светлая тема'
            : 'Тёмная тема'
          : theme === 'light'
            ? 'Light theme'
            : 'Dark theme';
      await page.getByRole('button', { name, exact: true }).click();
      await page.waitForFunction(
        (theme) =>
          document.documentElement.dataset.theme === theme &&
          !document.documentElement.hasAttribute('data-theme-changing'),
        theme,
      );
    }
    await page.setViewportSize({ width, height: 1000 });
    for (const scale of [1, 1.4]) {
      await page.evaluate(
        (scale) => document.documentElement.style.setProperty('--ui-font-scale', String(scale)),
        scale,
      );
      await inspect(`${label} ${language} ${theme} ${width}px ${scale * 100}%`);
    }
  }
  await page.evaluate(() => document.documentElement.style.setProperty('--ui-font-scale', '1'));
}
async function coreLogin(target = '/admin') {
  await page.goto(base + '/admin/login?redirect=' + encodeURIComponent(target));
  await page.locator('#admin-identifier').fill('style@example.test');
  await page.locator('#admin-password').fill('Fixture-only-password');
  await page.locator('form button[type=submit]').click();
  await page.waitForURL((u) => u.pathname === target);
}
async function commercialLogin() {
  await page.goto(base + '/login');
  await page.locator('#login-email').fill('style@example.test');
  await page.locator('#login-password').fill('Fixture-only-password');
  await page.locator('form button[type=submit]').click();
  await page.waitForURL((u) => u.pathname === (mfa ? '/mfa' : '/cabinet'));
}
const coreRoutes = [
  ['/admin', '.admin-dashboard'],
  ['/admin/organizations', '.admin-resource'],
  ['/admin/persons', '.admin-resource'],
  ['/admin/accounts', '.admin-resource'],
  ['/admin/rbac', '.admin-tabs'],
  ['/admin/modules', '#config-module-search'],
  ['/admin/settings', '#config-setting-search'],
  ['/admin/profile', '.core-preferences'],
  ['/admin/profile/consents', '.core-consents'],
  ['/admin/profile/privacy', '.core-privacy'],
  [`/admin/profile/privacy/${requestRow.id}`, '.core-privacy'],
  ['/admin/privacy', '.core-privacy'],
  ['/admin/privacy/registry', '.privacy-registry'],
  [`/admin/privacy/requests/${requestRow.id}`, '.core-privacy'],
  ['/admin/audit', '.admin-resource'],
  ['/admin/security', '.admin-security'],
  ['/admin/security/mfa', '.core-mfa'],
  ['/admin/security/sessions', '.core-sessions'],
  ['/admin/security/passkeys', '.core-credentials'],
  ['/admin/security/identities', '.core-credentials'],
  ['/admin/finance', '.admin-finance'],
];
const commercialRoutes = [
  ['/cabinet', '.cabinet'],
  ['/settings/security', '.security-view'],
  ['/settings/privacy', '.privacy'],
  [`/accounts/${account.id}`, '.overview'],
  [`/accounts/${account.id}/members`, '.members'],
  [`/accounts/${account.id}/billing`, '.billing'],
  [`/accounts/${account.id}/usage`, '.usage'],
  [`/accounts/${account.id}/analytics`, '.analytics'],
  [`/accounts/${account.id}/audit`, '.audit'],
  [`/accounts/${account.id}/invitations`, '.invitations'],
];
const publicRoutes = [
  '/operator',
  '/operator/catalog',
  '/',
  '/plans',
  '/plans/business',
  '/settings',
  '/settings/components',
  '/does-not-exist',
  '/login',
  '/admin/login',
  '/register',
  '/verify-email',
  '/resend-verification',
  '/forgot-password',
  '/reset-password?token=synthetic',
  '/invitation',
  '/activate?token=synthetic',
  '/password-reset?token=synthetic',
  '/mfa-recovery?token=synthetic',
  '/auth/callback/google',
  '/admin/forgot-password',
  '/admin/mfa-recovery',
];
mkdirSync('screenshots', { recursive: true });
try {
  await page.goto(base + '/', { waitUntil: 'networkidle' });
  // Read the registered routes, including records built with map/spread.
  const named = new Map(await page.evaluate(() => {
    const router = document.querySelector('#app').__vue_app__.config.globalProperties.$router;
    return router.getRoutes().filter(route => typeof route.name === 'string' && !route.name.startsWith('education-'))
      .map(route => [route.name, route.path.replace(/\/$/, '') || '/']);
  }));
  const tested = [...coreRoutes, ...commercialRoutes]
    .map(([path]) => path)
    .concat(
      publicRoutes.map((path) => path.split('?')[0]),
      '/mfa',
    );
  const resolved = await page.evaluate(paths => {
    const router = document.querySelector('#app').__vue_app__.config.globalProperties.$router;
    return paths.map(path => {
      const name = router.resolve(path).name;
      return { path, name: name === 'not-found' && path !== '/does-not-exist' ? null : name ?? null };
    });
  }, tested);
  const covered = new Set(resolved.map(route => route.name));
  assert.deepEqual(resolved.filter(route => route.name === null).map(route => route.path), [], 'Unknown routes in style matrix');
  assert.deepEqual(
    [...covered].sort(),
    [...named.keys()].sort(),
    'Every named route needs a style matrix entry',
  );
  await coreLogin();
  for (const [path, selector] of coreRoutes) {
    await navigateClientSide(page, path);
    await page.locator(selector).waitFor();
    await page.waitForFunction(
      () => !document.querySelector('main .app-skeleton,main [aria-busy=true]'),
    );
    await matrix(path);
    if (path === '/admin')
      await stableScreenshot(page, 'screenshots/typography-dashboard-desktop.png');
  }
  await navigateClientSide(page, '/admin/settings');
  await page.locator('#config-setting-search').waitFor();
  await stableScreenshot(page, 'screenshots/typography-settings-desktop.png');
  await page.getByRole('button', { name: 'Add setting', exact: true }).click();
  await page.getByRole('dialog', { name: 'Add setting', exact: true }).waitFor();
  await matrix('configuration dialog');
  await page.setViewportSize({ width: 393, height: 1000 });
  await stableScreenshot(page, 'screenshots/typography-settings-mobile.png');
  await page.getByRole('dialog').getByRole('button', { name: 'Cancel', exact: true }).click();
  await commercialLogin();
  for (const [path, selector] of commercialRoutes) {
    await navigateClientSide(page, path);
    await page.locator(selector).waitFor();
    await page.waitForFunction(
      () => !document.querySelector('main .app-skeleton,main [aria-busy=true]'),
    );
    await matrix(path);
  }
  await page.getByRole('button', { name: 'Sign out', exact: true }).click();
  await page.waitForURL((u) => u.pathname === '/login');
  for (const path of publicRoutes) {
    await page.goto(base + path, { waitUntil: 'networkidle' });
    await page.locator('main').waitFor();
    await matrix(path);
    if (path === '/settings')
      await stableScreenshot(page, 'screenshots/typography-public-settings.png');
  }
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto(base + '/plans', { waitUntil: 'networkidle' });
  await page.locator('.plans__table').waitFor();
  const planOverflow = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  );
  assert.ok(planOverflow <= 1, `plans mobile overflow: ${planOverflow}px`);
  const planFont = await page.locator('.plans__feature').first().evaluate((element) => getComputedStyle(element).fontFamily);
  assert.match(planFont, /Inter/);
  assert.match(planFont, /system-ui/);
  await stableScreenshot(page, 'screenshots/typography-plans-mobile.png');
  await page.setViewportSize({ width: 1440, height: 1000 });

  mfa = true;
  await commercialLogin();
  await page.locator('form').waitFor();
  await matrix('/mfa');
  assert.deepEqual(errors, []);
  assert.ok(fonts.size > 0);
  assert.ok([...fonts].every((url) => new URL(url).origin === new URL(base).origin));
  writeFileSync(
    'screenshots/typography-results.json',
    JSON.stringify(
      {
        fixtureOnly: true,
        passed: problems.length === 0,
        routes: 53,
        states,
        problems,
        localFonts: fonts.size,
      },
      null,
      2,
    ),
  );
  assert.deepEqual(problems, []);
  console.log(
    JSON.stringify({
      passed: true,
      fixtureOnly: true,
      routes: 53,
      states: states.length,
      textChecks: states.reduce((sum, s) => sum + s.checked, 0),
      localFonts: fonts.size,
    }),
  );
} catch (error) {
  writeFileSync(
    'screenshots/typography-failures.json',
    JSON.stringify({ states, problems, errors }, null, 2),
  );
  throw error;
} finally {
  await browser.close();
}
