import { mkdirSync, writeFileSync } from 'node:fs';
import { launchBrowser, selectDropdown } from './e2e-support.mjs';

const base = process.env.BASE_URL ?? 'http://127.0.0.1:5173';
const browser = await launchBrowser();
const errors = [];
const check = (value, message) => { if (!value) throw new Error(message); };
const shots = [];
const observedExternalOrigins = new Set();
mkdirSync('screenshots', { recursive: true });
let activePage;
async function shot(page, name, fullPage = false) {
  await page.screenshot({ path: `screenshots/${name}.png`, animations: 'disabled', fullPage }); shots.push(name);
}
async function overflow(page) {
  check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${page.viewportSize().width}px horizontal overflow`);
}
try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  await context.addInitScript(() => {
    localStorage.setItem('platform.locale', 'en'); localStorage.setItem('platform.theme', 'light');
    // Count only window/document registrations owned by overlays, not element handlers.
    const targets = new Map();
    const add = EventTarget.prototype.addEventListener;
    const remove = EventTarget.prototype.removeEventListener;
    function records(target, type, options) {
      if (![window, document].includes(target) || !['keydown', 'pointerdown', 'resize', 'scroll'].includes(type)) return null;
      const key = `${type}:${typeof options === 'boolean' ? options : !!options?.capture}`;
      if (!targets.has(target)) targets.set(target, new Map());
      const types = targets.get(target);
      if (!types.has(key)) types.set(key, new Set());
      return types.get(key);
    }
    EventTarget.prototype.addEventListener = function(type, listener, options) { records(this, type, options)?.add(listener); return add.call(this, type, listener, options); };
    EventTarget.prototype.removeEventListener = function(type, listener, options) { records(this, type, options)?.delete(listener); return remove.call(this, type, listener, options); };
    window.__overlayListenerCount = () => [...targets.values()].reduce((sum, types) => sum + [...types.values()].reduce((sum, listeners) => sum + listeners.size, 0), 0);
  });
  const page = await context.newPage(); activePage = page;
  page.on('request', request => {
    const url = new URL(request.url());
    if (['http:', 'https:'].includes(url.protocol) && url.origin !== new URL(base).origin) {
      observedExternalOrigins.add(url.origin);
      if (['font', 'image', 'stylesheet'].includes(request.resourceType())) errors.push(`External visual asset: ${url.origin}`);
    }
  });
  page.on('pageerror', error => errors.push(error.stack || String(error)));
  page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
  await page.goto(`${base}/settings`, { waitUntil: 'networkidle' });
  check(await page.locator('#appearance-reset').isDisabled(), 'Reset should be disabled for defaults');
  check(await page.locator('#appearance-undo').isDisabled(), 'Undo should be disabled before editing');
  await page.locator('#appearance-preset-reading').click();
  check(await page.locator('#appearance-font-scale').inputValue() === '1.2', 'Reading preset did not increase text');
  await page.locator('#appearance-undo').click();
  check(await page.locator('#appearance-font-scale').inputValue() === '1', 'Preset undo failed');
  await page.getByRole('button', { name: 'Increase Font size', exact: true }).click();
  check(await page.locator('#appearance-font-scale').inputValue() === '1.05', 'Precise range step failed');
  await page.getByRole('button', { name: 'Reset to default: Font size', exact: true }).click();
  check(await page.locator('#appearance-font-scale').inputValue() === '1', 'Per-control reset failed');
  await page.locator('#appearance-font-scale').focus(); await page.keyboard.press('End');
  await page.locator('#appearance-undo').click();
  check(await page.locator('#appearance-font-scale').inputValue() === '1', 'Keyboard edit undo failed');
  await page.locator('#appearance-advanced .app-disclosure__trigger').click();
  await page.getByRole('button', { name: 'Increase Button horizontal padding', exact: true }).click();
  check(await page.locator('#appearance-button-x').inputValue() === '17', 'Advanced controls are inaccessible');
  await page.locator('#appearance-reset').click();
  await page.locator('#appearance-undo').click();
  check(await page.locator('#appearance-button-x').inputValue() === '17', 'Full reset cannot be undone');
  await page.locator('#appearance-reset').click();
  await page.locator('#appearance-advanced .app-disclosure__trigger').click();
  await page.evaluate(() => scrollTo(0, 0));
  await shot(page, 'ux-settings-desktop-light', true);
  await page.getByRole('radio', { name: 'Dark', exact: true }).check();
  await page.waitForFunction(() => !document.documentElement.hasAttribute('data-theme-changing'));
  await shot(page, 'ux-settings-desktop-dark', true);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.waitForFunction(() => document.documentElement.dataset.motion === 'reduced');
  check(await page.locator('#appearance-speed').isDisabled(), 'Live system motion preference was ignored');
  check(await page.getByRole('button', { name: 'Increase Animation speed', exact: true }).isDisabled(), 'Disabled motion can be bypassed with a stepper');
  await page.emulateMedia({ reducedMotion: 'no-preference' });

  await page.getByRole('link', { name: 'Components', exact: true }).click();
  const baseline = await page.evaluate(() => window.__overlayListenerCount());
  const timings = [];
  for (let index = 0; index < 30; index++) {
    const start = performance.now();
    await page.locator('#demo-search').click();
    await page.getByRole('searchbox').fill(`test-${index}`);
    await page.getByRole('button', { name: 'Clear search', exact: true }).click();
    check(await page.getByRole('searchbox').evaluate(el => el === document.activeElement), 'Clear search lost keyboard focus');
    await page.keyboard.press('Escape');
    if (index % 3 === 0) {
      await page.locator('#demo-dialog-open').click();
      await page.locator('#demo-dialog').click(); await page.keyboard.press('Escape'); await page.keyboard.press('Escape');
      await page.getByRole('dialog').waitFor({ state: 'hidden' });
    }
    timings.push(performance.now() - start);
  }
  check(await page.locator('.app-menu').count() === 0, 'Orphan popup after interaction stress');
  check(await page.evaluate(() => window.__overlayListenerCount()) === baseline, 'Overlay listeners leaked after repeated interactions');
  await page.locator('#demo-hierarchy').click();
  await page.keyboard.press('ArrowDown'); await page.keyboard.press('ArrowRight'); await page.keyboard.press('ArrowRight');
  await shot(page, 'ux-cascade-desktop');
  await page.keyboard.press('Escape'); await page.keyboard.press('Escape'); await page.keyboard.press('Escape');
  await page.goto(`${base}/settings`, { waitUntil: 'networkidle' });
  await selectDropdown(page, 'appearance-language', 'ru');
  await page.setViewportSize({ width: 393, height: 852 });
  await page.evaluate(() => scrollTo(0, 0)); await overflow(page); await shot(page, 'ux-settings-mobile-ru');
  await page.locator('input[name="appearance-theme"][value="dark"]').check();
  await page.waitForFunction(() => !document.documentElement.hasAttribute('data-theme-changing'));
  await page.evaluate(() => scrollTo(0, 0)); await shot(page, 'ux-settings-mobile-ru-dark');
  await page.locator('#appearance-font-scale').scrollIntoViewIfNeeded(); await shot(page, 'ux-settings-mobile-ranges');
  await page.locator('#appearance-advanced .app-disclosure__trigger').click();
  await page.locator('#appearance-button-x').scrollIntoViewIfNeeded(); await overflow(page); await shot(page, 'ux-settings-mobile-advanced');
  await page.locator('#appearance-animations').scrollIntoViewIfNeeded(); await shot(page, 'ux-settings-mobile-motion');
  await page.locator('#appearance-preset-reading').click();
  await page.locator('#appearance-font-scale').focus(); await page.keyboard.press('End');
  await page.setViewportSize({ width: 320, height: 740 });
  await page.evaluate(() => scrollTo(0, 0)); await overflow(page); await shot(page, 'ux-settings-320-large-font');
  await page.getByRole('link', { name: 'Компоненты', exact: true }).click();
  await page.locator('#demo-hierarchy').click(); await page.locator('[data-menu-id="tools"]').click();
  await overflow(page); await shot(page, 'ux-cascade-mobile-back');
  await page.keyboard.press('Escape'); await page.keyboard.press('Escape');
  await page.setViewportSize({ width: 844, height: 390 });
  await page.locator('#demo-hierarchy').click(); await page.locator('[data-menu-id="tools"]').click();
  for (const panel of await page.locator('.app-menu__panel').all()) {
    const box = await panel.boundingBox(); check(box.y >= 0 && box.y + box.height <= 391, 'Landscape menu exceeds visible height');
  }
  await shot(page, 'ux-cascade-landscape');
  check(errors.length === 0, errors.join('\n'));
  timings.sort((a, b) => a - b);
  const result = { passed: true, cycles: 30, dialogCycles: 10, listenerBaseline: baseline, listenerFinal: baseline,
    interactionCycleP95Ms: timings[Math.ceil(timings.length * .95) - 1], externalOriginsOutsideVisualAssets: [...observedExternalOrigins], screenshots: shots };
  writeFileSync('screenshots/ux-results.json', JSON.stringify(result, null, 2));
  console.log('UX CHECKS PASSED:', JSON.stringify(result));
  await context.close();
} catch (error) {
  if (activePage && !activePage.isClosed()) await activePage.screenshot({ path: 'screenshots/ux-failure.png', fullPage: true });
  throw error;
} finally { await browser.close(); }
