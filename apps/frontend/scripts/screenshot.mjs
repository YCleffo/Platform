import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const outDir = join(root, 'screenshots');
mkdirSync(outDir, { recursive: true });

const BASE = process.env.BASE_URL ?? 'http://localhost:5173';

// Only unauthenticated, backend-independent states are captured.
// The authenticated cabinet and the create-account dialog require a running backend
// and are intentionally NOT screenshotted here (reported as NOT_VERIFIED).
const routes = [
  { path: '/login', expected: '/login', name: 'login' },
  { path: '/register', expected: '/register', name: 'register' },
  { path: '/verify-email', expected: '/verify-email', name: 'verify-email' },
  { path: '/', expected: '/', name: 'catalog' },
];

const viewports = [
  { name: '320x568', width: 320, height: 568 },
  { name: '375x812', width: 375, height: 812 },
  { name: '393x852', width: 393, height: 852 },
  { name: '768x1024', width: 768, height: 1024 },
  { name: '1440x900', width: 1440, height: 900 },
];

async function launch() {
  for (const channel of ['chrome', 'msedge']) {
    try {
      return await chromium.launch({ channel, headless: true });
    } catch {
      // try next channel
    }
  }
  return chromium.launch({ headless: true });
}

const browser = await launch();
const failures = [];
const shots = [];

for (const viewport of viewports) {
  for (const theme of ['light', 'dark']) {
    const context = await browser.newContext({ viewport: { width: viewport.width, height: viewport.height } });
    await context.addInitScript(
      (value) => {
        localStorage.setItem('platform.theme', value);
        localStorage.setItem('platform.locale', 'en');
      },
      theme,
    );
    const page = await context.newPage();

    for (const route of routes) {
      const name = `${viewport.name}-${route.name}-${theme}.png`;
      try {
        await page.goto(`${BASE}${route.path}`, { waitUntil: 'networkidle', timeout: 15000 });
        await page.waitForTimeout(250);
        const pathname = new URL(page.url()).pathname;
        if (pathname !== route.expected) {
          failures.push(`${name}: resolved to ${pathname}, expected ${route.expected}`);
          continue;
        }
        await page.screenshot({ path: join(outDir, name), fullPage: false });
        shots.push(name);
      } catch (error) {
        failures.push(`${name}: ${String(error)}`);
      }
    }

    await context.close();
  }
}

await browser.close();

console.log(`CAPTURED ${shots.length} screenshots in screenshots/`);
for (const shot of shots) {
  console.log(`  screenshots/${shot}`);
}
console.log('NOT_VERIFIED: authenticated cabinet, account workspace and create-account dialog (no auth in this static check).');
console.log('Use scripts/e2e-account-workspace.mjs with a running backend for the authenticated workspace.');

if (failures.length > 0) {
  console.error('SCREENSHOT FAILURES:');
  for (const failure of failures) {
    console.error(`  ${failure}`);
  }
  process.exit(1);
}
