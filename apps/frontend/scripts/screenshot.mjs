import { mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const outDir = join(root, 'screenshots');
mkdirSync(outDir, { recursive: true });

const BASE = 'http://localhost:5173';

const routes = ['login', 'register', 'mfa', 'verify-email', 'catalog', 'cabinet'];
const viewports = [
  { name: '320x568', width: 320, height: 568 },
  { name: '375x812', width: 375, height: 812 },
  { name: '393x852', width: 393, height: 852 },
  { name: '768x1024', width: 768, height: 1024 },
  { name: '1440x900', width: 1440, height: 900 },
];

const browser = await chromium.launch({ channel: 'chrome', headless: true });

const shots = [];

for (const vp of viewports) {
  for (const theme of ['light', 'dark']) {
    const context = await browser.newContext({ viewport: { width: vp.width, height: vp.height } });
    await context.addInitScript((t) => {
      localStorage.setItem('platform.theme', t);
      localStorage.setItem('platform.locale', 'en');
    }, theme);
    const page = await context.newPage();

    const targets = vp.name === '375x812' || vp.name === '393x852'
      ? routes
      : ['login', 'catalog', 'cabinet'];

    for (const route of targets) {
      const name = `${vp.name}-${route}-${theme}.png`;
      try {
        await page.goto(`${BASE}/${route === 'catalog' ? '' : route}`, { waitUntil: 'networkidle', timeout: 15000 });
        await page.waitForTimeout(400);
        await page.screenshot({ path: join(outDir, name), fullPage: false });
        shots.push(name);
      } catch (err) {
        console.error(`FAILED ${name}: ${String(err)}`);
      }
    }

    await context.close();
  }
}

await browser.close();

console.log('CAPTURED:');
for (const s of shots) {
  console.log(`  screenshots/${s}`);
}
