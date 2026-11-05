// Pure UI fixtures: calendar/time, mobile density, breadcrumbs and common dialog chrome.
import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import { launchBrowser, stableScreenshot } from './e2e-support.mjs';
const base = process.env.BASE_URL ?? 'http://localhost:5173';
const browser = await launchBrowser();
mkdirSync('screenshots', { recursive: true });
const states = [];
try {
  for (const width of [320, 393, 1440]) for (const locale of ['en', 'ru']) {
    const context = await browser.newContext({ viewport: { width, height: 920 }, hasTouch: width < 768, isMobile: width < 768, ignoreHTTPSErrors: true });
    await context.addInitScript(({ locale }) => { localStorage.setItem('platform.locale', locale); localStorage.setItem('platform.theme', 'dark'); }, { locale });
    const page = await context.newPage(); const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.route((url) => url.pathname.startsWith('/api/'), (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ items: [], total: 0 }) }));
    await page.goto(base + '/settings/components');
    await page.locator('#demo-date').waitFor();
    const overflow = () => page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
    assert.equal(await overflow(), false);
    if (width < 768) {
      const sizes = await page.locator('.components-view__temporal').evaluate((card) => {
        const c = getComputedStyle(card); const button = card.querySelector('.app-disclosure__trigger');
        return { font: parseFloat(c.fontSize), family: c.fontFamily, padding: parseFloat(c.paddingLeft), button: parseFloat(getComputedStyle(button).fontSize), touchHeight: button.getBoundingClientRect().height };
      });
      assert.equal(sizes.font, 13); assert.match(sizes.family, /Inter/); assert.match(sizes.family, /system-ui/); assert.equal(sizes.padding, 16); assert.ok(sizes.button <= 13.1); assert.ok(sizes.touchHeight >= 44);
    }
    const dateControl = page.locator('#demo-date').locator('..');
    await dateControl.locator('.temporal-trigger').click();
    const calendar = page.locator('.app-temporal:not([aria-hidden="true"])'); await calendar.waitFor();
    const selected = calendar.locator('[data-day="2026-11-05"]');
    await selected.focus(); await selected.press('ArrowRight'); await page.keyboard.press('Enter');
    assert.equal(await page.locator('#demo-date').inputValue(), locale === 'ru' ? '06.11.2026' : '11/06/2026');
    assert.equal(await dateControl.locator('.temporal-trigger').getAttribute('aria-expanded'), 'false');
    await page.locator('#demo-bounded-date').locator('..').locator('.temporal-trigger').click();
    await calendar.waitFor(); assert.equal(await calendar.locator('[data-day="2026-11-02"]').isDisabled(), true);
    assert.equal(await calendar.locator('[data-day="2026-11-03"]').isDisabled(), false);
    const rect = await calendar.boundingBox(); assert.ok(rect.x >= 0 && rect.x + rect.width <= width + 1 && rect.y >= 0 && rect.y + rect.height <= 921);
    if (width === 320) await stableScreenshot(page, `screenshots/mobile-calendar-${locale}.png`);
    await page.keyboard.press('Escape'); await calendar.waitFor({ state: 'hidden' });
    assert.equal(await page.locator('#demo-disabled-date').locator('..').locator('.temporal-trigger').isDisabled(), true);
    assert.equal(await page.locator('#demo-readonly-date').locator('..').locator('.temporal-trigger').isDisabled(), true);

    await page.locator('#demo-time').locator('..').locator('.temporal-trigger').click();
    const fields = calendar.locator('.app-temporal__time .app-dropdown');
    for (const [index, value] of [[0, '07'], [1, '05']]) {
      await fields.nth(index).locator('.app-dropdown__trigger').click();
      await page.getByRole('menu').last().locator(`[data-menu-id="${value}"]`).click();
    }
    await calendar.getByRole('button', { name: locale === 'ru' ? 'Применить' : 'Apply', exact: true }).click();
    assert.equal(await page.locator('#demo-time').inputValue(), '07:05');
    await page.locator('#demo-datetime').locator('..').locator('.temporal-trigger').click();
    if (width === 393) await stableScreenshot(page, `screenshots/mobile-datetime-${locale}.png`);
    await page.keyboard.press('Escape');
    const crumb = page.locator('#demo-breadcrumbs');
    await crumb.getByRole('button').click(); await page.getByRole('menu').last().waitFor(); await page.keyboard.press('Escape');
    assert.ok((await crumb.innerText()).includes('…'));
    await page.locator('#demo-dialog-open').click();
    const dialog = page.getByRole('dialog'); await dialog.waitFor();
    await page.locator('#demo-dialog-date').locator('..').locator('.temporal-trigger').click();
    await calendar.waitFor();
    await page.keyboard.press('Escape'); await calendar.waitFor({ state: 'hidden' });
    assert.equal(await dialog.isVisible(), true, 'Calendar Escape must preserve its owner dialog');
    if (width === 393) await stableScreenshot(page, `screenshots/mobile-dialog-${locale}.png`);
    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    assert.deepEqual(axe.violations, []);
    await page.keyboard.press('Escape');
    assert.equal(await overflow(), false); assert.deepEqual(errors, []);
    states.push({ width, locale, passed: true }); await context.close();
  }
  const context = await browser.newContext({ timezoneId: 'America/New_York' });
  const page = await context.newPage();
  await page.goto(base + '/settings/components'); await page.locator('#demo-datetime').fill('2026-03-08T02:30');
  await page.locator('#demo-date').focus();
  assert.equal(await page.locator('#demo-datetime').evaluate((input) => input.checkValidity()), false, 'Nonexistent DST wall time must be rejected');
  await context.close();
  writeFileSync('screenshots/mobile-components-results.json', JSON.stringify({ passed: true, fixtureOnly: true, states, dstGap: 'rejected' }, null, 2));
  console.log(JSON.stringify({ passed: true, states: states.length, dstGap: 'rejected', fixtureOnly: true }));
} finally { await browser.close(); }
