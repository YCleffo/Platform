import { mkdirSync } from 'node:fs';
import { launchBrowser, selectDropdown } from './e2e-support.mjs';

const base = process.env.BASE_URL ?? 'http://127.0.0.1:5173';
const browser = await launchBrowser();
const errors = [];
let activePage;
const check = (value, message) => { if (!value) throw new Error(message); };
mkdirSync('screenshots', { recursive: true });
async function bounds(page) {
  // Resize and drill/cascade replacement schedule layout on the next frame.
  // Measure the positioned panels, including their entrance animation.
  await page.waitForFunction(() => {
    const panels = [...document.querySelectorAll('.app-menu__panel')];
    return panels.length > 0 && panels.every(panel => {
      const rect = panel.getBoundingClientRect();
      return rect.left >= 0 && rect.top >= 0 && rect.right <= innerWidth + 1 && rect.bottom <= innerHeight + 1;
    });
  });
  for (const panel of await page.locator('.app-menu__panel').all()) {
    const box = await panel.boundingBox();
    const size = page.viewportSize();
    check(box && box.x >= 0 && box.y >= 0 && box.x + box.width <= size.width + 1 && box.y + box.height <= size.height + 1, `Menu outside viewport: ${JSON.stringify(box)}`);
  }
  check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'Menu caused horizontal overflow');
}
try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  await context.addInitScript(() => { localStorage.setItem('platform.locale', 'en'); localStorage.setItem('platform.theme', 'dark'); });
  const page = await context.newPage();
  activePage = page;
  page.on('pageerror', error => errors.push(error.stack || String(error)));
  page.on('console', message => { if (message.type() === 'error' && !message.location().url?.includes('/api/')) errors.push(message.text()); });
  await page.goto(`${base}/settings/components`, { waitUntil: 'networkidle' });
  await selectDropdown(page, 'demo-single', 'en');
  check(await page.locator('#demo-single').innerText() === 'English', 'Single choice did not update');
  await page.locator('#demo-single').focus(); await page.keyboard.press('ArrowUp');
  check(await page.locator('[data-menu-id="disabled"]').evaluate(el => el === document.activeElement), 'ArrowUp did not focus the final item');
  await page.keyboard.press('Enter');
  check(await page.locator('#demo-single').getAttribute('aria-expanded') === 'true', 'Disabled choice closed the menu');
  await page.keyboard.press('Home'); await page.keyboard.press('Enter');
  check(await page.locator('#demo-single').innerText() === 'Русский', 'Keyboard selection failed');
  check(await page.locator('#demo-single').evaluate(el => el === document.activeElement), 'Selection did not restore focus');
  await page.locator('#demo-search').click();
  const searchInputShadow = await page.getByRole('searchbox').evaluate(el => getComputedStyle(el).boxShadow);
  const searchFieldShadow = await page.getByRole('searchbox').evaluate(el => getComputedStyle(el.parentElement).boxShadow);
  check(searchInputShadow === 'none', 'Search input must not draw a second focus ring');
  check(searchFieldShadow !== 'none', 'Search field must keep one focus ring');
  await page.getByRole('searchbox').fill('deu');
  check(await page.locator('[data-menu-id]').count() === 1, 'Search did not filter');
  await page.keyboard.press('ArrowDown'); await page.keyboard.press('Enter');
  check(await page.locator('#demo-search').innerText() === 'Deutsch', 'Search keyboard selection failed');
  await page.locator('#demo-search').click(); await page.getByRole('searchbox').fill('missing');
  await page.getByRole('status').filter({ hasText: 'Nothing found' }).waitFor();
  await page.keyboard.press('Escape');
  await page.locator('#demo-multiple').click();
  await page.locator('[data-menu-id="en"]').click();
  check(await page.locator('[data-menu-id="ru"]').getAttribute('aria-checked') === 'true' && await page.locator('[data-menu-id="en"]').getAttribute('aria-checked') === 'true', 'Multiple selection did not persist');
  for (const id of ['de', 'es', 'fr', 'ja']) await page.locator(`[data-menu-id="${id}"]`).click();
  await page.keyboard.press('Escape');
  const chips = page.locator('#demo-multiple .app-dropdown__chip');
  check(await chips.count() === 3, 'Multiple trigger must show two labels and a +N chip');
  check(await page.locator('#demo-multiple .app-dropdown__chip--more').innerText() === '+4', 'Multiple overflow counter is wrong');
  const multipleHeight = (await page.locator('#demo-multiple').boundingBox())?.height ?? 0;
  check(multipleHeight <= 56, `Multiple trigger wrapped to a second line: ${multipleHeight}px`);
  await page.locator('#demo-multiple').locator('..').getByRole('button', { name: 'Clear selection', exact: true }).click();
  check(await page.locator('#demo-multiple').innerText() === 'Choose an option', 'Clear selection failed');

  await page.locator('#demo-hierarchy').click();
  await page.keyboard.press('ArrowDown'); await page.keyboard.press('ArrowRight'); await page.keyboard.press('ArrowRight');
  check(await page.locator('.app-menu__panel').count() === 3, 'Desktop hierarchy is not a three-panel cascade');
  await bounds(page);
  const panels = await page.locator('.app-menu__panel').all();
  const boxes = await Promise.all(panels.map(panel => panel.boundingBox()));
  for (let index = 1; index < boxes.length; index++) check(Math.abs(boxes[index].x - boxes[index - 1].x) >= boxes[index].width, 'Cascading panels overlap');
  await page.screenshot({ path: 'screenshots/menus-desktop-cascade.png', animations: 'disabled' });
  await page.setViewportSize({ width: 393, height: 852 });
  await page.waitForFunction(() => document.querySelectorAll('.app-menu__panel').length === 1);
  await bounds(page);
  const popupId = await page.locator('#demo-hierarchy').getAttribute('aria-controls');
  check(popupId && await page.locator(`[id="${popupId}"]`).getAttribute('role') === 'menu', 'Popup controls reference broke in mobile hierarchy');
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.waitForFunction(() => document.querySelectorAll('.app-menu__panel').length === 3);
  // Keep a nested menu open across cascade/drill replacement. Its new height
  // must be measured, including on the smallest supported viewport.
  for (const width of [320, 393, 768, 1024, 1440]) {
    await page.setViewportSize({ width, height: 852 });
    await page.waitForFunction(() => [...document.querySelectorAll('.app-menu__panel')].every(panel => {
      const rect = panel.getBoundingClientRect();
      return rect.left >= 0 && rect.top >= 0 && rect.right <= innerWidth + 1 && rect.bottom <= innerHeight + 1;
    }));
    await bounds(page);
  }
  await page.keyboard.press('ArrowLeft');
  check(await page.locator('[data-menu-id="design"]').evaluate(el => el === document.activeElement), 'Back did not restore parent focus');
  await page.keyboard.press('ArrowLeft'); await page.keyboard.press('Escape');
  await page.locator('#demo-hierarchy').click(); await page.getByRole('searchbox').fill('hex');
  await page.locator('[data-menu-id="colors"]').click();
  check(await page.locator('#demo-hierarchy').innerText() === 'Colors', 'Deep search did not select leaf');

  await page.locator('#demo-dialog-open').click(); await page.getByRole('dialog').waitFor();
  await page.locator('#demo-dialog').click(); await page.getByRole('searchbox').fill('english');
  await bounds(page);
  check(await page.getByRole('dialog').locator('[role="menu"]').count() === 1, 'Menu escaped modal accessibility/focus scope');
  await page.keyboard.press('Escape');
  check(await page.getByRole('dialog').isVisible(), 'Menu Escape closed parent dialog');
  await selectDropdown(page, 'demo-dialog', 'en');
  await page.keyboard.press('Tab');
  check(await page.getByRole('dialog').evaluate(el => el.contains(document.activeElement)), 'Tab escaped dialog');
  await page.keyboard.press('Escape'); await page.getByRole('dialog').waitFor({ state: 'hidden' });

  await page.locator('#demo-context').focus(); await page.keyboard.press('Shift+F10');
  await page.locator('[data-menu-id="message"]').click();
  check(await page.locator('#demo-context [role="status"]').innerText() === 'Context action completed.', 'Context action did not execute');
  check(await page.locator('#demo-context').evaluate(el => el === document.activeElement), 'Context menu did not restore focus');
  // Exercise bottom/right collision handling, then the site-wide context wrapper.
  await page.evaluate(() => document.querySelector('#demo-context').dispatchEvent(new MouseEvent('contextmenu', { clientX: innerWidth - 2, clientY: innerHeight - 2, bubbles: true, cancelable: true })));
  await page.locator('[data-menu-id="nested"]').hover(); await page.locator('[data-menu-depth="1"]').waitFor();
  await bounds(page); await page.keyboard.press('Escape'); await page.keyboard.press('Escape');
  const native = await page.locator('.brand').evaluate(el => !el.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true })));
  check(!native && await page.locator('.app-menu').count() === 0, 'Native link menu was intercepted');
  await page.locator('.settings-layout__header h1').click({ button: 'right' });
  await page.getByRole('menu', { name: 'Page actions', exact: true }).waitFor(); await page.keyboard.press('Escape');

  for (const width of [320, 393, 768, 1024]) {
    console.log(`Verify menu hierarchy at ${width}px`);
    await page.setViewportSize({ width, height: 852 });
    await page.locator('#demo-hierarchy').click();
    await page.locator('[data-menu-id="tools"]').click(); await page.locator('[data-menu-id="design"]').click();
    await bounds(page);
    if (width < 900) {
      check(await page.locator('.app-menu__panel').count() === 1, `${width}px hierarchy did not switch to Back navigation`);
      await page.locator('.app-menu__back').click();
      await page.locator('[data-menu-id="development"]').waitFor();
    }
    await page.locator('[data-menu-id]').first().focus();
    // Close all levels explicitly, even after a responsive drill transition.
    for (let count = 0; count < 4 && await page.locator('.app-menu').count(); count++) await page.keyboard.press('Escape');
  }
  await page.setViewportSize({ width: 393, height: 852 });
  await page.locator('#demo-hierarchy').click(); await page.locator('[data-menu-id="tools"]').click();
  await page.screenshot({ path: 'screenshots/menus-mobile-back.png', animations: 'disabled' });
  await page.getByRole('searchbox').pressSequentially('typography');
  check(await page.getByRole('searchbox').inputValue() === 'typography', 'Typing in a nested search lost focus');
  await page.locator('[data-menu-id="typography"]').click();
  check(await page.locator('#demo-hierarchy').innerText() === 'Typography', 'Nested search failed');
  await page.keyboard.press('Escape'); await page.keyboard.press('Escape');
  await page.goto(`${base}/settings`, { waitUntil: 'networkidle' });
  const languageLabel = page.locator('label[for="appearance-language"]');
  await languageLabel.click();
  await page.locator('.app-menu__panel').waitFor();
  check(await page.getByRole('searchbox').count() === 0, 'Short language dropdown must not show search');
  await languageLabel.click();
  await page.waitForTimeout(400);
  check(await page.locator('.app-menu__panel').count() === 0, 'Label click must close an open dropdown without replaying its open animation');
  await page.locator('#appearance-font-scale').focus(); await page.keyboard.press('End');
  await page.locator('#appearance-advanced .app-disclosure__trigger').click();
  await page.locator('#appearance-spacing-scale').focus(); await page.keyboard.press('End');
  await page.getByRole('radio', { name: 'Spacious', exact: true }).check();
  await page.getByRole('link', { name: 'Components', exact: true }).click();
  await page.setViewportSize({ width: 320, height: 852 });
  await page.locator('#demo-hierarchy').click(); await page.locator('[data-menu-id="tools"]').click();
  await bounds(page);
  await page.locator('[data-menu-id="design"]').click(); await bounds(page);
  await page.locator('[data-menu-id="colors"]').click();
  check(await page.locator('#demo-hierarchy').innerText() === 'Colors', 'Largest font/density broke nested selection');
  await page.setViewportSize({ width: 393, height: 852 });
  await page.goto(`${base}/`, { waitUntil: 'networkidle' });
  const menuButton = page.getByRole('button', { name: 'Open menu' });
  await menuButton.click();
  const mobileMenu = page.getByRole('dialog', { name: 'Menu' });
  await mobileMenu.waitFor();
  const menuBox = await mobileMenu.boundingBox();
  check(menuBox && menuBox.width >= 392 && menuBox.height >= 851, `Mobile menu is not full-screen: ${JSON.stringify(menuBox)}`);
  check(await page.evaluate(() => getComputedStyle(document.body).overflow === 'hidden'), 'Mobile menu did not lock body scroll');
  await page.screenshot({ path: 'screenshots/mobile-menu.png', animations: 'disabled' });
  await page.keyboard.press('Escape');
  await page.waitForTimeout(300);
  check(await mobileMenu.count() === 0, 'Escape did not close the mobile menu');
  check(await menuButton.evaluate(el => el === document.activeElement), 'Mobile menu did not restore focus to its trigger');
  await menuButton.click();
  await mobileMenu.getByRole('link', { name: 'Settings', exact: true }).click();
  await page.waitForURL('**/settings');
  await mobileMenu.waitFor({ state: 'detached', timeout: 2_000 });
  check(await mobileMenu.count() === 0, 'Mobile menu did not close after navigation');
  check(errors.length === 0, errors.join('\n'));
  console.log('MENU CHECKS PASSED: search/empty/clear, single/multiple/disabled, keyboard/focus, three-level cascade, mobile Back, viewport edges, dialog, native and custom context menus.');
  await context.close();
} catch (error) {
  if (activePage && !activePage.isClosed()) {
    await activePage.screenshot({ path: 'screenshots/menus-failure.png', fullPage: true });
    console.error(await activePage.locator('.app-menu__panel').evaluateAll(panels => panels.map(panel => ({ depth: panel.dataset.menuDepth, rect: panel.getBoundingClientRect().toJSON(), text: panel.textContent }))));
  }
  console.error(errors.join('\n')); throw error;
}
finally { await browser.close(); }
