import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync } from 'node:fs';
import { launchBrowser } from './e2e-support.mjs';
import { revision } from './frontend07-evidence.mjs';

const base = new URL(process.env.BASE_URL ?? 'http://127.0.0.1:4174');
const rows = [];
const errors = [];
const out = 'screenshots/frontend09-closeout-navigation';
const commercialReads = process.argv.includes('--commercial-reads');
const browser = await launchBrowser();
const provenance = revision();
const title = (page) => page.getByRole('heading', { level: 1 }).first();
const currentPath = (page) => {
  const url = new URL(page.url());
  return url.pathname + url.search;
};

async function expectPath(page, path) {
  await page.waitForURL((url) => url.pathname + url.search === path, { timeout: 8000 });
  const ready = /^\/plans\//.test(path) ? page.locator('.plan-detail') : title(page);
  await ready.waitFor({ timeout: 8000 });
  assert.equal(currentPath(page), path);
}

async function verifyHistory(page, start, destination, visibleCurrent) {
  await page.goBack({ waitUntil: 'domcontentloaded' });
  await expectPath(page, start);
  await page.goForward({ waitUntil: 'domcontentloaded' });
  await expectPath(page, destination);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expectPath(page, destination);
  if (visibleCurrent) {
    assert.equal(await visibleCurrent.getAttribute('aria-current'), 'page');
  }
}

async function scenario({ source, scenario, start, destination, width = 393, mode = 'public-preview' }, action) {
  const context = await browser.newContext({
    viewport: { width, height: 852 },
    locale: 'en-US',
    colorScheme: 'light',
    reducedMotion: 'reduce',
    hasTouch: width < 768,
    isMobile: width < 768,
    ignoreHTTPSErrors: commercialReads,
  });
  await context.addInitScript(() => localStorage.setItem('platform.locale', 'en'));
  const page = await context.newPage();
  page.setDefaultTimeout(8000);
  const pageErrors = [];
  page.on('pageerror', (error) => pageErrors.push(error.name));
  const row = { source, scenario, start, expectedUrl: destination, viewport: { width, height: 852 }, mode,
    verificationMethod: 'Chromium browser interaction', testFile: 'apps/frontend/scripts/verify-frontend09-navigation.mjs' };
  try {
    await page.goto(new URL(start, base).href, { waitUntil: 'domcontentloaded' });
    await expectPath(page, start);
    const evidence = await action(page, context);
    assert.deepEqual(pageErrors, []);
    rows.push({ ...row, actualUrl: currentPath(page), evidence, classification: 'VERIFIED', verdict: 'PASS' });
  } catch (error) {
    errors.push(`${scenario}: ${error.message}`);
    rows.push({ ...row, actualPath: new URL(page.url()).pathname, evidence: { pageErrors }, classification: 'NOT_VERIFIED', verdict: 'FAIL' });
  } finally {
    await context.close();
  }
}

const navActive = (page, selector, path) => page.locator(`${selector}:visible a[href="${path}"]`);
const mobileMenu = async (page) => {
  await page.getByRole('button', { name: 'Open menu', exact: true }).click();
  const menu = page.getByRole('dialog', { name: 'Menu', exact: true });
  await menu.waitFor();
  return menu;
};

try {
  await scenario({ source: 'src/app/layouts/AppLayout.vue:173', scenario: 'Desktop primary link from catalog to Settings', start: '/plans', destination: '/settings', width: 1440 }, async (page) => {
    const settings = navActive(page, '.app-layout__nav', '/settings');
    await settings.click();
    await expectPath(page, '/settings');
    await verifyHistory(page, '/plans', '/settings', settings);
    return { action: 'Desktop Settings link', history: true, reload: true, active: 'settings' };
  });

  await scenario({ source: 'src/shared/ui/navigation/AppBottomNav.vue:338', scenario: 'Mobile primary link to Settings', start: '/plans', destination: '/settings' }, async (page) => {
    const settings = navActive(page, '.app-bottom-nav', '/settings');
    await settings.tap();
    await expectPath(page, '/settings');
    await verifyHistory(page, '/plans', '/settings', settings);
    return { action: 'Bottom tab tap', history: true, reload: true, active: 'settings' };
  });

  await scenario({ source: 'src/shared/ui/navigation/AppBottomNav.vue:128', scenario: 'Mobile indicator drag from Settings to Catalog', start: '/settings', destination: '/' }, async (page, context) => {
    const settings = navActive(page, '.app-bottom-nav', '/settings');
    const catalog = navActive(page, '.app-bottom-nav', '/');
    const pill = page.locator('.app-bottom-nav__pill');
    await settings.waitFor();
    await page.waitForFunction(() => getComputedStyle(document.querySelector('.app-bottom-nav__pill')).opacity === '1');
    const start = await pill.boundingBox();
    const end = await catalog.boundingBox();
    assert.ok(start && end);
    const client = await context.newCDPSession(page);
    const touch = (type, points) => client.send('Input.dispatchTouchEvent', { type, touchPoints: points });
    const y = Math.round(start.y + start.height / 2);
    await touch('touchStart', [{ x: Math.round(start.x + start.width / 2), y }]);
    await touch('touchMove', [{ x: Math.round(end.x + end.width / 2), y }]);
    await touch('touchEnd', []);
    await expectPath(page, '/');
    await verifyHistory(page, '/settings', '/', catalog);
    return { action: 'Chromium CDP touch drag', history: true, reload: true, active: 'catalog' };
  });

  await scenario({ source: 'src/app/layouts/SiteContextMenu.vue:302', scenario: 'Page context menu routes to Settings', start: '/plans', destination: '/settings', width: 1440 }, async (page) => {
    await title(page).click({ button: 'right' });
    await page.locator('[data-menu-id="settings"]').click();
    await expectPath(page, '/settings');
    await verifyHistory(page, '/plans', '/settings', navActive(page, '.app-layout__nav', '/settings'));
    return { action: 'Right click and menu selection', history: true, reload: true, active: 'settings' };
  });

  await scenario({ source: 'src/shared/ui/navigation/AppBreadcrumbs.vue:34', scenario: 'Compact breadcrumb returns to plan comparison', start: '/plans/business', destination: '/plans', width: 320 }, async (page) => {
    const crumb = page.locator('.app-breadcrumbs:visible');
    await crumb.locator('button[aria-haspopup="menu"]').click();
    await page.getByRole('menu').getByRole('menuitem', { name: /compare plans/i }).click();
    await expectPath(page, '/plans');
    await verifyHistory(page, '/plans/business', '/plans', navActive(page, '.app-bottom-nav', '/'));
    return { action: 'Breadcrumb overflow menu selection', history: true, reload: true, active: 'catalog', params: { code: 'business' } };
  });

  await scenario({ source: 'src/shared/ui/navigation/AppBreadcrumbs.vue:49', scenario: 'Expanded breadcrumb root returns to Catalog', start: '/plans/business', destination: '/', width: 700 }, async (page) => {
    await page.locator('.app-breadcrumbs:visible .app-breadcrumbs__root a[href="/"]').click({ timeout: 8000 });
    await expectPath(page, '/');
    await verifyHistory(page, '/plans/business', '/', navActive(page, '.app-bottom-nav', '/'));
    return { action: 'Expanded breadcrumb root link', history: true, reload: true, active: 'catalog', params: { code: 'business' } };
  });

  await scenario({ source: 'src/shared/ui/navigation/AppMobileMenu.vue:272', scenario: 'Main mobile menu navigates to Catalog', start: '/settings', destination: '/' }, async (page) => {
    const menu = await mobileMenu(page);
    await menu.getByRole('navigation', { name: 'Main', exact: true }).locator('a[href="/"]').click();
    await expectPath(page, '/');
    await menu.waitFor({ state: 'hidden' });
    await verifyHistory(page, '/settings', '/', navActive(page, '.app-bottom-nav', '/'));
    return { action: 'Mobile menu Main section link', history: true, reload: true, active: 'catalog' };
  });

  await scenario({ source: 'src/shared/ui/navigation/AppMobileMenu.vue:343', scenario: 'Personal mobile menu reaches Components', start: '/plans', destination: '/settings/components' }, async (page) => {
    const menu = await mobileMenu(page);
    await menu.getByRole('navigation', { name: 'Personal settings', exact: true }).locator('a[href="/settings/components"]').click();
    await expectPath(page, '/settings/components');
    await menu.waitFor({ state: 'hidden' });
    await verifyHistory(page, '/plans', '/settings/components', navActive(page, '.app-bottom-nav', '/settings'));
    return { action: 'Mobile menu Personal section link', history: true, reload: true, active: 'settings' };
  });

  for (const [start, destination] of [
    ['/password-reset', '/admin/forgot-password'],
    ['/mfa-recovery', '/admin/mfa-recovery'],
  ]) {
    await scenario({ source: 'src/modules/admin/auth/CoreAuthLinkView.vue', scenario: `Request a fresh link from ${start}`, start, destination }, async (page) => {
      await page.locator(`.core-auth-links a[href="${destination}"]`).click();
      await expectPath(page, destination);
      await verifyHistory(page, start, destination);
      return { action: 'Recovery link missing; open the proper request form', history: true, reload: true, session: 'public' };
    });
  }

  if (commercialReads) {
    assert.ok(['127.0.0.1', 'localhost', 'e2e.platform.test'].includes(base.hostname), 'Commercial checks require a test frontend origin');
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    let plan;
    try {
      const response = await context.request.get(new URL('/api/control/catalog/plans', base).href);
      assert.ok(response.ok(), 'Commercial test catalog is unavailable');
      const body = await response.json();
      plan = (body.data?.plans ?? body.plans)?.find(({ prices }) => prices.length > 0);
      assert.match(plan?.code ?? '', /^[a-z0-9-]+$/, 'Commercial test catalog contains no supported plan code');
      assert.match(plan?.prices[0]?.id ?? '', /^[a-z0-9-]+$/, 'Commercial test catalog contains no supported price ID');
    } finally {
      await context.close();
    }
    const detail = `/plans/${encodeURIComponent(plan.code)}`;
    const redirect = `${detail}?planPrice=${encodeURIComponent(plan.prices[0].id)}`;
    for (const [source, start, selector] of [
      ['src/modules/commercial/catalog/PlanCard.vue:92', '/', `.plan:has(a.plan__details[href="${detail}"]) a.plan__cta`],
      ['src/modules/commercial/catalog/PlanComparisonView.vue:129', '/plans', '.plans__cta'],
      ['src/modules/commercial/catalog/PlanDetailView.vue:93', detail, '.plan-detail__cta'],
    ]) {
      const loginDestination = `/login?redirect=${encodeURIComponent(redirect)}`;
      await scenario({ source, scenario: `Priced plan CTA from ${start}`, start, destination: loginDestination, mode: 'isolated-commercial-public-read' }, async (page) => {
        const button = page.locator(selector).filter({ visible: true }).and(
          page.locator(`a[href*="${plan.prices[0].id}"]`),
        );
        await button.click();
        await page.waitForURL((target) =>
          target.pathname === '/login' && target.searchParams.get('redirect') === redirect, { timeout: 8000 });
        const url = new URL(page.url());
        assert.equal(url.pathname, '/login');
        assert.equal(url.searchParams.get('redirect'), redirect);
        const destination = currentPath(page);
        await verifyHistory(page, start, destination);
        return { action: 'Activate priced plan CTA', history: true, reload: true, session: 'public', planCode: plan.code, priceMatchesCatalog: true };
      });
    }
  }
} finally {
  mkdirSync(out, { recursive: true });
  const report = commercialReads ? 'commercial-public-routes' : 'public-routes';
  writeFileSync(`${out}/${report}.json`, JSON.stringify({ ...provenance, browserVersion: browser.version(), rows }, null, 2) + '\n');
  await browser.close();
}

assert.deepEqual(errors, [], 'Navigation browser regressions');
console.log(`Verified ${rows.length} public transition scenarios in Chromium.`);
