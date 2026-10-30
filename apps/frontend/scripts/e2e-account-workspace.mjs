import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const outDir = join(root, 'screenshots');
mkdirSync(outDir, { recursive: true });

const APP = process.env.APP_URL ?? 'http://localhost:5173';
const API = process.env.CONTROL_API_URL ?? 'http://localhost:8090';
const MAILPIT = process.env.MAILPIT_URL ?? 'http://localhost:8025';

const failures = [];
const check = (condition, message) => {
  if (!condition) failures.push(message);
};

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

async function waitForVerificationToken(email) {
  const deadline = Date.now() + 60_000;
  while (Date.now() < deadline) {
    const list = await (await fetch(`${MAILPIT}/api/v1/messages?limit=100`)).json();
    for (const message of list.messages ?? []) {
      if (!JSON.stringify(message.To ?? '').includes(email)) continue;
      const full = await (await fetch(`${MAILPIT}/api/v1/message/${message.ID}`)).json();
      const blob = `${full.Text ?? ''}\n${full.HTML ?? ''}`;
      const match = /verify-email\?token=([^\s"'<>&]+)/.exec(blob);
      if (match) return decodeURIComponent(match[1]);
    }
    await new Promise((resolve) => setTimeout(resolve, 2000));
  }
  throw new Error(`Verification email for ${email} did not arrive.`);
}

async function registerAndVerify(email, password) {
  const registered = await fetch(`${API}/api/control/auth/register`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password }),
  });
  if (registered.status !== 202) {
    throw new Error(`Register ${email} failed: HTTP ${registered.status}`);
  }
  const token = await waitForVerificationToken(email);
  const verified = await fetch(`${API}/api/control/auth/verify`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token }),
  });
  if (verified.status !== 200) {
    throw new Error(`Verify ${email} failed: HTTP ${verified.status}`);
  }
}

async function submitLogin(page, email, password) {
  await page.fill('#login-email', email);
  await page.fill('#login-password', password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await page.waitForURL((url) => url.pathname !== '/login', { timeout: 20_000 });
}

async function createAccount(page, code, name) {
  await page.locator('button', { hasText: 'Create account' }).first().click();
  const dialog = page.locator('[role="dialog"]');
  await dialog.waitFor({ state: 'visible' });
  await dialog.locator('#account-code').fill(code);
  await dialog.locator('#account-name').fill(name);
  await dialog.locator('button', { hasText: 'Create account' }).first().click();
  await dialog.waitFor({ state: 'hidden', timeout: 20_000 });

  const link = page.locator('a[href^="/accounts/"]').filter({ hasText: name }).first();
  await link.waitFor({ state: 'visible', timeout: 20_000 });
  const href = await link.getAttribute('href');
  if (href === null) {
    throw new Error(`Account link for ${name} has no href.`);
  }
  return href.split('/').pop();
}

async function navigateClientSide(page, path) {
  await page.evaluate((target) => {
    window.history.pushState({}, '', target);
    window.dispatchEvent(new PopStateEvent('popstate'));
  }, path);
}

async function assertNoOverflow(page, label) {
  const ok = await page.evaluate(
    () => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1,
  );
  check(ok, `${label}: horizontal overflow`);
}

async function main() {
  const runId = Date.now().toString(36);
  const emailA = process.env.E2E_EMAIL_A ?? `e2e-a-${runId}@example.test`;
  const emailB = process.env.E2E_EMAIL_B ?? `e2e-b-${runId}@example.test`;
  const password = process.env.E2E_PASSWORD ?? 'correct horse battery staple';
  const codeA = `e2e-a-${runId}`;
  const codeB = `e2e-b-${runId}`;
  const nameA = `E2E A ${runId}`;
  const nameB = `E2E B ${runId}`;

  if (process.env.E2E_EMAIL_A === undefined) {
    await registerAndVerify(emailA, password);
  }
  if (process.env.E2E_EMAIL_B === undefined) {
    await registerAndVerify(emailB, password);
  }

  const browser = await launch();
  const pageErrors = [];
  let accountAId = '';

  const context = await browser.newContext({ viewport: { width: 393, height: 852 } });
  await context.addInitScript(() => {
    localStorage.setItem('platform.locale', 'en');
    localStorage.setItem('platform.theme', 'light');
  });
  const page = await context.newPage();
  page.on('pageerror', (error) => pageErrors.push(String(error)));
  page.on('console', (message) => {
    if (message.type() !== 'error') return;
    const url = message.location().url ?? '';
    if (url.includes('/api/') || url.endsWith('/favicon.ico') || url.endsWith('/favicon.svg')) return;
    pageErrors.push(`${message.text()} (${url})`);
  });

  try {
    await page.goto(`${APP}/login`, { waitUntil: 'networkidle' });
    await submitLogin(page, emailA, password);
    await page.waitForURL('**/cabinet', { timeout: 20_000 });

    const accountA = await createAccount(page, codeA, nameA);
    accountAId = accountA;
    const accountB = await createAccount(page, codeB, nameB);
    check(Boolean(accountA) && Boolean(accountB), 'both accounts must be created');

    // Client-side navigation to the account (no reload: tokens live in memory).
    await page.locator('a[href^="/accounts/"]').filter({ hasText: nameA }).first().click();
    await page.waitForURL(`**/accounts/${accountA}`, { timeout: 20_000 });
    await page.waitForSelector('.account-layout__name', { timeout: 20_000 });
    check((await page.locator('.account-layout__name').textContent())?.includes(nameA), 'overview shows account A name');
    check(await page.getByText('Owner', { exact: true }).first().isVisible(), 'overview shows the current role');
    check(await page.getByText('Draft', { exact: true }).first().isVisible(), 'overview shows the account status');
    await assertNoOverflow(page, 'overview 393');
    await page.screenshot({ path: join(outDir, 'frontend04-overview-393-light.png') });

    // Members (read-only).
    await page.locator('.account-layout__tabs a', { hasText: 'Members' }).first().click();
    await page.waitForURL(`**/accounts/${accountA}/members`, { timeout: 20_000 });
    await page.waitForSelector('.member__email', { timeout: 20_000 });
    check((await page.locator('.member__email').first().textContent())?.trim() === emailA, 'members list shows the owner email');
    check(await page.getByText('Owner', { exact: true }).first().isVisible(), 'members list shows the role');
    await assertNoOverflow(page, 'members 393');
    await page.screenshot({ path: join(outDir, 'frontend04-members-393-light.png') });

    // Account switching.
    await page.selectOption('.switcher__select', { label: nameB });
    await page.waitForURL(`**/accounts/${accountB}`, { timeout: 20_000 });
    await page.waitForSelector('.account-layout__name', { timeout: 20_000 });
    check((await page.locator('.account-layout__name').textContent())?.includes(nameB), 'switcher opens account B');

    // Keyboard reachability of the switcher.
    await page.focus('.switcher__select');
    await page.keyboard.press('Tab');
    const activeTag = await page.evaluate(() => document.activeElement?.tagName ?? '');
    check(activeTag !== 'SELECT', 'Tab must move focus away from the switcher');

    // Unknown account via client-side navigation.
    await navigateClientSide(page, '/accounts/018f0000-0000-7000-8000-0000000000ff');
    await page.waitForSelector('.app-error', { timeout: 20_000 });
    check(
      await page.getByText('This account was not found or you do not have access to it.').first().isVisible(),
      'unknown account shows the not-found/denied state',
    );

    // Direct URL requires re-authentication because tokens are memory-only,
    // then returns to the originally requested account.
    await page.goto(`${APP}/accounts/${accountA}`, { waitUntil: 'networkidle' });
    await page.waitForURL('**/login**', { timeout: 20_000 });
    await submitLogin(page, emailA, password);
    await page.waitForURL(`**/accounts/${accountA}`, { timeout: 20_000 });
    await page.waitForSelector('.account-layout__name', { timeout: 20_000 });
    check((await page.locator('.account-layout__name').textContent())?.includes(nameA), 'direct URL returns to account A after re-login');

    // Dark theme.
    const themeBefore = await page.evaluate(() => document.documentElement.dataset.theme);
    await page.locator('header button').first().click();
    await page.waitForFunction((previous) => document.documentElement.dataset.theme !== previous, themeBefore);
    const themeAfter = await page.evaluate(() => document.documentElement.dataset.theme);
    check(themeAfter !== themeBefore, 'theme toggle changes data-theme');
    await page.screenshot({ path: join(outDir, 'frontend04-overview-393-dark.png') });
    await page.locator('header button').first().click();

    // Mobile boundary and desktop layout.
    await page.setViewportSize({ width: 320, height: 720 });
    await assertNoOverflow(page, 'overview 320');
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.screenshot({ path: join(outDir, 'frontend04-overview-1440-light.png') });
    await assertNoOverflow(page, 'overview 1440');
    await page.setViewportSize({ width: 393, height: 852 });
  } catch (error) {
    failures.push(`main flow: ${String(error)}`);
  }

  // Cross-user isolation with a separate session.
  const contextB = await browser.newContext({ viewport: { width: 393, height: 852 } });
  await contextB.addInitScript(() => {
    localStorage.setItem('platform.locale', 'en');
    localStorage.setItem('platform.theme', 'light');
  });
  const pageB = await contextB.newPage();
  const pageBErrors = [];
  pageB.on('pageerror', (error) => pageBErrors.push(String(error)));
  try {
    await pageB.goto(`${APP}/login`, { waitUntil: 'networkidle' });
    await submitLogin(pageB, emailB, password);
    await pageB.waitForURL('**/cabinet', { timeout: 20_000 });
    if (accountAId !== '') {
      await navigateClientSide(pageB, `/accounts/${accountAId}`);
      await pageB.waitForSelector('.app-error', { timeout: 20_000 });
      check(
        await pageB.getByText('This account was not found or you do not have access to it.').first().isVisible(),
        'user B must be denied access to account A',
      );
      check(
        (await pageB.locator('.account-layout__name').count()) === 0,
        'user B must not see account A data',
      );
    }
  } catch (error) {
    failures.push(`user B isolation: ${String(error)}`);
  }
  if (pageBErrors.length > 0) {
    failures.push(`user B browser errors: ${pageBErrors.join(' | ')}`);
  }
  await contextB.close();

  if (pageErrors.length > 0) {
    failures.push(`browser errors: ${pageErrors.join(' | ')}`);
  }

  await context.close();
  await browser.close();
}

await main();

if (failures.length > 0) {
  console.error('ACCOUNT WORKSPACE E2E FAILED:');
  for (const failure of failures) {
    console.error(`  ${failure}`);
  }
  process.exit(1);
}

console.log(
  'ACCOUNT WORKSPACE E2E PASSED: login, create accounts, overview, members, switching, unknown account, direct URL re-login, theme, 320/1440, keyboard, cross-user denial.',
);
