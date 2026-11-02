import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { evaluateMutationGate } from './e2e-guard.mjs';
import {
  launchBrowser,
  registerAndVerify,
  waitForMailToken,
  waitForVerificationToken,
} from './e2e-support.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const outDir = join(root, 'screenshots');
mkdirSync(outDir, { recursive: true });

const APP = process.env.APP_URL ?? '';
const API = process.env.CONTROL_API_URL ?? '';
const MAILPIT = process.env.MAILPIT_URL ?? '';
const PASSWORD = process.env.E2E_PASSWORD ?? '';
const NEW_PASSWORD = `${PASSWORD}-reset`;
const failures = [];
const check = (condition, message) => {
  if (!condition) failures.push(message);
};

async function registerPending(email) {
  const response = await fetch(`${API}/api/control/auth/register`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password: PASSWORD }),
  });
  if (response.status !== 202) throw new Error(`Register ${email} failed: HTTP ${response.status}`);
}

async function loginStatus(email, password) {
  const response = await fetch(`${API}/api/control/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password }),
  });
  return response.status;
}

async function verifyStatus(token) {
  const response = await fetch(`${API}/api/control/auth/verify`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token }),
  });
  return response.status;
}

async function fillResetForm(page, password) {
  await page.locator('#reset-password').fill(password);
  await page.locator('#reset-password-confirm').fill(password);
  await page.getByRole('button', { name: 'Update password' }).click();
}

async function main() {
  const gate = await evaluateMutationGate(process.env);
  if (!gate.allowed) {
    console.error('NOT_VERIFIED: account recovery browser E2E refused (no approved isolated runtime).');
    for (const reason of gate.reasons) console.error(`  - ${reason}`);
    process.exit(2);
  }

  const runId = `${Date.now().toString(36)}-${process.pid}`;
  const resendEmail = `e2e-resend-${runId}@example.test`;
  const resetEmail = `e2e-reset-${runId}@example.test`;
  const oldPassword = `${PASSWORD}-old`;
  const browser = await launchBrowser();
  const pageErrors = [];
  const context = await browser.newContext({ viewport: { width: 393, height: 852 }, ignoreHTTPSErrors: true });
  await context.addInitScript(() => localStorage.setItem('platform.locale', 'en'));

  try {
    await registerPending(resendEmail);
    const initialToken = await waitForVerificationToken(MAILPIT, resendEmail);

    const resendPage = await context.newPage();
    resendPage.on('pageerror', error => pageErrors.push(String(error)));
    await resendPage.goto(`${APP}/verify-email?token=invalid-token`, { waitUntil: 'networkidle' });
    await resendPage.getByRole('heading', { name: 'Could not confirm email' }).waitFor({ timeout: 20_000 });
    await resendPage.getByRole('link', { name: 'Resend confirmation' }).click();
    await resendPage.waitForURL('**/resend-verification', { timeout: 20_000 });
    await resendPage.getByRole('heading', { name: 'Resend confirmation email' }).waitFor({ timeout: 20_000 });
    await resendPage.locator('#resend-email').fill(resendEmail);
    await resendPage.getByRole('button', { name: 'Send confirmation email' }).click();
    await resendPage.getByRole('heading', { name: 'Check your email' }).waitFor({ timeout: 20_000 });
    check((await resendPage.locator('.app-form-shell__subtitle').textContent())?.includes('awaiting confirmation'), 'resend acknowledgement is generic');
    const resentToken = await waitForMailToken(MAILPIT, resendEmail, {
      exclude: initialToken,
      pattern: /verify-email\?token=([^\s"'<>&]+)/,
      timeout: 60_000,
    });
    check(resentToken !== initialToken, 'resend rotates the verification token');
    check(await verifyStatus(initialToken) === 400, 'the superseded verification token is rejected');
    check(await verifyStatus(resentToken) === 200, 'the resent verification token works');
    await resendPage.close();

    await registerAndVerify({ api: API, mailpit: MAILPIT, email: resetEmail, password: oldPassword });

    const requestPage = await context.newPage();
    requestPage.on('pageerror', error => pageErrors.push(String(error)));
    await requestPage.goto(`${APP}/forgot-password`, { waitUntil: 'networkidle' });
    await requestPage.getByRole('heading', { name: 'Reset your password' }).waitFor({ timeout: 20_000 });
    await requestPage.locator('#reset-request-email').fill(resetEmail);
    await requestPage.getByRole('button', { name: 'Send reset link' }).click();
    await requestPage.getByRole('heading', { name: 'Check your email' }).waitFor({ timeout: 20_000 });
    check((await requestPage.locator('.app-form-shell__subtitle').textContent())?.includes('If an account exists'), 'reset request acknowledgement is generic');
    const resetToken = await waitForMailToken(MAILPIT, resetEmail, {
      pattern: /reset-password\?token=([^\s"'<>&]+)/,
      timeout: 60_000,
    });
    await requestPage.close();

    const confirmPage = await context.newPage();
    confirmPage.on('pageerror', error => pageErrors.push(String(error)));
    await confirmPage.goto(`${APP}/reset-password?token=${encodeURIComponent(resetToken)}`, { waitUntil: 'networkidle' });
    await confirmPage.getByRole('heading', { name: 'Choose a new password' }).waitFor({ timeout: 20_000 });
    const tokenStored = await confirmPage.evaluate(token => [localStorage, sessionStorage].some(storage =>
      Object.keys(storage).some(key => `${key}=${storage.getItem(key)}`.includes(token))), resetToken);
    check(!tokenStored, 'the reset token is not written to browser storage');
    await fillResetForm(confirmPage, NEW_PASSWORD);
    await confirmPage.waitForURL(url => url.pathname === '/login' && url.searchParams.get('reset') === 'success', { timeout: 20_000 });
    await confirmPage.getByText('Your password has been updated. Sign in with your new password.').waitFor({ timeout: 20_000 });
    check(!confirmPage.url().includes(resetToken), 'the reset token is removed from the URL after success');
    check(await loginStatus(resetEmail, oldPassword) === 401, 'the old password is rejected after reset');
    check(await loginStatus(resetEmail, NEW_PASSWORD) === 200, 'the new password works after reset');
    await confirmPage.close();

    const reusePage = await context.newPage();
    reusePage.on('pageerror', error => pageErrors.push(String(error)));
    await reusePage.goto(`${APP}/reset-password?token=${encodeURIComponent(resetToken)}`, { waitUntil: 'networkidle' });
    await reusePage.getByRole('heading', { name: 'Choose a new password' }).waitFor({ timeout: 20_000 });
    await fillResetForm(reusePage, `${NEW_PASSWORD}-again`);
    await reusePage.getByText('This reset link is invalid, expired, or already used. Request a new link.').waitFor({ timeout: 20_000 });
    check((await reusePage.locator('main').count()) > 0, 'a used reset token shows a recoverable page');
    await reusePage.close();

    const invalidPage = await context.newPage();
    invalidPage.on('pageerror', error => pageErrors.push(String(error)));
    await invalidPage.goto(`${APP}/reset-password?token=invalid-token`, { waitUntil: 'networkidle' });
    await invalidPage.getByRole('heading', { name: 'Choose a new password' }).waitFor({ timeout: 20_000 });
    await fillResetForm(invalidPage, `${NEW_PASSWORD}-invalid`);
    await invalidPage.getByText('This reset link is invalid, expired, or already used. Request a new link.').waitFor({ timeout: 20_000 });
    check((await invalidPage.locator('main').count()) > 0, 'an invalid reset token shows a recoverable page');
    await invalidPage.close();

    const missingPage = await context.newPage();
    missingPage.on('pageerror', error => pageErrors.push(String(error)));
    await missingPage.goto(`${APP}/reset-password`, { waitUntil: 'networkidle' });
    await missingPage.getByRole('heading', { name: 'Could not reset password' }).waitFor({ timeout: 20_000 });
    check(await missingPage.getByRole('link', { name: 'Request a new link' }).isVisible(), 'a missing token offers a new reset link');
    await missingPage.close();

    const latePage = await context.newPage();
    latePage.on('pageerror', error => pageErrors.push(String(error)));
    await latePage.route('**/api/control/auth/login', async route => {
      try {
        const response = await route.fetch();
        await new Promise(resolve => setTimeout(resolve, 1_500));
        await route.fulfill({ response });
      } catch {
        // The abandoned login request was aborted by the page lifecycle.
      }
    });
    await latePage.goto(`${APP}/login`, { waitUntil: 'networkidle' });
    await latePage.locator('#login-email').fill(resetEmail);
    await latePage.locator('#login-password').fill(NEW_PASSWORD);
    const startedLogin = latePage.waitForRequest('**/api/control/auth/login', { timeout: 10_000 });
    await latePage.getByRole('button', { name: 'Sign in', exact: true }).click();
    await startedLogin;
    await latePage.getByRole('link', { name: 'Sign up', exact: true }).click();
    await latePage.waitForURL('**/register', { timeout: 20_000 });
    await latePage.waitForTimeout(2_000);
    check(new URL(latePage.url()).pathname === '/register', 'a late login response does not leave the new page');
    check(await latePage.locator('#register-email').isVisible(), 'the newer registration form remains intact');
    await latePage.close();
  } catch (error) {
    failures.push(`account recovery flow: ${String(error)}`);
    for (const [index, page] of context.pages().entries()) {
      const path = new URL(page.url()).pathname;
      const text = await page.locator('main').innerText().catch(() => 'Main content unavailable');
      failures.push(`page ${path}: ${text.slice(0, 1400)}`);
      await page.screenshot({ path: join(outDir, `recovery-failure-${index}.png`), animations: 'disabled' }).catch(() => undefined);
    }
  }

  if (pageErrors.length > 0) failures.push(`browser errors: ${pageErrors.join(' | ')}`);
  await context.close();
  await browser.close();
}

if (process.argv.includes('--check-guard')) {
  const gate = await evaluateMutationGate(process.env, { verifyRuntime: false });
  if (gate.allowed) {
    console.error('E2E GUARD FAILED: recovery run would be allowed without explicit isolated markers.');
    process.exit(1);
  }
  console.log('E2E GUARD OK: recovery mutating run is refused without an approved isolated runtime.');
  process.exit(0);
}

await main();

if (failures.length > 0) {
  console.error('ACCOUNT RECOVERY E2E FAILED:');
  for (const failure of failures) console.error(`  ${failure}`);
  process.exit(1);
}

console.log(
  'ACCOUNT RECOVERY E2E PASSED: standalone resend after reload, token rotation, password reset email, new-tab confirm, single-use token, invalid/missing token recovery and token hygiene.',
);
