import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync } from 'node:fs';
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
import {
  captureAuthenticated,
  measureAuthenticatedNavigation,
  observeAuthenticated,
} from './frontend09-authenticated-evidence.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const outDir = join(root, 'screenshots');
mkdirSync(outDir, { recursive: true });

const APP = process.env.APP_URL ?? '';
const API = process.env.CONTROL_API_URL ?? '';
const MAILPIT = process.env.MAILPIT_URL ?? '';

const failures = [];
const verified = [];
const check = (condition, message) => {
  if (!condition) failures.push(message);
};
const accountCapture = {
  suite: 'commercial-account',
  mask: [
    '.account-layout__name', '.account-layout__code', '.account-layout__crumb-current',
    '.app-breadcrumbs__current', '.switcher__value', '.details__row:first-child dd',
    '.details__row:nth-last-child(-n+2) dd',
  ],
};

async function assertNoOverflow(page, label) {
  const ok = await page.evaluate(
    () => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1,
  );
  check(ok, `${label}: horizontal overflow`);
}

async function inspectBillingPreferences(page, billingPath) {
  await page.setViewportSize({ width: 1440, height: 900 });
  const legalName = page.locator('#billing-legal-name');
  const country = page.locator('#billing-country');
  await legalName.fill('Unsaved QA billing profile');
  await country.fill('R');
  await page.getByRole('button', { name: 'Save billing profile', exact: true }).press('Enter');
  const englishError = page.getByRole('alert').filter({ hasText: 'Enter a legal name and a two-letter country code.' });
  await englishError.waitFor();
  await page.getByRole('button', { name: 'EN: Change language' }).click();
  await page.waitForFunction(() => document.documentElement.lang === 'ru');
  await page.getByRole('alert').filter({ hasText: 'Укажите юридическое название и двухбуквенный код страны.' }).waitFor();
  await page.getByRole('button', { name: 'RU: Сменить язык' }).click();
  await page.waitForFunction(() => document.documentElement.lang === 'en');
  await englishError.waitFor();
  assert.equal(await legalName.inputValue(), 'Unsaved QA billing profile');
  assert.equal(await country.inputValue(), 'R');
  assert.equal(new URL(page.url()).pathname, billingPath);
  await assertAccountNavigation(page, billingPath);

  for (const [label, theme] of [['Dark theme', 'dark'], ['Light theme', 'light']]) {
    await page.getByRole('button', { name: label, exact: true }).click();
    await page.waitForFunction((expected) => document.documentElement.dataset.theme === expected, theme);
    assert.equal(await legalName.inputValue(), 'Unsaved QA billing profile');
    await englishError.waitFor();
  }
  await assertNoOverflow(page, 'billing draft and error at desktop');
  verified.push('Billing profile retained draft and translated validation across RU/EN and light/dark');
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
    await observeAuthenticated(page);

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
    await captureAuthenticated(page, { ...accountCapture, screen: 'overview' });

    // Members (read-only).
    await page.locator('.account-layout__tabs a', { hasText: 'Members' }).first().click();
    await page.waitForURL(`**/accounts/${accountA}/members`, { timeout: 20_000 });
    await page.waitForSelector('.member__email', { timeout: 20_000 });
    await assertAccountNavigation(page, `/accounts/${accountA}/members`);
    check((await page.locator('.member__email').first().textContent())?.trim() === emailA, 'members list shows the owner email');
    check(await page.getByText('Owner', { exact: true }).first().isVisible(), 'members list shows the role');
    await assertNoOverflow(page, 'members 393');
    await stableScreenshot(page, join(outDir, 'frontend04-members-393-light.png'));

    const invitationsPath = `/accounts/${accountA}/invitations`;
    const billingPath = `/accounts/${accountA}/billing`;
    const tabs = page.locator('.account-layout__tabs');
    await tabs.getByRole('link', { name: 'Invitations', exact: true }).click();
    await page.waitForURL((url) => url.pathname === invitationsPath);
    await page.getByText('No invitations yet.', { exact: true }).waitFor();
    await assertAccountNavigation(page, invitationsPath);
    await assertNoOverflow(page, 'invitations 393');

    // Billing and usage pages are backed by the Commercial API.
    await page.locator('.account-layout__tabs a', { hasText: 'Billing' }).first().click();
    await page.waitForURL(`**/accounts/${accountA}/billing`, { timeout: 20_000 });
    await page.waitForSelector('.billing__title', { timeout: 20_000 });
    await assertAccountNavigation(page, billingPath);
    await page.goBack();
    await page.getByText('No invitations yet.', { exact: true }).waitFor();
    await assertAccountNavigation(page, invitationsPath);
    await page.goForward();
    await page.locator('.billing__title').waitFor();
    await assertAccountNavigation(page, billingPath);
    check(await page.getByText('Billing', { exact: true }).first().isVisible(), 'billing page renders');
    await assertNoOverflow(page, 'billing 393');
    await stableScreenshot(page, join(outDir, 'frontend05-billing-393-light.png'));
    await captureAuthenticated(page, { ...accountCapture, screen: 'billing' });
    await page.getByRole('heading', { name: 'Start subscription', exact: true }).waitFor();
    await page.getByRole('heading', { name: 'Invoices and payment history', exact: true }).waitFor();
    await page.getByRole('heading', { name: 'Billing profile', exact: true }).waitFor();
    await inspectBillingPreferences(page, billingPath);
    await page.getByRole('link', { name: 'Open Security Center', exact: true }).click();
    await page.waitForURL((url) => url.pathname === '/settings/security');
    await page.locator('.security-view__session-list').waitFor();
    await assertPrimaryNavigation(page, '/settings/security');
    await page.goBack();
    await page.waitForURL((url) => url.pathname === billingPath);
    await page.locator('#billing-legal-name').waitFor();
    await assertAccountNavigation(page, billingPath);
    await page.setViewportSize({ width: 393, height: 852 });
    verified.push('Billing nested subscription/invoice/profile sections and Security Center link with Back');

    await page.locator('.account-layout__tabs a', { hasText: 'Usage' }).first().click();
    await page.waitForURL(`**/accounts/${accountA}/usage`, { timeout: 20_000 });
    await page.waitForSelector('.usage__title', { timeout: 20_000 });
    await assertAccountNavigation(page, `/accounts/${accountA}/usage`);
    check(await page.getByText('Usage', { exact: true }).first().isVisible(), 'usage page renders');
    await assertNoOverflow(page, 'usage 393');
    await stableScreenshot(page, join(outDir, 'frontend05-usage-393-light.png'));

    await page.locator('.account-layout__tabs a', { hasText: 'Analytics' }).first().click();
    await page.waitForURL(`**/accounts/${accountA}/analytics`, { timeout: 20_000 });
    await page.waitForSelector('.analytics__title', { timeout: 20_000 });
    await page.locator('.analytics__bar').first().waitFor({ state: 'visible', timeout: 20_000 });
    await assertAccountNavigation(page, `/accounts/${accountA}/analytics`);
    check(await page.getByText('Members', { exact: true }).first().isVisible(), 'analytics page renders');
    check(await page.locator('.analytics__bar').count() > 0, 'analytics activity chart renders');
    await assertNoOverflow(page, 'analytics 393');
    await stableScreenshot(page, join(outDir, 'frontend05-analytics-393-light.png'));

    await page.locator('.account-layout__tabs a', { hasText: 'Audit log' }).first().click();
    await page.waitForURL(`**/accounts/${accountA}/audit`, { timeout: 20_000 });
    await page.waitForSelector('.audit__title', { timeout: 20_000 });
    await page.locator('.audit__event').first().waitFor({ state: 'visible', timeout: 20_000 });
    await assertAccountNavigation(page, `/accounts/${accountA}/audit`);
    check((await page.locator('.audit__event').first().innerText()).includes('commercial_account.created'), 'audit page renders account events');
    await assertNoOverflow(page, 'audit 393');
    await stableScreenshot(page, join(outDir, 'frontend05-audit-393-light.png'));

    const menuTrigger = page.getByRole('button', { name: 'Open menu' });
    await menuTrigger.press('Enter');
    const accountMenu = page.getByRole('dialog', { name: 'Menu' });
    await accountMenu.waitFor();
    check(await accountMenu.getByRole('link', { name: 'Billing', exact: true }).isVisible(), 'mobile menu exposes billing');
    check(await accountMenu.getByRole('link', { name: 'Usage', exact: true }).isVisible(), 'mobile menu exposes usage');
    check(await accountMenu.getByRole('link', { name: 'Analytics', exact: true }).isVisible(), 'mobile menu exposes analytics');
    check(await accountMenu.getByRole('link', { name: 'Audit log', exact: true }).isVisible(), 'mobile menu exposes audit');
    await page.keyboard.press('Escape');
    await accountMenu.waitFor({ state: 'detached' });
    assert.equal(await menuTrigger.evaluate((button) => button === document.activeElement), true, 'Menu Escape restores focus');
    verified.push('Owner account tabs expose Overview/Members/Invitations/Billing/Usage/Analytics/Audit and mark one active indicator');

    await navigateClientSide(page, '/settings/privacy');
    await page.waitForSelector('.privacy__title', { timeout: 20_000 });
    check(await page.getByText('Privacy', { exact: true }).first().isVisible(), 'privacy page renders');
    const downloadPromise = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Download my data' }).click();
    const download = await downloadPromise;
    check((await download.suggestedFilename()).startsWith('platform-personal-data-'), 'personal data export downloads');
    await stableScreenshot(page, join(outDir, 'frontend05-privacy-393-light.png'));
    await navigateClientSide(page, `/accounts/${accountA}`);

    // Account switching.
    await page.getByRole('button', { name: 'Switch account' }).click();
    const switcher = page.locator('[role="dialog"]').filter({ hasText: 'Switch account' });
    await switcher.locator('.picker__item').filter({ hasText: nameB }).click();
    await page.waitForURL(`**/accounts/${accountB}`, { timeout: 20_000 });
    await page.waitForSelector('.account-layout__name', { timeout: 20_000 });
    check((await page.locator('.account-layout__name').textContent())?.includes(nameB), 'switcher opens account B');

    // Keyboard reachability of the switcher.
    const switchButton = page.getByRole('button', { name: `Switch account ${nameB}`, exact: true });
    await switchButton.focus();
    await page.keyboard.press('Tab');
    check(await switchButton.evaluate((button) => document.activeElement !== button), 'Tab must move focus away from the switcher');

    // Unknown account via client-side navigation.
    await navigateClientSide(page, '/accounts/018f0000-0000-7000-8000-0000000000ff');
    await page.waitForSelector('.app-error', { timeout: 20_000 });
    check(
      await page.getByText('This account was not found or you do not have access to it.').first().isVisible(),
      'unknown account shows the not-found/denied state',
    );

    // Direct URL requires re-authentication because tokens are memory-only,
    // then returns to the originally requested account.
    await page.goto(`${APP}${billingPath}`, { waitUntil: 'networkidle' });
    await page.waitForURL('**/login**', { timeout: 20_000 });
    assert.equal(new URL(page.url()).searchParams.get('redirect'), billingPath, 'Nested route survives the login guard');
    await submitLogin(page, emailA, password);
    await page.waitForURL((url) => url.pathname === billingPath, { timeout: 20_000 });
    await page.waitForSelector('.account-layout__name', { timeout: 20_000 });
    await page.locator('#billing-legal-name').waitFor();
    await assertAccountNavigation(page, billingPath);
    check((await page.locator('.account-layout__name').textContent())?.includes(nameA), 'direct URL returns to account A after re-login');
    verified.push('Direct nested billing URL reauthenticates and restores account and active section');
    await tabs.getByRole('link', { name: 'Overview', exact: true }).click();
    await page.waitForURL((url) => url.pathname === `/accounts/${accountA}`);

    // Dark theme from the full-screen mobile menu.
    const themeBefore = await page.evaluate(() => document.documentElement.dataset.theme);
    const themeMenu = page.getByRole('dialog', { name: 'Menu' });
    await page.getByRole('button', { name: 'Open menu' }).click();
    await themeMenu.getByRole('button', { name: 'Dark theme' }).click();
    await page.waitForFunction((previous) => document.documentElement.dataset.theme !== previous, themeBefore);
    const themeAfter = await page.evaluate(() => document.documentElement.dataset.theme);
    check(themeAfter !== themeBefore, 'theme toggle changes data-theme');
    assert.equal(await themeMenu.isVisible(), true, 'Theme transition preserves the mobile menu dialog');
    await page.keyboard.press('Escape');
    await themeMenu.waitFor({ state: 'detached', timeout: 2_000 });
    await stableScreenshot(page, join(outDir, 'frontend04-overview-393-dark.png'));
    await captureAuthenticated(page, { ...accountCapture, screen: 'overview' });
    await page.getByRole('button', { name: 'Open menu' }).click();
    await themeMenu.getByRole('button', { name: 'Light theme' }).click();
    await page.waitForFunction((previous) => document.documentElement.dataset.theme === previous, themeBefore);
    await page.keyboard.press('Escape');
    await themeMenu.waitFor({ state: 'detached', timeout: 2_000 });

    // Mobile boundary and desktop layout.
    await page.setViewportSize({ width: 320, height: 740 });
    await assertNoOverflow(page, 'overview 320');
    await page.setViewportSize({ width: 1440, height: 900 });
    await stableScreenshot(page, join(outDir, 'frontend04-overview-1440-light.png'));
    await assertNoOverflow(page, 'overview 1440');
    await page.getByRole('button', { name: 'EN: Change language' }).click();
    await page.waitForFunction(() => document.documentElement.lang === 'ru');
    await assertAccountNavigation(page, `/accounts/${accountA}`);
    check(await tabs.getByRole('link', { name: 'Выставление счетов', exact: true }).isVisible(), 'account tabs translate while keeping current section');
    await captureAuthenticated(page, { ...accountCapture, screen: 'overview' });
    await page.getByRole('button', { name: 'RU: Сменить язык' }).click();
    await page.waitForFunction(() => document.documentElement.lang === 'en');
    const sizes = [[320, 740], [393, 852], [430, 932], [768, 1024], [1024, 768], [1440, 900], [1920, 1080], [844, 390]];
    for (const [width, height] of sizes) {
      await page.setViewportSize({ width, height });
      await assertNoOverflow(page, `overview ${width}×${height}`);
    }
    await measureAuthenticatedNavigation(page, {
      suite: accountCapture.suite,
      targets: [
        { path: `/accounts/${accountA}`, locator: () => tabs.getByRole('link', { name: 'Overview', exact: true }), ready: () => page.locator('.overview') },
        { path: invitationsPath, locator: () => tabs.getByRole('link', { name: 'Invitations', exact: true }), ready: () => page.getByText('No invitations yet.', { exact: true }) },
        { path: billingPath, locator: () => tabs.getByRole('link', { name: 'Billing', exact: true }), ready: () => page.locator('#billing-legal-name') },
      ],
    });
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
      verified.push('Unrelated user denied access to the account and its identity data');
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

writeFileSync(join(outDir, 'frontend09-account-workspace-closeout.json'), JSON.stringify({
  passed: true,
  realBackend: true,
  scenarios: verified,
}, null, 2));

console.log(
  'ACCOUNT WORKSPACE E2E PASSED: login, create accounts, overview, members, invitations, billing, usage, analytics, audit, Back/Forward, switching, direct URL re-login, RU/EN, theme, 320/1440, keyboard, cross-user denial.',
);
