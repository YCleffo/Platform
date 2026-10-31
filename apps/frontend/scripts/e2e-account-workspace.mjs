import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { evaluateMutationGate } from './e2e-guard.mjs';
import {
  assertAccountNavigation,
  assertPrimaryNavigation,
  createCommercialAccount,
  launchBrowser,
  navigateClientSide,
  registerAndVerify,
  submitLogin,
  stableScreenshot,
  verifySecuritySession,
} from './e2e-support.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const outDir = join(root, 'screenshots');
mkdirSync(outDir, { recursive: true });

const APP = process.env.APP_URL ?? '';
const API = process.env.CONTROL_API_URL ?? '';
const MAILPIT = process.env.MAILPIT_URL ?? '';

const failures = [];
const check = (condition, message) => {
  if (!condition) failures.push(message);
};

async function assertNoOverflow(page, label) {
  const ok = await page.evaluate(
    () => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1,
  );
  check(ok, `${label}: horizontal overflow`);
}

async function main() {
  const gate = await evaluateMutationGate(process.env);
  if (!gate.allowed) {
    console.error('NOT_VERIFIED: mutating account-workspace E2E refused (no approved isolated runtime).');
    for (const reason of gate.reasons) {
      console.error(`  - ${reason}`);
    }
    console.error('No users, accounts or memberships were created.');
    process.exit(2);
  }

  const runId = Date.now().toString(36);
  const emailA = process.env.E2E_EMAIL_A ?? `e2e-a-${runId}@example.test`;
  const emailB = process.env.E2E_EMAIL_B ?? `e2e-b-${runId}@example.test`;
  const password = process.env.E2E_PASSWORD;
  const codeA = `e2e-a-${runId}`;
  const codeB = `e2e-b-${runId}`;
  const nameA = `E2E A ${runId}`;
  const nameB = `E2E B ${runId}`;

  if (process.env.E2E_EMAIL_A === undefined) {
    await registerAndVerify({ api: API, mailpit: MAILPIT, email: emailA, password });
  }
  if (process.env.E2E_EMAIL_B === undefined) {
    await registerAndVerify({ api: API, mailpit: MAILPIT, email: emailB, password });
  }

  const browser = await launchBrowser();
  const pageErrors = [];
  let accountAId = '';

  const context = await browser.newContext({ viewport: { width: 393, height: 852 }, ignoreHTTPSErrors: true });
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

    await assertPrimaryNavigation(page, '/cabinet');
    await navigateClientSide(page, '/');
    await page.getByRole('heading', { level: 1 }).waitFor();
    await assertPrimaryNavigation(page, '/');
    await verifySecuritySession(page);
    await navigateClientSide(page, '/cabinet');
    await page.locator('button', { hasText: 'Create account' }).first().waitFor();
    await assertPrimaryNavigation(page, '/cabinet');

    const accountA = await createCommercialAccount(page, codeA, nameA);
    accountAId = accountA;
    const accountB = await createCommercialAccount(page, codeB, nameB);
    check(Boolean(accountA) && Boolean(accountB), 'both accounts must be created');

    // Client-side navigation to the account (no reload: tokens live in memory).
    await page.locator('a[href^="/accounts/"]').filter({ hasText: nameA }).first().click();
    await page.waitForURL(`**/accounts/${accountA}`, { timeout: 20_000 });
    await page.waitForSelector('.account-layout__name', { timeout: 20_000 });
    await assertAccountNavigation(page, `/accounts/${accountA}`);
    check((await page.locator('.account-layout__name').textContent())?.includes(nameA), 'overview shows account A name');
    check(await page.getByText('Owner', { exact: true }).first().isVisible(), 'overview shows the current role');
    check(await page.getByText('Draft', { exact: true }).first().isVisible(), 'overview shows the account status');
    await assertNoOverflow(page, 'overview 393');
    await stableScreenshot(page, join(outDir, 'frontend04-overview-393-light.png'));

    // Members (read-only).
    await page.locator('.account-layout__tabs a', { hasText: 'Members' }).first().click();
    await page.waitForURL(`**/accounts/${accountA}/members`, { timeout: 20_000 });
    await page.waitForSelector('.member__email', { timeout: 20_000 });
    await assertAccountNavigation(page, `/accounts/${accountA}/members`);
    check((await page.locator('.member__email').first().textContent())?.trim() === emailA, 'members list shows the owner email');
    check(await page.getByText('Owner', { exact: true }).first().isVisible(), 'members list shows the role');
    await assertNoOverflow(page, 'members 393');
    await stableScreenshot(page, join(outDir, 'frontend04-members-393-light.png'));

    // Account switching.
    await page.getByRole('button', { name: 'Switch account' }).click();
    const switcher = page.locator('[role="dialog"]').filter({ hasText: 'Switch account' });
    await switcher.locator('.picker__item').filter({ hasText: nameB }).click();
    await page.waitForURL(`**/accounts/${accountB}`, { timeout: 20_000 });
    await page.waitForSelector('.account-layout__name', { timeout: 20_000 });
    check((await page.locator('.account-layout__name').textContent())?.includes(nameB), 'switcher opens account B');

    // Keyboard reachability of the switcher.
    await page.getByRole('button', { name: 'Switch account' }).focus();
    await page.keyboard.press('Tab');
    const activeLabel = await page.evaluate(() => document.activeElement?.getAttribute('aria-label') ?? '');
    check(activeLabel !== 'Switch account', 'Tab must move focus away from the switcher');

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
    await stableScreenshot(page, join(outDir, 'frontend04-overview-393-dark.png'));
    await page.locator('header button').first().click();

    // Mobile boundary and desktop layout.
    await page.setViewportSize({ width: 320, height: 720 });
    await assertNoOverflow(page, 'overview 320');
    await page.setViewportSize({ width: 1440, height: 900 });
    await stableScreenshot(page, join(outDir, 'frontend04-overview-1440-light.png'));
    await assertNoOverflow(page, 'overview 1440');
    await page.setViewportSize({ width: 393, height: 852 });
  } catch (error) {
    failures.push(`main flow: ${String(error)}`);
  }

  // Cross-user isolation with a separate session.
  const contextB = await browser.newContext({ viewport: { width: 393, height: 852 }, ignoreHTTPSErrors: true });
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

if (process.argv.includes('--check-guard')) {
  const gate = await evaluateMutationGate(process.env, { verifyRuntime: false });
  if (gate.allowed) {
    console.error('E2E GUARD FAILED: mutating run would be allowed without explicit isolated markers.');
    process.exit(1);
  }
  console.log('E2E GUARD OK: mutating run is refused without an approved isolated runtime.');
  for (const reason of gate.reasons) {
    console.log(`  - ${reason}`);
  }
  process.exit(0);
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
