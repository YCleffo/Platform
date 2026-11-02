import AxeBuilder from '@axe-core/playwright';
import { mkdirSync, writeFileSync } from 'node:fs';
import { launchBrowser } from './e2e-support.mjs';

const base = process.env.BASE_URL ?? 'http://127.0.0.1:5173';
const browser = await launchBrowser();
const results = [];
mkdirSync('screenshots', { recursive: true });
async function scan(page, label) {
  const invalidReferences = await page.locator('[aria-controls], [aria-labelledby], [aria-describedby], [aria-owns]').evaluateAll(elements => elements.flatMap(element =>
    ['aria-controls', 'aria-labelledby', 'aria-describedby', 'aria-owns'].flatMap(attribute => (element.getAttribute(attribute)?.split(/\s+/) ?? []).filter(id => id && !document.getElementById(id)).map(id => `${attribute}: ${id}`))));
  if (invalidReferences.length) throw new Error(`${label}: dangling ARIA references: ${invalidReferences.join(', ')}`);
  const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  results.push({ label, violations: result.violations.map(item => ({ id: item.id, impact: item.impact, description: item.description,
    nodes: item.nodes.map(node => ({ target: node.target, summary: node.failureSummary })) })), incomplete: result.incomplete.map(item => ({ id: item.id, count: item.nodes.length })) });
}
try {
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
    await context.close();
  }
  writeFileSync('screenshots/accessibility.json', JSON.stringify({ engine: 'axe-core 4.14', tags: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'], results }, null, 2));
  const bad = results.filter(result => result.violations.length);
  if (bad.length) { console.error(JSON.stringify(bad, null, 2)); throw new Error(`Accessibility violations in ${bad.length} states`); }
  console.log(`ACCESSIBILITY CHECKS PASSED: ${results.length} states, zero automated A/AA violations; manual review remains necessary.`);
} finally { await browser.close(); }
