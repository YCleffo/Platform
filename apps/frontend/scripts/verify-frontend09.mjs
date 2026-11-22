import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import { launchBrowser } from './e2e-support.mjs';

const base = process.env.BASE_URL ?? 'http://127.0.0.1:4174';
const output = 'screenshots/frontend09';
const variants = [
  { width: 320, height: 740, locale: 'ru', theme: 'light' },
  { width: 393, height: 852, locale: 'en', theme: 'dark' },
  { width: 844, height: 390, locale: 'ru', theme: 'dark' },
  { width: 1440, height: 900, locale: 'en', theme: 'light' },
];
const tags = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];
const captures = [];
const errors = [];
const browser = await launchBrowser();
mkdirSync(output, { recursive: true });

async function open(page, path, expected = path) {
  await page.goto(new URL(path, base).href, { waitUntil: 'domcontentloaded' });
  await page.waitForURL((url) => url.pathname === expected);
  await page.getByRole('heading', { level: 1 }).first().waitFor();
}

async function capture(page, variant, label) {
  const layout = await page.evaluate(async () => {
    await document.fonts.ready;
    const root = document.documentElement;
    const style = getComputedStyle(root);
    return {
      theme: root.dataset.theme,
      locale: root.lang,
      clientWidth: root.clientWidth,
      scrollWidth: root.scrollWidth,
      colors: Object.fromEntries(['--bg', '--surface', '--text-1', '--border', '--accent'].map((key) => [key, style.getPropertyValue(key).trim()])),
      performance: window.frontend09Performance,
    };
  });
  assert.equal(layout.theme, variant.theme);
  assert.equal(layout.locale.split('-')[0], variant.locale);
  assert.ok(layout.scrollWidth <= layout.clientWidth + 1, `${label} overflows at ${variant.width}×${variant.height}`);
  assert.ok(Object.values(layout.colors).every(Boolean), 'A required design token is missing');
  const axe = await new AxeBuilder({ page }).withTags(tags).analyze();
  const violations = axe.violations.map(({ id, nodes }) => ({ id, targets: nodes.map(({ target }) => target) }));
  const filename = `${label}-${variant.width}x${variant.height}-${variant.locale}-${variant.theme}.png`;
  const png = await page.screenshot({ path: `${output}/${filename}`, animations: 'disabled' });
  captures.push({
    label, ...variant, path: new URL(page.url()).pathname, file: filename,
    sha256: createHash('sha256').update(png).digest('hex'),
    layout, violations, axeIncomplete: axe.incomplete.map(({ id }) => id),
    axeIncompleteTargets: axe.incomplete.map(({ id, nodes }) => ({
      id,
      targets: nodes.map(({ target }) => target),
    })),
  });
}

try {
  for (const variant of variants) {
    const context = await browser.newContext({
      viewport: { width: variant.width, height: variant.height },
      locale: variant.locale,
      colorScheme: variant.theme,
      reducedMotion: 'reduce',
      hasTouch: variant.width < 1024,
    });
    try {
      await context.addInitScript(({ locale, theme }) => {
        if (localStorage.getItem('platform.locale') === null) localStorage.setItem('platform.locale', locale);
        if (localStorage.getItem('platform.theme') === null) localStorage.setItem('platform.theme', theme);
        const supported = globalThis.PerformanceObserver?.supportedEntryTypes ?? [];
        const metrics = {
          longTasks: supported.includes('longtask') ? { count: 0, maxMs: 0 } : null,
          layoutShiftWithoutInput: supported.includes('layout-shift') ? 0 : null,
        };
        window.frontend09Performance = metrics;
        if (metrics.longTasks) {
          new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) {
              metrics.longTasks.count++;
              metrics.longTasks.maxMs = Math.max(metrics.longTasks.maxMs, entry.duration);
            }
          }).observe({ type: 'longtask', buffered: true });
        }
        if (metrics.layoutShiftWithoutInput !== null) {
          new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) {
              if (!entry.hadRecentInput) metrics.layoutShiftWithoutInput += entry.value;
            }
          }).observe({ type: 'layout-shift', buffered: true });
        }
      }, variant);
      const page = await context.newPage();
      page.on('pageerror', (error) => errors.push(error.message));

      await open(page, '/settings');
      await capture(page, variant, 'public-settings');
      const tabs = page.locator('.settings-layout__tabs');
      const components = tabs.locator('a[href="/settings/components"]');
      await components.click();
      await page.waitForURL('**/settings/components');
      assert.equal(await components.getAttribute('aria-current'), 'page');
      await page.goBack();
      await page.waitForURL('**/settings');
      assert.equal(await tabs.locator('a[href="/settings"]').getAttribute('aria-current'), 'page');
      await page.goForward();
      await page.waitForURL('**/settings/components');
      await page.reload({ waitUntil: 'domcontentloaded' });
      await components.waitFor();
      assert.equal(await components.getAttribute('aria-current'), 'page');

      await open(page, '/login');
      await capture(page, variant, 'commercial-login');
      const email = page.locator('#login-email');
      const password = page.locator('#login-password');
      await email.fill('frontend09@example.test');
      await email.press('Tab');
      assert.equal(await password.evaluate((element) => document.activeElement === element), true);
      const authActions = page.locator('.auth-layout__actions');
      await authActions.locator('button').filter({ hasText: /^(RU|EN)$/ }).click();
      const changedLocale = variant.locale === 'en' ? 'ru' : 'en';
      await page.waitForFunction((locale) => document.documentElement.lang.split('-')[0] === locale, changedLocale);
      assert.equal(await email.inputValue(), 'frontend09@example.test');
      assert.equal(new URL(page.url()).pathname, '/login');
      await authActions.locator('button').filter({ hasText: /^(RU|EN)$/ }).click();
      await page.waitForFunction((locale) => document.documentElement.lang.split('-')[0] === locale, variant.locale);
      await page.locator('a[href="/forgot-password"]').click();
      await page.waitForURL('**/forgot-password');
      await page.getByRole('heading', { level: 1 }).first().waitFor();

      await open(page, '/admin', '/admin/login');
      assert.equal(new URL(page.url()).searchParams.get('redirect'), '/admin');
      await capture(page, variant, 'core-admin-login');
    } finally {
      await context.close();
    }
  }
} finally {
  const report = { browserVersion: browser.version(), tags, captures, pageErrors: errors };
  writeFileSync(`${output}/visual-report.json`, JSON.stringify(report, null, 2) + '\n');
  await browser.close();
}

assert.deepEqual(errors, [], 'Uncaught browser errors');
assert.deepEqual(captures.flatMap(({ label, violations }) => violations.map((violation) => ({ label, ...violation }))), [], 'Automated accessibility violations');
console.log(`FRONTEND_09 browser evidence: ${captures.length} public and auth captures, RU/EN, Light/Dark, browser history, and axe.`);
