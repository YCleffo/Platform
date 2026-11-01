import { chromium } from 'playwright';

const BASE = process.env.BASE_URL ?? 'http://localhost:5173';
const widths = [320, 360, 375, 393, 430, 768, 1024, 1280, 1440];

// No backend is required: these are static/guard states of the SPA.
const routes = [
  { path: '/login', expected: '/login', selector: 'form' },
  { path: '/register', expected: '/register', selector: 'form' },
  { path: '/verify-email', expected: '/verify-email', selector: '.verify-view' },
  { path: '/resend-verification', expected: '/resend-verification', selector: 'form' },
  { path: '/forgot-password', expected: '/forgot-password', selector: 'form' },
  { path: '/reset-password', expected: '/reset-password', selector: '.app-form-shell' },
  { path: '/reset-password?token=synthetic', expected: '/reset-password', selector: 'form' },
  { path: '/does-not-exist', expected: '/does-not-exist', selector: '.not-found' },
  { path: '/', expected: '/', selector: '.catalog' },
  { path: '/settings', expected: '/settings', selector: '.appearance-view' },
  { path: '/settings/components', expected: '/settings/components', selector: '.components-view' },
  // Protected / challenge routes must redirect to /login when unauthenticated.
  { path: '/cabinet', expected: '/login', selector: 'form' },
  { path: '/mfa', expected: '/login', selector: 'form' },
  { path: '/accounts/018f0000-0000-7000-8000-000000000001', expected: '/login', selector: 'form' },
  { path: '/accounts/018f0000-0000-7000-8000-000000000001/members', expected: '/login', selector: 'form' },
  { path: '/settings/security', expected: '/login', selector: 'form' },
  { path: '/accounts/018f0000-0000-7000-8000-000000000001/invitations', expected: '/login', selector: 'form' },
  { path: '/invitation', expected: '/invitation', selector: '.invitation-accept' },

];

const problems = [];

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

for (const width of widths) {
  const context = await browser.newContext({ viewport: { width, height: 800 } });
  await context.addInitScript(() => {
    localStorage.setItem('platform.theme', 'light');
    localStorage.setItem('platform.locale', 'en');
  });
  const page = await context.newPage();
  const pageErrors = [];
  page.on('pageerror', (error) => pageErrors.push(String(error)));
  page.on('console', (message) => {
    if (message.type() !== 'error') {
      return;
    }
    const url = message.location().url ?? '';
    // The SPA intentionally shows an error state when the backend is unavailable;
    // those network failures are expected here. Real JS errors still surface via pageerror.
    if (url.includes('/api/') || url.endsWith('/favicon.ico') || url.endsWith('/favicon.svg')) {
      return;
    }
    pageErrors.push(`${message.text()} (${url})`);
  });

  for (const route of routes) {
    const label = `${width}px ${route.path}`;
    try {
      await page.goto(`${BASE}${route.path}`, { waitUntil: 'networkidle', timeout: 15000 });
      await page.waitForTimeout(150);
      const pathname = new URL(page.url()).pathname;
      if (pathname !== route.expected) {
        problems.push(`${label}: resolved to ${pathname}, expected ${route.expected}`);
      }
      if ((await page.$(route.selector)) === null) {
        problems.push(`${label}: missing selector ${route.selector}`);
      }
      const overflow = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
      }));
      if (overflow.scrollWidth > overflow.clientWidth + 1) {
        problems.push(`${label}: HORIZONTAL OVERFLOW ${overflow.scrollWidth} > ${overflow.clientWidth}`);
      }
    } catch (error) {
      problems.push(`${label}: ERROR ${String(error)}`);
    }
  }

  if (pageErrors.length > 0) {
    problems.push(`${width}px: console/page errors -> ${pageErrors.join(' | ')}`);
  }
  await context.close();
}

// Theme + keyboard + localization checks (mobile reference width).
{
  const context = await browser.newContext({ viewport: { width: 393, height: 852 } });
  const page = await context.newPage();

  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
  await page.evaluate(() => localStorage.setItem('platform.theme', 'light'));
  await page.reload({ waitUntil: 'networkidle' });
  const light = await page.evaluate(() => ({
    theme: document.documentElement.dataset.theme,
    bg: getComputedStyle(document.body).backgroundColor,
  }));

  await page.evaluate(() => localStorage.setItem('platform.theme', 'dark'));
  await page.reload({ waitUntil: 'networkidle' });
  const dark = await page.evaluate(() => ({
    theme: document.documentElement.dataset.theme,
    bg: getComputedStyle(document.body).backgroundColor,
  }));

  if (light.theme !== 'light' || dark.theme !== 'dark') {
    problems.push(`theme: expected light/dark, got ${light.theme}/${dark.theme}`);
  }
  if (light.bg === dark.bg) {
    problems.push(`theme: light and dark backgrounds are identical (${light.bg})`);
  }

  // Keyboard: Tab from the email field must reach the password field.
  await page.evaluate(() => localStorage.setItem('platform.theme', 'light'));
  await page.reload({ waitUntil: 'networkidle' });
  await page.focus('#login-email');
  await page.keyboard.press('Tab');
  const focusedId = await page.evaluate(() => document.activeElement?.id ?? '');
  if (focusedId !== 'login-password') {
    problems.push(`keyboard: Tab from email focused "${focusedId}", expected "login-password"`);
  }

  // RU rendering.
  await page.evaluate(() => localStorage.setItem('platform.locale', 'ru'));
  await page.reload({ waitUntil: 'networkidle' });
  const title = (await page.textContent('h1'))?.trim() ?? '';
  if (title !== 'Войти') {
    problems.push(`i18n: RU login title was "${title}", expected "Войти"`);
  }

  await context.close();
}

await browser.close();

if (problems.length > 0) {
  console.error('RESPONSIVE CHECKS FAILED:');
  for (const problem of problems) {
    console.error(`  ${problem}`);
  }
  process.exit(1);
}

console.log(
  'RESPONSIVE CHECKS PASSED: routes, guards, overflow (320–1440), console, theme, keyboard, RU.\n' +
    'READ-ONLY SMOKE: no authentication and no data mutations.\n' +
    'NOT_VERIFIED here: authenticated cabinet, account workspace, Security Center, MFA and membership mutations (need an isolated runtime).',
);
