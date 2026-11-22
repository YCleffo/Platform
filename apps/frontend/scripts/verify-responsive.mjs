import { chromium } from 'playwright';

const BASE = process.env.BASE_URL ?? 'http://localhost:5173';
const viewports = [
  [320, 740], [360, 800], [375, 812], [393, 852], [430, 932], [768, 1024],
  [844, 390], [1024, 768], [1280, 800], [1440, 900], [1920, 1080],
].map(([width, height]) => ({ width, height }));

// No backend is required: these are static/guard states of the SPA.
const routes = [
  { path: '/login', expected: '/login', selector: 'form' },
  { path: '/admin/login', expected: '/admin/login', selector: 'form' },
  { path: '/admin', expected: '/admin/login', selector: 'form' },
  { path: '/register', expected: '/register', selector: 'form' },
  { path: '/verify-email', expected: '/verify-email', selector: '.verify-view' },
  { path: '/resend-verification', expected: '/resend-verification', selector: 'form' },
  { path: '/forgot-password', expected: '/forgot-password', selector: 'form' },
  { path: '/reset-password', expected: '/reset-password', selector: '.app-form-shell' },
  { path: '/reset-password?token=synthetic', expected: '/reset-password', selector: 'form' },
  { path: '/does-not-exist', expected: '/does-not-exist', selector: '.not-found' },
  { path: '/', expected: '/', selector: '.catalog' },
  { path: '/plans', expected: '/plans', selector: '.plans' },
  { path: '/plans/business', expected: '/plans/business', selector: '.plan-detail' },
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
const widthConcurrency = Math.max(1, Number.parseInt(process.env.RESPONSIVE_CONCURRENCY ?? '3', 10) || 3);

async function verifyWidth(viewport) {
  const { width, height } = viewport;
  const context = await browser.newContext({ viewport });
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
    if (url.includes('/api/') || url.includes('local.adguard.org') || url.endsWith('/favicon.ico') || url.endsWith('/favicon.svg')) {
      return;
    }
    pageErrors.push(`${message.text()} (${url})`);
  });

  for (const route of routes) {
    const label = `${width}×${height} ${route.path}`;
    try {
      await page.goto(`${BASE}${route.path}`, { waitUntil: 'domcontentloaded', timeout: 15000 });
      await page.locator(route.selector).first().waitFor({ timeout: 5000 });
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
    problems.push(`${width}×${height}: console/page errors -> ${pageErrors.join(' | ')}`);
  }
  await context.close();
}

async function mapLimit(items, limit, worker) {
  const queue = [...items];
  await Promise.all(
    Array.from({ length: Math.min(limit, queue.length) }, async () => {
      for (;;) {
        const item = queue.shift();
        if (item === undefined) return;
        await worker(item);
      }
    }),
  );
}

const navigationOnly = process.argv.includes('--navigation-only');
if (!navigationOnly) await mapLimit(viewports, widthConcurrency, verifyWidth);

// Theme + keyboard + localization checks (mobile reference width).
if (!navigationOnly) {
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

async function verifyNavigation() {
  const context = await browser.newContext({
    viewport: { width: 393, height: 852 },
    isMobile: true,
    hasTouch: true,
    reducedMotion: 'reduce',
  });
  try {
    await context.addInitScript(() => { localStorage.setItem('platform.locale', 'en'); });
    const page = await context.newPage();
    const cdp = await context.newCDPSession(page);
    async function swipe(x, y, targetX, targetY = y) {
      await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y }] });
      for (const progress of [.25, .5, .75, 1]) {
        await cdp.send('Input.dispatchTouchEvent', {
          type: 'touchMove',
          touchPoints: [{ x: x + (targetX - x) * progress, y: y + (targetY - y) * progress }],
        });
      }
      await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    }
    await page.route('**/api/control/catalog/plans', (route) => route.fulfill({
      json: {
        plans: [{
          id: '018f0000-0000-7000-8000-000000000021', code: 'starter', version: 1, name: 'Starter',
          prices: [{ id: '018f0000-0000-7000-8000-000000000022', currency: 'RUB', amount_minor: 99000, billing_period: 'month' }],
          features: { seats: 3, analytics: false, sso: false, support: 'email' },
        }],
      },
    }));
    await page.goto(`${BASE}/plans`, { waitUntil: 'domcontentloaded' });
    const heading = page.locator('.plans__title');
    await heading.waitFor();
    const activeCatalog = page.locator('.app-bottom-nav:visible a[aria-current="page"][href="/"]');
    await activeCatalog.waitFor();
    const menuButton = page.getByRole('button', { name: 'Open menu', exact: true });
    await menuButton.click();
    const menu = page.getByRole('dialog', { name: 'Menu', exact: true });
    await menu.getByRole('navigation', { name: 'Main' }).locator('a[href="/"][aria-current="page"]').waitFor();
    await page.keyboard.press('Escape');
    await menu.waitFor({ state: 'hidden' });
    if (!(await menuButton.evaluate((element) => element === document.activeElement))) {
      throw new Error('Mobile navigation menu did not restore focus');
    }
    await page.setViewportSize({ width: 844, height: 390 });
    await page.locator('.app-layout__nav:visible a[aria-current="page"][href="/"]').waitFor();
    await page.locator('.app-bottom-nav').waitFor({ state: 'hidden' });
    await page.setViewportSize({ width: 393, height: 852 });

    await page.locator('.app-bottom-nav a[href="/settings"]').tap();
    await page.waitForURL((url) => url.pathname === '/settings');
    const slider = page.locator('input[type="range"]').first();
    await slider.scrollIntoViewIfNeeded();
    const { x, y, width, height } = await slider.boundingBox();
    await swipe(x + width * .25, y + height / 2, x + width * .75);
    if (new URL(page.url()).pathname !== '/settings') throw new Error('Slider drag changed section');

    await page.goto(`${BASE}/plans`, { waitUntil: 'domcontentloaded' });
    await heading.waitFor();
    const tableScroll = page.locator('.plans__scroll');
    const caption = tableScroll.locator('caption');
    await caption.scrollIntoViewIfNeeded();
    const tableBounds = await tableScroll.boundingBox();
    const captionBounds = await caption.boundingBox();
    await swipe(tableBounds.x + tableBounds.width - 48, captionBounds.y + captionBounds.height / 2,
      tableBounds.x + 48);
    await page.waitForFunction(() => document.querySelector('.plans__scroll')?.scrollLeft > 0);
    if (new URL(page.url()).pathname !== '/plans') throw new Error('Horizontal table drag changed section');
    await heading.scrollIntoViewIfNeeded();
    const title = await heading.boundingBox();
    const touchY = title.y + title.height / 2;
    await swipe(380, touchY, 220);
    await swipe(260, touchY, 240, touchY + 95);
    if (new URL(page.url()).pathname !== '/plans') throw new Error('Edge or vertical swipe changed section');

    await swipe(220, touchY, 110);
    await page.waitForURL((url) => url.pathname === '/login' && url.searchParams.get('redirect') === '/cabinet');
    await page.goBack({ waitUntil: 'domcontentloaded' });
    await page.waitForURL((url) => url.pathname === '/plans');
    await activeCatalog.waitFor();
  } finally {
    await context.close();
  }
}

try {
  await verifyNavigation();
} catch (error) {
  problems.push(`mobile navigation: ${String(error)}`);
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
  (navigationOnly
    ? 'NAVIGATION CHECKS PASSED: mobile and desktop active links, menu focus, slider and touch gestures.\n'
    : 'RESPONSIVE CHECKS PASSED: routes, guards, overflow (320–1920), console, theme, keyboard, RU and navigation.\n') +
    'READ-ONLY SMOKE: no authentication and no data mutations.\n' +
    'NOT_VERIFIED here: authenticated cabinet, account workspace, Security Center, MFA and membership mutations (need an isolated runtime).',
);
