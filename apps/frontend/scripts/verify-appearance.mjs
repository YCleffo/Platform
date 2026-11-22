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
async function themeIs(page, expected) {
  await page.waitForFunction(theme => document.documentElement.dataset.theme === theme, expected);
  await settled(page);
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
async function wholeLabels(page, label) {
  const split = await page.locator('.appearance-choice span').evaluateAll(spans => spans.flatMap(span => {
    const range = document.createRange();
    range.selectNodeContents(span);
    return range.getClientRects().length > 1 ? [span.textContent?.trim() ?? ''] : [];
  }));
  check(split.length === 0, `${label}: labels break inside words: ${split.join(', ')}`);
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
  await page.evaluate(() => {
    window.__viewTransitionDurations = [];
    window.__themeTransitionCalls = 0;
    if (typeof document.startViewTransition === 'function') {
      const orig = document.startViewTransition.bind(document);
      document.startViewTransition = function(callback) {
        window.__themeTransitionCalls++;
        const vt = orig(callback);
        vt.ready.then(() => {
          window.__viewTransitionDurations = document.getAnimations()
            .filter(a => a.animationName?.includes('view-transition'))
            .map(a => a.effect?.getTiming()?.duration);
        }).catch(() => {});
        return vt;
      };
    }
  });
  await range(page, 'appearance-theme-duration', 600);
  await page.getByRole('radio', { name: 'Dark', exact: true }).check();
  await page.waitForFunction(() => document.documentElement.dataset.theme === 'dark');
  if (await page.evaluate(() => typeof document.startViewTransition === 'function')) {
    await page.waitForFunction(() => (window.__viewTransitionDurations?.length ?? 0) > 0 || document.documentElement.dataset.themeChanging === undefined, undefined, { timeout: 3000 });
    const durations = await page.evaluate(() => {
      const captured = window.__viewTransitionDurations ?? [];
      if (captured.length > 0) return captured;
      return document.getAnimations()
        .filter(animation => animation.animationName?.includes('view-transition'))
        .map(animation => animation.effect?.getTiming()?.duration);
    });
    check(durations.length > 0 && durations.every(duration => duration === 600), `Theme animations are not synchronized: ${durations}`);
  }
  await settled(page);
  await page.evaluate(() => window.scrollTo(0, 0));
  // Rapid changes must leave the last requested theme, without rejected ready promises.
  await page.locator('input[name="appearance-theme"]').evaluateAll(radios => {
    for (let index = 0; index < 10; index++) {
      radios.find(radio => radio.value === (index % 2 === 0 ? 'light' : 'dark')).click();
    }
  });
  await themeIs(page, 'dark');
  check(await page.getByRole('radio', { name: 'Dark', exact: true }).isChecked(), 'Rapid changes lost the latest selection');
  check(await page.evaluate(() => localStorage.getItem('platform.theme') === 'dark'), 'Rapid changes lost the persisted selection');

  await range(page, 'appearance-theme-duration', 0);
  const transitions = await page.evaluate(() => window.__themeTransitionCalls);
  await page.getByRole('radio', { name: 'Light', exact: true }).check();
  await themeIs(page, 'light');
  check(await page.evaluate(previous => window.__themeTransitionCalls === previous, transitions), 'Zero duration started a snapshot transition');
  await range(page, 'appearance-theme-duration', 200);
  await page.locator('#appearance-animations').uncheck();
  await page.getByRole('radio', { name: 'Dark', exact: true }).check();
  await themeIs(page, 'dark');
  await page.getByRole('radio', { name: 'Light', exact: true }).check();
  await themeIs(page, 'light');
  check(await page.locator('.app-skeleton').first().evaluate(el => getComputedStyle(el).animationName === 'none'), 'Animations were not disabled');
  await range(page, 'appearance-font-scale', 1.4);
  await page.locator('#appearance-advanced .app-disclosure__trigger').click();
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
    await wholeLabels(page, `${width}px spacious + largest font`);
  }
  await page.reload({ waitUntil: 'networkidle' });
  check(await page.locator('#appearance-font-scale').inputValue() === '1.4', 'Preferences were not restored on reload');
  await themeIs(page, 'light');
  check(await page.getByRole('radio', { name: 'Light', exact: true }).isChecked(), 'Theme selection was not restored on reload');
  const other = await context.newPage(); track(other);
  await other.goto(`${base}/settings`, { waitUntil: 'networkidle' });
  await range(page, 'appearance-font-scale', 1.1);
  await other.waitForFunction(() => document.documentElement.style.getPropertyValue('--ui-font-scale') === '1.1');
  await page.locator('#appearance-animations').check();
  await other.waitForFunction(() => document.documentElement.dataset.motion === 'full');
  await page.locator('#appearance-preview-input').fill('Unsubmitted appearance preview');
  await page.getByRole('button', { name: 'Open a dialog', exact: true }).click();
  await page.getByRole('dialog').waitFor();
  await other.getByRole('radio', { name: 'Dark', exact: true }).check();
  await themeIs(page, 'dark');
  check(await page.getByRole('dialog').isVisible(), 'Cross-tab theme change closed the dialog');
  const dialog = page.getByRole('dialog');
  const focused = await dialog.evaluate(el => {
    el.dataset.testLocaleDialog = 'preserve';
    return el.contains(document.activeElement);
  });
  check(focused, 'Preview dialog did not receive focus');
  const appearanceUrl = page.url();
  await selectDropdown(other, 'appearance-language', 'ru');
  await page.waitForFunction(() => document.documentElement.lang === 'ru', null, { timeout: 5000 });
  await dialog.getByRole('button', { name: 'Закрыть', exact: true }).first().waitFor();
  check(await dialog.evaluate(el => el.dataset.testLocaleDialog === 'preserve' && el.contains(document.activeElement)), 'Language change replaced the dialog or lost focus');
  check(await page.locator('#appearance-preview-input').inputValue() === 'Unsubmitted appearance preview', 'Language change cleared the preview field');
  await selectDropdown(other, 'appearance-language', 'en');
  await page.waitForFunction(() => document.documentElement.lang === 'en', null, { timeout: 5000 });
  await dialog.getByRole('button', { name: 'Close', exact: true }).first().waitFor();
  check(page.url() === appearanceUrl && await dialog.evaluate(el => el.dataset.testLocaleDialog === 'preserve' && el.contains(document.activeElement)), 'Round-trip language change navigated away or lost focus');
  await page.keyboard.press('Escape');
  await page.getByRole('dialog').waitFor({ state: 'hidden' });
  const dropdown = page.locator('#appearance-shadows');
  await dropdown.click();
  await page.locator('#appearance-shadows-menu').waitFor();
  await other.getByRole('radio', { name: 'Light', exact: true }).check();
  await themeIs(page, 'light');
  check(await page.locator('#appearance-shadows-menu').isVisible(), 'Cross-tab theme change closed the dropdown');
  check(await page.locator('#appearance-preview-input').inputValue() === 'Unsubmitted appearance preview', 'Theme changes cleared the preview field');
  await page.keyboard.press('Escape');
  await page.locator('#appearance-shadows-menu').waitFor({ state: 'hidden' });
  await other.close();
  await page.locator('#appearance-animations').uncheck();
  await page.locator('#appearance-preset-default').click();
  check(await page.locator('#appearance-font-scale').inputValue() === '1', 'Reset did not restore defaults');
  check(!(await page.locator('#appearance-animations').isChecked()), 'Visual presets must preserve the motion preference');
  await page.getByRole('button', { name: 'Open a dialog', exact: true }).click();
  await page.getByRole('dialog').waitFor();
  await page.keyboard.press('Escape');
  await page.getByRole('dialog').waitFor({ state: 'hidden' });
  check(await page.getByRole('button', { name: 'Open a dialog', exact: true }).evaluate(el => el === document.activeElement), 'Dialog did not restore focus');
  await page.locator('#appearance-font-scale').focus();
  await page.keyboard.press('ArrowRight');
  check(await page.locator('#appearance-font-scale').inputValue() === '1.05', 'Range is not keyboard accessible');
  await page.locator('#appearance-preset-default').click();
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({ path: 'screenshots/settings-desktop-light.png', fullPage: true });
  await page.getByRole('radio', { name: 'Dark', exact: true }).check(); await settled(page);
  await page.screenshot({ path: 'screenshots/settings-desktop-dark.png', fullPage: true });
  await selectDropdown(page, 'appearance-language', 'ru');
  await range(page, 'appearance-font-scale', 1.4);
  await page.getByRole('radio', { name: 'Просторная', exact: true }).check();
  for (const width of [320, 393]) {
    await page.setViewportSize({ width, height: 852 });
    await overflow(page, `${width}px Russian spacious + largest font`);
    await wholeLabels(page, `${width}px Russian spacious + largest font`);
  }
  await page.setViewportSize({ width: 393, height: 852 });
  await page.screenshot({ path: 'screenshots/settings-mobile-ru-dark.png', fullPage: true });
  await overflow(page, 'Russian mobile');
  await page.getByRole('link', { name: 'Безопасность', exact: true }).click();
  await page.waitForURL('**/login?**');
  check(new URL(page.url()).searchParams.get('redirect') === '/settings/security', 'Security authentication guard was lost');
  await context.close();

  const reduced = await browser.newContext({ reducedMotion: 'reduce', colorScheme: 'dark', viewport: { width: 393, height: 852 } });
  await reduced.addInitScript(() => { localStorage.setItem('platform.theme', 'system'); localStorage.setItem('platform.locale', 'en'); });
  const reducedPage = await reduced.newPage(); track(reducedPage);
  await reducedPage.goto(`${base}/settings`, { waitUntil: 'networkidle' });
  check(await reducedPage.locator('#appearance-speed').isDisabled(), 'System reduced motion is not respected');
  check(await reducedPage.evaluate(() => document.documentElement.dataset.theme === 'dark' && document.documentElement.dataset.motion === 'reduced'), 'System theme/motion not applied');
  await reducedPage.emulateMedia({ colorScheme: 'light' });
  await themeIs(reducedPage, 'light');
  await reducedPage.emulateMedia({ colorScheme: 'dark' });
  await themeIs(reducedPage, 'dark');
  await reducedPage.getByRole('radio', { name: 'Light', exact: true }).check();
  await themeIs(reducedPage, 'light');
  await reducedPage.emulateMedia({ colorScheme: 'light' });
  await reducedPage.emulateMedia({ colorScheme: 'dark' });
  check(await reducedPage.evaluate(() => document.documentElement.dataset.theme === 'light'), 'System appearance overrode explicit Light');
  check(await reducedPage.evaluate(() => !document.getAnimations().some(a => a.animationName?.includes('view-transition'))), 'Reduced motion started a theme animation');
  await reduced.close();

  const blocked = await browser.newContext({ colorScheme: 'dark', locale: 'en-US' });
  await blocked.addInitScript(() => {
    Object.defineProperty(window, 'localStorage', { get() { throw new DOMException('Blocked', 'SecurityError'); } });
    Object.defineProperty(document, 'startViewTransition', { value: undefined });
  });
  const initialPage = await blocked.newPage();
  await initialPage.route('**/*', route =>
    route.request().resourceType() === 'script' ? route.abort() : route.continue(),
  );
  await initialPage.goto(`${base}/settings`, { waitUntil: 'domcontentloaded' });
  check(await initialPage.evaluate(() => document.documentElement.dataset.theme === 'dark'), 'Initial theme ignored system dark when storage was blocked');
  await initialPage.close();
  const blockedPage = await blocked.newPage(); track(blockedPage);
  await blockedPage.goto(`${base}/settings`, { waitUntil: 'networkidle' });
  await blockedPage.locator('.appearance-view').waitFor();
  await range(blockedPage, 'appearance-font-scale', 1.2);
  check(await blockedPage.evaluate(() => document.documentElement.style.getPropertyValue('--ui-font-scale') === '1.2'), 'Blocked storage prevented session preferences');
  await blockedPage.getByRole('radio', { name: 'Light', exact: true }).check();
  await themeIs(blockedPage, 'light');
  await blocked.close();
  check(errors.length === 0, errors.join('\n'));
  console.log('APPEARANCE CHECKS PASSED: synchronized theme, ten rapid changes, zero-duration and fallback switches, system/reduced motion, density/font/padding/radius/shadows, persistence, multi-tab RU/EN without dialog, draft or focus reset, 320–1440px, guards and blocked storage.');
} catch (error) { console.error(errors.join('\n')); throw error; }
finally { await browser.close(); }
