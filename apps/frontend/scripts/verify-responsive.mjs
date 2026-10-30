import { chromium } from 'playwright';

const BASE = 'http://localhost:5173';
const widths = [320, 360, 375, 393, 430, 768, 1024, 1280, 1440];
const routes = [
  { path: 'login', name: 'login', selector: 'form' },
  { path: 'register', name: 'register', selector: 'form' },
  { path: 'mfa', name: 'mfa', selector: 'form' },
  { path: 'verify-email', name: 'verify-email', selector: '.verify-view' },
  { path: '', name: 'catalog', selector: '.catalog' },
];

const browser = await chromium.launch({ channel: 'chrome', headless: true });
const problems = [];

for (const width of widths) {
  const context = await browser.newContext({ viewport: { width, height: 800 } });
  const page = await context.newPage();
  const pageErrors = [];
  page.on('pageerror', (e) => pageErrors.push(String(e)));

  for (const route of routes) {
    const label = `${width}px ${route.name}`;
    try {
      await page.goto(`${BASE}/${route.path}`, { waitUntil: 'networkidle', timeout: 15000 });
      await page.waitForTimeout(200);
      const hasEl = (await page.$(route.selector)) !== null;
      const overflow = await page.evaluate(() => {
        const doc = document.documentElement;
        return { scrollWidth: doc.scrollWidth, clientWidth: doc.clientWidth };
      });
      if (!hasEl) {
        problems.push(`${label}: missing selector ${route.selector}`);
      }
      if (overflow.scrollWidth > overflow.clientWidth + 1) {
        problems.push(`${label}: HORIZONTAL OVERFLOW ${overflow.scrollWidth} > ${overflow.clientWidth}`);
      }
    } catch (err) {
      problems.push(`${label}: ERROR ${String(err)}`);
    }
  }

  if (pageErrors.length > 0) {
    problems.push(`${width}px: page errors -> ${pageErrors.join(' | ')}`);
  }
  await context.close();
}

await browser.close();

if (problems.length === 0) {
  console.log('ALL CHECKS PASSED: no horizontal overflow, all selectors present, no page errors.');
} else {
  console.log('PROBLEMS:');
  for (const p of problems) {
    console.log(`  ${p}`);
  }
}
