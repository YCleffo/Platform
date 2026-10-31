import { mkdirSync } from 'node:fs';
import { launchBrowser, selectDropdown } from './e2e-support.mjs';

const base = process.env.BASE_URL ?? 'http://127.0.0.1:5173';
const browser = await launchBrowser();
const errors = [];
mkdirSync('screenshots', { recursive: true });
function check(value, message) { if (!value) throw new Error(message); }
function track(page) {
  page.on('pageerror', error => errors.push(error.stack || String(error)));
  page.on('console', message => {
    if (message.type() === 'error' && !message.location().url?.includes('/api/')) errors.push(message.text());
  });
}
async function range(page, id, value) {
  await page.locator(`#${id}`).evaluate((input, value) => {
    input.value = String(value);
    input.dispatchEvent(new Event('input', { bubbles: true }));
  }, value);
}
async function settled(page) {
  await page.waitForFunction(() => document.documentElement.dataset.themeChanging === undefined);
}
async function overflow(page, label) {
  const bad = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
  if (bad) {
    console.error(JSON.stringify(await page.evaluate(() => [...document.querySelectorAll('body *')].map(el => ({
      tag: el.tagName, class: String(el.className), width: el.getBoundingClientRect().width, right: el.getBoundingClientRect().right,
    })).filter(el => el.right > innerWidth + 1).slice(0, 20))));
    await page.screenshot({ path: 'screenshots/settings-overflow.png', fullPage: true });
  }
  check(!bad, `${label}: horizontal overflow`);
}

try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  await context.addInitScript(() => {
    if (!localStorage.getItem('platform.locale')) localStorage.setItem('platform.locale', 'en');
    if (!localStorage.getItem('platform.theme')) localStorage.setItem('platform.theme', 'light');
  });
  const page = await context.newPage(); track(page);
  await page.goto(`${base}/settings`, { waitUntil: 'networkidle' });
  await page.locator('.appearance-view').waitFor();
  check((await page.locator('.app-layout__nav-link--active').getAttribute('href')) === '/settings', 'Settings navigation is not active');
  const initialFont = await page.evaluate(() => parseFloat(getComputedStyle(document.body).fontSize));
  await range(page, 'appearance-theme-duration', 600);
  await page.getByRole('radio', { name: 'Dark', exact: true }).check();
  await page.waitForFunction(() => document.documentElement.dataset.theme === 'dark');
  const durations = await page.evaluate(() => document.getAnimations()
    .filter(animation => animation.animationName?.includes('view-transition'))
    .map(animation => animation.effect.getTiming().duration));
  if (await page.evaluate(() => typeof document.startViewTransition === 'function')) {
    check(durations.length > 0 && durations.every(duration => duration === 600), `Theme animations are not synchronized: ${durations}`);
  }
  await settled(page);
  await page.evaluate(() => window.scrollTo(0, 0));
  // Rapid changes must leave the last requested theme, without rejected ready promises.
  await page.getByRole('radio', { name: 'Light', exact: true }).check();
  await page.getByRole('radio', { name: 'Dark', exact: true }).check();
  await settled(page);
  check(await page.evaluate(() => document.documentElement.dataset.theme === 'dark'), 'Rapid theme changes lost the latest selection');
  await page.locator('#appearance-animations').uncheck();
  await page.getByRole('radio', { name: 'Light', exact: true }).check();
  await settled(page);
  check(await page.locator('.app-skeleton').first().evaluate(el => getComputedStyle(el).animationName === 'none'), 'Animations were not disabled');
  await range(page, 'appearance-font-scale', 1.4);
  await range(page, 'appearance-spacing-scale', 1.25);
  await range(page, 'appearance-button-x', 32);
  await range(page, 'appearance-button-y', 16);
  await range(page, 'appearance-radius-scale', 0);
  await page.getByRole('radio', { name: 'Spacious', exact: true }).check();
  await selectDropdown(page, 'appearance-shadows', 'none');
  const measured = await page.evaluate(() => ({
    font: parseFloat(getComputedStyle(document.body).fontSize),
    padding: parseFloat(getComputedStyle(document.querySelector('.appearance-view__preview .app-button--primary')).paddingLeft),
    shadow: getComputedStyle(document.querySelector('.appearance-view__preview .app-card')).boxShadow,
    radius: getComputedStyle(document.querySelector('.appearance-view__preview .app-card')).borderRadius,
  }));
  check(measured.font > initialFont && Math.abs(measured.padding - 36.8) < .2, `Font/button settings not applied: ${JSON.stringify(measured)}`);
  check(measured.shadow === 'none' && measured.radius === '0px', 'Shadow/radius settings not applied');
  for (const width of [320, 393, 768, 1024, 1440]) {
    await page.setViewportSize({ width, height: 1000 });
    await overflow(page, `${width}px spacious + largest font`);
  }
  await page.reload({ waitUntil: 'networkidle' });
  check(await page.locator('#appearance-font-scale').inputValue() === '1.4', 'Preferences were not restored on reload');
  const other = await context.newPage(); track(other);
  await other.goto(`${base}/settings`, { waitUntil: 'networkidle' });
  await range(page, 'appearance-font-scale', 1.1);
  await other.waitForFunction(() => document.documentElement.style.getPropertyValue('--ui-font-scale') === '1.1');
  await other.close();
  await page.getByRole('button', { name: 'Reset appearance', exact: true }).click();
  check(await page.locator('#appearance-font-scale').inputValue() === '1', 'Reset did not restore defaults');
  check(await page.locator('#appearance-animations').isChecked(), 'Reset did not restore animations');
  await page.getByRole('button', { name: 'Open a dialog', exact: true }).click();
  await page.getByRole('dialog').waitFor();
  await page.keyboard.press('Escape');
  await page.getByRole('dialog').waitFor({ state: 'hidden' });
  check(await page.getByRole('button', { name: 'Open a dialog', exact: true }).evaluate(el => el === document.activeElement), 'Dialog did not restore focus');
  await page.locator('#appearance-font-scale').focus();
  await page.keyboard.press('ArrowRight');
  check(await page.locator('#appearance-font-scale').inputValue() === '1.05', 'Range is not keyboard accessible');
  await page.getByRole('button', { name: 'Reset appearance', exact: true }).click();
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({ path: 'screenshots/settings-desktop-light.png', fullPage: true });
  await page.getByRole('radio', { name: 'Dark', exact: true }).check(); await settled(page);
  await page.screenshot({ path: 'screenshots/settings-desktop-dark.png', fullPage: true });
  await selectDropdown(page, 'appearance-language', 'ru');
  await page.setViewportSize({ width: 393, height: 852 });
  await page.screenshot({ path: 'screenshots/settings-mobile-ru-dark.png', fullPage: true });
  await overflow(page, 'Russian mobile');
  await page.getByRole('link', { name: 'Безопасность', exact: true }).click();
  await page.waitForURL('**/login?**');
  check(new URL(page.url()).searchParams.get('redirect') === '/settings/security', 'Security authentication guard was lost');
  await context.close();

  const reduced = await browser.newContext({ reducedMotion: 'reduce', colorScheme: 'dark' });
  await reduced.addInitScript(() => { localStorage.setItem('platform.theme', 'system'); localStorage.setItem('platform.locale', 'en'); });
  const reducedPage = await reduced.newPage(); track(reducedPage);
  await reducedPage.goto(`${base}/settings`, { waitUntil: 'networkidle' });
  check(await reducedPage.locator('#appearance-speed').isDisabled(), 'System reduced motion is not respected');
  check(await reducedPage.evaluate(() => document.documentElement.dataset.theme === 'dark' && document.documentElement.dataset.motion === 'reduced'), 'System theme/motion not applied');
  await reducedPage.getByRole('radio', { name: 'Light', exact: true }).check(); await settled(reducedPage);
  check(await reducedPage.evaluate(() => !document.getAnimations().some(a => a.animationName?.includes('view-transition'))), 'Reduced motion started a theme animation');
  await reduced.close();

  const blocked = await browser.newContext();
  await blocked.addInitScript(() => {
    Object.defineProperty(window, 'localStorage', { get() { throw new DOMException('Blocked', 'SecurityError'); } });
  });
  const blockedPage = await blocked.newPage(); track(blockedPage);
  await blockedPage.goto(`${base}/settings`, { waitUntil: 'networkidle' });
  await blockedPage.locator('.appearance-view').waitFor();
  await range(blockedPage, 'appearance-font-scale', 1.2);
  check(await blockedPage.evaluate(() => document.documentElement.style.getPropertyValue('--ui-font-scale') === '1.2'), 'Blocked storage prevented session preferences');
  await blocked.close();
  check(errors.length === 0, errors.join('\n'));
  console.log('APPEARANCE CHECKS PASSED: synchronized theme, rapid changes, system/reduced motion, density/font/padding/radius/shadows, persistence, multi-tab, keyboard/dialog, 320–1440px, RU, guard and blocked storage.');
} catch (error) { console.error(errors.join('\n')); throw error; }
finally { await browser.close(); }
