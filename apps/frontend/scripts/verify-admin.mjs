// Read-only UI regression with HTTP fixtures; this does not test server authorization.
import { mkdirSync, writeFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import { launchBrowser } from './e2e-support.mjs';

const base = process.env.BASE_URL ?? 'http://127.0.0.1:5173';
mkdirSync('screenshots', { recursive: true });
const browser = await launchBrowser();
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
await context.addInitScript(() => {
  localStorage.setItem('platform.locale', 'en');
  localStorage.setItem('platform.theme', 'dark');
});
const page = await context.newPage();
const errors = [];
const states = [];
page.on('pageerror', error => errors.push(error.message));

let holdRefresh = false;
let releaseRefresh;
const role = {
  id: 'role-a', code: 'core.admin', name: 'Administrator', isSystem: true, isProtected: true,
};
const person = {
  id: 'person-a', displayName: 'Fixture Person A', givenName: 'Fixture', familyName: 'Person',
};
await context.route('**/api/**', async route => {
  const path = new URL(route.request().url()).pathname;
  // Vite module URLs also contain /api/; only intercept actual API requests.
  if (!path.startsWith('/api/')) return route.continue();
  let data = { items: [], pagination: { total: 0, limit: 25, offset: 0 } };
  if (path === '/api/auth/login') {
    data = {
      status: 'authenticated', accessToken: 'fixture-access', refreshToken: 'fixture-refresh',
      accessExpiresAt: '2099-01-01T00:00:00Z', sessionExpiresAt: '2099-01-01T00:00:00Z',
      sessionId: 'session-a',
    };
  } else if (path === '/api/auth/me') {
    data = {
      account: { id: 'account-a', personId: null }, roles: ['core.superadmin'], permissions: [],
      locale: 'en', timezone: 'UTC', sessionId: 'session-a',
    };
  } else if (path === '/api/auth/logout') {
    return route.fulfill({ status: 204 });
  } else if (path === '/api/core/roles') {
    if (holdRefresh) await new Promise(resolve => { releaseRefresh = resolve; });
    data = { items: [role], pagination: { total: 1, limit: 25, offset: 0 } };
  } else if (path === '/api/core/roles/role-a') {
    data = role;
  } else if (path === '/api/core/persons') {
    data = { items: [person], pagination: { total: 1, limit: 25, offset: 0 } };
  }
  await route.fulfill({
    status: 200, contentType: 'application/json', body: JSON.stringify({ status: 'success', data }),
  });
});

async function navigate(path) {
  await page.locator('a[href="' + path + '"]').first().evaluate(element => element.click());
  await page.waitForURL(url => url.pathname === path);
  await page.waitForLoadState('networkidle');
}

function buttonGeometry() {
  return page.locator('.admin-resource__head .admin-resource__actions button').evaluateAll(elements =>
    elements.map(element => {
      const rect = element.getBoundingClientRect();
      return [rect.x, rect.y, rect.width, rect.height];
    }));
}

try {
  await page.goto(base + '/admin/login');
  await page.locator('#admin-identifier').fill('fixture@example.test');
  await page.locator('#admin-password').fill('Fixture-only-not-a-real-password');
  await page.locator('form button[type=submit]').click();
  await page.waitForURL(url => url.pathname === '/admin');

  const routes = ['', 'organizations', 'persons', 'accounts', 'rbac', 'modules',
    'settings', 'privacy', 'audit', 'security', 'finance'];
  for (const width of [1440, 393, 320]) {
    await page.setViewportSize({ width, height: 900 });
    for (const route of routes) {
      const path = '/admin' + (route ? '/' + route : '');
      await navigate(path);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
      states.push({ path, width, overflow });
    }
  }

  await page.setViewportSize({ width: 1440, height: 1000 });
  await navigate('/admin/rbac');
  const actions = page.locator('.admin-resource__head .admin-resource__actions');
  const labels = await actions.innerText();
  await page.locator('.admin__locale').click();
  const russianLabels = await actions.innerText();
  await page.locator('.admin__locale').click();

  const before = await buttonGeometry();
  holdRefresh = true;
  await page.getByRole('button', { name: 'Try again', exact: true }).click();
  await page.waitForFunction(() =>
    document.querySelector('.admin-resource__head button[aria-busy="true"]') !== null);
  const pending = await buttonGeometry();
  holdRefresh = false;
  releaseRefresh?.();
  await page.waitForFunction(() =>
    document.querySelector('.admin-resource__head button[aria-busy="true"]') === null);
  await page.screenshot({ path: 'screenshots/audit-rbac-desktop.png', animations: 'disabled' });

  await page.getByRole('button', { name: 'Details', exact: true }).first().click();
  await page.getByRole('dialog').waitFor();
  await page.waitForFunction(() => {
    const rect = document.querySelector('[role="dialog"]')?.getBoundingClientRect();
    return rect && Math.abs(rect.y) < 1 && Math.abs(rect.height - innerHeight) < 1;
  });
  const modal = await page.getByRole('dialog').boundingBox();
  await page.screenshot({ path: 'screenshots/audit-json-response.png', animations: 'disabled' });
  await page.keyboard.press('Escape');
  await page.getByRole('dialog').waitFor({ state: 'hidden' });
  await page.getByRole('button', { name: 'Create', exact: true }).click();
  await page.getByRole('dialog').waitFor();
  const accessibility = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();

  const result = {
    fixtureOnly: true, states, errors, labels, russianLabels,
    buttonGeometryStable: JSON.stringify(before) === JSON.stringify(pending),
    before, pending, modal,
    createDialogViolations: accessibility.violations.map(item => ({
      id: item.id, impact: item.impact, targets: item.nodes.map(node => node.target),
    })),
  };
  writeFileSync('screenshots/admin-interface.json', JSON.stringify(result, null, 2));
  const failures = [];
  if (states.some(state => state.overflow)) failures.push('Horizontal overflow in admin pages');
  if (errors.length) failures.push(...errors);
  if (!['Account roles', 'Assign role', 'Revoke role', 'Create', 'Try again']
    .every(label => labels.includes(label))) failures.push('Untranslated EN RBAC actions');
  if (!['Роли учётной записи', 'Назначить роль', 'Отозвать роль', 'Создать', 'Повторить']
    .every(label => russianLabels.includes(label))) failures.push('Untranslated RU RBAC actions');
  if (!result.buttonGeometryStable) failures.push('Refresh changes action button geometry');
  if (modal === null || Math.abs(modal.x) > 1 || Math.abs(modal.y) > 1
    || Math.abs(modal.width - 1440) > 1 || Math.abs(modal.height - 1000) > 1) {
    failures.push('JSON response does not fill the viewport');
  }
  if (result.createDialogViolations.length) failures.push('Create dialog has accessibility violations');
  if (failures.length) throw new Error(failures.join('; '));
  console.log('ADMIN INTERFACE PASSED: 33 fixture states, EN/RU actions, stable refresh buttons, fullscreen JSON, accessible create dialog.');
} finally {
  releaseRefresh?.();
  await browser.close();
}
