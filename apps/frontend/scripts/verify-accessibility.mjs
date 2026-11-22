import assert from 'node:assert/strict';
import AxeBuilder from '@axe-core/playwright';
import { mkdirSync, writeFileSync } from 'node:fs';
import { launchBrowser } from './e2e-support.mjs';

const base = process.env.BASE_URL ?? 'http://127.0.0.1:5173';
const browser = await launchBrowser();
const results = [];
const settingsRangeChecks = [];
const settingsVariants = [
  { width: 320, height: 740, locale: 'ru', theme: 'light' },
  { width: 393, height: 852, locale: 'en', theme: 'dark' },
  { width: 844, height: 390, locale: 'ru', theme: 'dark' },
  { width: 1440, height: 900, locale: 'en', theme: 'light' },
];
const rangeNames = {
  en: [
    ['Font size', 'Decrease Font size', 'Increase Font size'],
    ['Animation speed', 'Decrease Animation speed', 'Increase Animation speed'],
    ['Theme transition', 'Decrease Theme transition', 'Increase Theme transition'],
  ],
  ru: [
    ['Размер шрифта', 'Уменьшить: Размер шрифта', 'Увеличить: Размер шрифта'],
    ['Скорость анимаций', 'Уменьшить: Скорость анимаций', 'Увеличить: Скорость анимаций'],
    ['Смена темы', 'Уменьшить: Смена темы', 'Увеличить: Смена темы'],
  ],
};
mkdirSync('screenshots', { recursive: true });

async function verifySettingsRanges() {
  for (const variant of settingsVariants) {
    const { width, height, locale, theme } = variant;
    const context = await browser.newContext({ viewport: { width, height }, reducedMotion: 'reduce' });
    try {
      await context.addInitScript(({ locale, theme }) => {
        localStorage.setItem('platform.locale', locale);
        localStorage.setItem('platform.theme', theme);
      }, variant);
      const page = await context.newPage();
      await page.goto(base + '/settings');
      const [fontSize] = rangeNames[locale];
      const fontRange = page.getByRole('group', { name: fontSize[0], exact: true });
      await fontRange.waitFor();
      for (const [rangeName, decrease, increase] of rangeNames[locale]) {
        const range = page.getByRole('group', { name: rangeName, exact: true });
        assert.equal(await range.getByRole('slider', { name: rangeName, exact: true }).count(), 1);
        assert.equal((await range.getByRole('button', { name: decrease, exact: true }).innerText()).trim(), '−');
        assert.equal((await range.getByRole('button', { name: increase, exact: true }).innerText()).trim(), '+');
      }
      const contrast = await fontRange.evaluate((range) => {
        function luminance(color) {
          const rgb = color.match(/[\d.]+/g).map((value) => Number(value) / 255);
          const weights = [0.2126, 0.7152, 0.0722];
          return weights.reduce((sum, weight, index) => {
            const channel = rgb[index];
            const linear = channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
            return sum + weight * linear;
          }, 0);
        }
        function ratio(color, background) {
          const first = luminance(color);
          const second = luminance(background);
          return (Math.max(first, second) + 0.05) / (Math.min(first, second) + 0.05);
        }
        const button = getComputedStyle(range.querySelector('.app-range__step'));
        const rail = getComputedStyle(range.querySelector('.app-range__rail'));
        const card = getComputedStyle(range.closest('.app-card'));
        return {
          stepSymbol: ratio(button.color, button.backgroundColor),
          rail: ratio(rail.backgroundColor, card.backgroundColor),
        };
      });
      assert.ok(contrast.stepSymbol >= 4.5, `${theme} ${width}px step symbol contrast ${contrast.stepSymbol}`);
      assert.ok(contrast.rail >= 3, `${theme} ${width}px range rail contrast ${contrast.rail}`);
      settingsRangeChecks.push({ ...variant, names: rangeNames[locale], contrast });
    } finally {
      await context.close();
    }
  }
}

async function scan(page, label) {
  const invalidReferences = await page.locator('[aria-controls], [aria-labelledby], [aria-describedby], [aria-owns]').evaluateAll(elements => elements.flatMap(element =>
    ['aria-controls', 'aria-labelledby', 'aria-describedby', 'aria-owns'].flatMap(attribute => (element.getAttribute(attribute)?.split(/\s+/) ?? []).filter(id => id && !document.getElementById(id)).map(id => `${attribute}: ${id}`))));
  if (invalidReferences.length) throw new Error(`${label}: dangling ARIA references: ${invalidReferences.join(', ')}`);
  const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  results.push({ label, violations: result.violations.map(item => ({ id: item.id, impact: item.impact, description: item.description,
    nodes: item.nodes.map(node => ({ target: node.target, summary: node.failureSummary })) })), incomplete: result.incomplete.map(item => ({ id: item.id, count: item.nodes.length })) });
}
try {
  await verifySettingsRanges();
  for (const theme of ['light', 'dark']) {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
    await context.addInitScript(theme => { localStorage.setItem('platform.locale', 'en'); localStorage.setItem('platform.theme', theme); }, theme);
    const page = await context.newPage();
    for (const path of ['/login', '/admin/login', '/register', '/verify-email', '/resend-verification', '/forgot-password', '/reset-password', '/reset-password?token=synthetic', '/invitation', '/does-not-exist', '/settings', '/settings/components']) {
      await page.goto(base + path, { waitUntil: 'networkidle' });
      await scan(page, `${theme} ${path}`);
    }
    await page.locator('#demo-hierarchy').click();
    await page.keyboard.press('ArrowDown'); await page.keyboard.press('ArrowRight'); await page.keyboard.press('ArrowRight');
    await scan(page, `${theme} cascade`);
    for (let n = 0; n < 3; n++) await page.keyboard.press('Escape');
    await page.locator('#demo-dialog-open').click(); await page.locator('#demo-dialog').click();
    await scan(page, `${theme} modal dropdown`);
    await page.keyboard.press('Escape'); await page.keyboard.press('Escape');
    await page.setViewportSize({ width: 393, height: 852 });
    await page.goto(base + '/settings', { waitUntil: 'networkidle' }); await scan(page, `${theme} mobile settings`);

    const plansPage = await context.newPage();
    await plansPage.route('**/api/control/catalog/plans', (route) => route.fulfill({
      json: {
        plans: [
          { id: '018f0000-0000-7000-8000-000000000021', code: 'starter', version: 1, name: 'Starter',
            prices: [{ id: '018f0000-0000-7000-8000-000000000022', currency: 'RUB', amount_minor: 99000, billing_period: 'month' }],
            features: { seats: 3, analytics: false, sso: false, support: 'email' } },
          { id: '018f0000-0000-7000-8000-000000000023', code: 'business', version: 1, name: 'Business',
            prices: [{ id: '018f0000-0000-7000-8000-000000000024', currency: 'RUB', amount_minor: 299000, billing_period: 'month' }],
            features: { seats: 25, analytics: true, sso: true, support: 'priority' } },
        ],
      },
    }));
    await plansPage.goto(base + '/plans', { waitUntil: 'networkidle' });
    await scan(plansPage, `${theme} plans`);
    await plansPage.setViewportSize({ width: 393, height: 852 });
    await plansPage.reload({ waitUntil: 'networkidle' });
    await scan(plansPage, `${theme} mobile plans`);
    await plansPage.close();

    await context.close();
  }
  writeFileSync('screenshots/accessibility.json', JSON.stringify({ engine: 'axe-core 4.14', tags: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'], results, settingsRangeChecks }, null, 2));
  const bad = results.filter(result => result.violations.length);
  if (bad.length) { console.error(JSON.stringify(bad, null, 2)); throw new Error(`Accessibility violations in ${bad.length} states`); }
  console.log(`ACCESSIBILITY CHECKS PASSED: ${results.length} axe states and ${settingsRangeChecks.length} Settings range name/contrast cases. Manual screen-reader review remains open.`);
} finally { await browser.close(); }
