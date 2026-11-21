import AxeBuilder from '@axe-core/playwright';
import { createHmac } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { evaluateMutationGate } from './e2e-guard.mjs';
import {
  assertAccountNavigation,
  createCommercialAccount,
  dialog,
  launchBrowser,
  navigateClientSide,
  registerAndVerify,
  submitLogin,
  stableScreenshot,
  verifySecuritySession,
  waitForMailToken,
} from './e2e-support.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const outDir = join(root, 'screenshots');
mkdirSync(outDir, { recursive: true });

const APP = process.env.APP_URL ?? '';
const API = process.env.CONTROL_API_URL ?? '';
const MAILPIT = process.env.MAILPIT_URL ?? '';
const PASSWORD = process.env.E2E_PASSWORD ?? '';
const failures = [];
const check = (condition, message) => {
  if (!condition) failures.push(message);
};
const accessibility = [];
async function scanAccessibility(page, label) {
  await page.locator('.toast-enter-active, .toast-leave-active').waitFor({ state: 'detached', timeout: 5000 }).catch(() => {});
  const dangling = await page.locator('[aria-controls], [aria-labelledby], [aria-describedby], [aria-owns]').evaluateAll(elements => elements.flatMap(element =>
    ['aria-controls', 'aria-labelledby', 'aria-describedby', 'aria-owns'].flatMap(attribute => (element.getAttribute(attribute)?.split(/\s+/) ?? []).filter(id => id && !document.getElementById(id)).map(id => `${attribute}: ${id}`))));
  if (dangling.length) throw new Error(`${label}: dangling ARIA references: ${dangling.join(', ')}`);
  const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).exclude('.toast-enter-active, .toast-leave-active').analyze();
  accessibility.push({ label, violations: result.violations.map(item => ({ id: item.id, impact: item.impact, nodes: item.nodes.map(node => node.target) })) });
  writeFileSync(join(outDir, 'authenticated-accessibility.json'), JSON.stringify({ passed: !accessibility.some(item => item.violations.length), results: accessibility }, null, 2));
  if (result.violations.length) {
    throw new Error(`${label}: accessibility violations ${JSON.stringify(result.violations.map(item => ({ id: item.id, impact: item.impact, targets: item.nodes.map(node => node.target) })))}`);
  }
}

function base32Decode(secret) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const char of secret) bits += strPad(decToBin(alphabet.indexOf(char)), 5);
  const bytes = [];
  for (let index = 0; index + 8 <= bits.length; index += 8) {
    bytes.push(parseInt(bits.slice(index, index + 8), 2));
  }
  return Buffer.from(bytes);
}

function strPad(value, width) {
  return String(value).padStart(width, '0');
}

function decToBin(value) {
  return value.toString(2);
}

function totp(secret, counter) {
  const key = base32Decode(secret);
  const buffer = Buffer.alloc(8);
  buffer.writeBigUInt64BE(BigInt(counter));
  const mac = createHmac('sha1', key).update(buffer).digest();
  const offset = mac[mac.length - 1] & 15;
  const value =
    ((mac[offset] & 127) << 24) |
    ((mac[offset + 1] & 255) << 16) |
    ((mac[offset + 2] & 255) << 8) |
    (mac[offset + 3] & 255);
  return String(value % 1_000_000).padStart(6, '0');
}

function totpFactory(secret) {
  let lastCounter = 0;
  return async () => {
    const counter = Math.max(Math.floor(Date.now() / 30_000), lastCounter + 1);
    const readyAt = counter * 30_000 + 250;
    if (readyAt > Date.now()) await new Promise((resolve) => setTimeout(resolve, readyAt - Date.now()));
    lastCounter = counter;
    return totp(secret, counter);
  };
}

async function enrollMfa(page) {
  await verifySecuritySession(page);
  await page.getByRole('heading', { name: 'Security', exact: true }).waitFor({ timeout: 20_000 });
  await page.getByText('Not enabled', { exact: true }).waitFor({ timeout: 20_000 });
  await page.screenshot({ path: join(outDir, 'e2e-mfa-before-setup.png') });
  await scanAccessibility(page, 'security-center');
  await page.getByRole('button', { name: 'Connect authenticator' }).click();
  const setup = dialog(page, 'Connect authenticator');
  await setup.waitFor({ state: 'visible' });
  await scanAccessibility(page, 'mfa-setup-dialog');
  const secret = (await setup.locator('.mfa-setup__secret').textContent())?.trim() ?? '';
  check(secret.length > 0, 'MFA setup exposes the manual secret');
  if (secret === '') throw new Error('MFA secret is unavailable.');
  const nextCode = totpFactory(secret);
  await setup.locator('#totp-code').fill(await nextCode());
  await setup.getByRole('button', { name: 'Confirm code' }).click();
  await setup.getByRole('heading', { name: 'Recovery codes' }).waitFor({ timeout: 20_000 });
  await scanAccessibility(page, 'mfa-recovery-codes');
  await setup.getByRole('button', { name: 'I have saved the codes' }).click();
  await setup.waitFor({ state: 'hidden', timeout: 20_000 });
  await page.getByText('Enabled', { exact: true }).first().waitFor({ timeout: 20_000 });
  await page.screenshot({ path: join(outDir, 'e2e-mfa-enabled.png') });
  return nextCode;
}

async function completeReauthIfVisible(page, totp) {
  const reauth = dialog(page, 'Confirm your identity');
  await reauth.waitFor({ state: 'visible', timeout: 8_000 }).catch(() => {});
  if (!(await reauth.isVisible().catch(() => false))) return false;
  await reauth.locator('#reauth-password').fill(PASSWORD);
  const codeInput = reauth.locator('#reauth-code');
  await codeInput.waitFor({ state: 'visible', timeout: 10_000 }).catch(() => {});
  if (await codeInput.isVisible().catch(() => false)) {
    await codeInput.fill(await totp());
  }
  await reauth.getByRole('button', { name: 'Confirm identity' }).click();
  try {
    await reauth.waitFor({ state: 'hidden', timeout: 20_000 });
  } catch {
    throw new Error(`MFA step-up did not complete: ${await reauth.innerText()}`);
  }
  return true;
}

async function createInvitation(page, email, role) {
  await page.getByRole('button', { name: 'Invite by email' }).click();
  const invite = dialog(page, 'Invite a member');
  await invite.waitFor({ state: 'visible' });
  await invite.locator('#invitation-email').fill(email);
  await invite.locator('#invitation-role').click();
  await invite.locator(`#invitation-role-menu [data-menu-id="${role}"]`).click();
  await invite.getByRole('button', { name: 'Send invitation' }).click();
  return invite;
}

async function main() {
  const gate = await evaluateMutationGate(process.env);
  if (!gate.allowed) {
    console.error('NOT_VERIFIED: invitation/MFA browser E2E refused (no approved isolated runtime).');
    for (const reason of gate.reasons) console.error(`  - ${reason}`);
    process.exit(2);
  }

  const runId = `${Date.now().toString(36)}-${process.pid}`;
  const emailA = `e2e-owner-${runId}@example.test`;
  const emailB = `e2e-invitee-${runId}@example.test`;
  const emailC = `e2e-decline-${runId}@example.test`;
  const emailD = `e2e-revoke-${runId}@example.test`;
  await Promise.all(
    [emailA, emailB, emailC, emailD].map((email) =>
      registerAndVerify({ api: API, mailpit: MAILPIT, email, password: PASSWORD }),
    ),
  );

  const browser = await launchBrowser();
  const ownerErrors = [];
  const context = await browser.newContext({ viewport: { width: 393, height: 852 }, ignoreHTTPSErrors: true });
  await context.addInitScript(() => {
    localStorage.setItem('platform.locale', 'en');
    localStorage.setItem('platform.theme', 'light');
  });
  const page = await context.newPage();
  const serverErrors = [];
  page.on('response', response => {
    const pathname = new URL(response.url()).pathname;
    if (response.status() >= 500 && pathname.startsWith('/api/') && pathname !== '/api/branding') {
      serverErrors.push(`${response.request().method()} ${pathname}: HTTP ${response.status()}`);
    }
  });
  page.on('pageerror', (error) => ownerErrors.push(String(error)));
  page.on('console', (message) => {
    if (message.type() !== 'error') return;
    const url = message.location().url ?? '';
    if (url.includes('/api/') || url.endsWith('/favicon.ico') || url.endsWith('/favicon.svg')) return;
    ownerErrors.push(`${message.text()} (${url})`);
  });

  let accountId = '';
  try {
    await page.goto(`${APP}/login`, { waitUntil: 'networkidle' });
    await submitLogin(page, emailA, PASSWORD);
    await page.waitForURL('**/cabinet', { timeout: 20_000 });
    accountId = await createCommercialAccount(page, `e2e-inv-${runId}`, `Invitation E2E ${runId}`);

    const nextCode = await enrollMfa(page);

    await navigateClientSide(page, '/cabinet');
    await page.getByRole('heading', { name: 'Commercial accounts' }).waitFor({ timeout: 20_000 });
    await page.locator('a[href^="/accounts/"]').filter({ hasText: `Invitation E2E ${runId}` }).first().click();
    await page.waitForURL(`**/accounts/${accountId}`, { timeout: 20_000 });
    await page.locator('.account-layout__tabs a', { hasText: 'Invitations' }).first().click();
    await page.waitForURL(`**/accounts/${accountId}/invitations`, { timeout: 20_000 });
    await page.getByRole('heading', { name: 'Invitations' }).waitFor({ timeout: 20_000 });
    await assertAccountNavigation(page, `/accounts/${accountId}/invitations`);

    const inviteB = await createInvitation(page, emailB, 'billing');
    const neededReauth = await completeReauthIfVisible(page, nextCode);
    check(neededReauth, 'first invitation action requires MFA step-up after enrollment');
    await inviteB.waitFor({ state: 'hidden', timeout: 30_000 });
    await page.locator('.invitation__email').filter({ hasText: emailB }).waitFor({ timeout: 20_000 });
    const tokenB = await waitForMailToken(MAILPIT, emailB);

    const contextB = await browser.newContext({ viewport: { width: 393, height: 852 }, ignoreHTTPSErrors: true });
    await contextB.addInitScript(() => localStorage.setItem('platform.locale', 'en'));
    const pageB = await contextB.newPage();
    await pageB.goto(`${APP}/invitation#token=${encodeURIComponent(tokenB)}`, { waitUntil: 'networkidle' });
    await pageB.getByRole('heading', { name: 'Account invitation' }).waitFor({ timeout: 20_000 });
    check((await pageB.locator('.invitation-accept__intro').first().textContent())?.includes('Billing'), 'invitation preview shows the invited role');
    await pageB.getByRole('button', { name: 'Sign in', exact: true }).first().click();
    await pageB.waitForURL('**/login**', { timeout: 20_000 });
    await submitLogin(pageB, emailB, PASSWORD);
    await pageB.waitForURL('**/invitation', { timeout: 20_000 });
    await pageB.getByRole('button', { name: 'Accept invitation' }).click();
    await pageB.waitForURL(`**/accounts/${accountId}`, { timeout: 20_000 });
    check((await pageB.locator('.account-layout__name').textContent())?.includes(`Invitation E2E ${runId}`), 'invitee opens the joined account');
    await pageB.screenshot({ path: join(outDir, 'e2e-invitation-accepted.png') });

    await page.locator('.account-layout__tabs a', { hasText: 'Members' }).first().click();
    await page.waitForURL(`**/accounts/${accountId}/members`, { timeout: 20_000 });
    const memberB = page.locator('.member').filter({ hasText: emailB });
    await memberB.waitFor({ timeout: 20_000 });
    check(await memberB.getByText('Billing', { exact: true }).first().isVisible(), 'accepted invitation creates a billing membership');

    await memberB.getByRole('button', { name: 'Change role' }).click();
    const roleDialog = dialog(page, 'Change member role');
    await roleDialog.locator('#member-role').click();
    await roleDialog.locator('#member-role-menu [data-menu-id="technical"]').click();
    await roleDialog.getByRole('button', { name: 'Save' }).click();
    await roleDialog.waitFor({ state: 'hidden', timeout: 20_000 });
    try {
      await memberB.getByText('Technical', { exact: true }).first().waitFor({ timeout: 20_000 });
    } catch {
      throw new Error(`role change page: ${await page.locator('body').innerText()}`);
    }
    const ownerRow = page.locator('.member').filter({ hasText: emailA });
    await ownerRow.getByRole('button', { name: 'Remove member' }).click();
    const removeDialog = dialog(page, 'Remove member');
    await removeDialog.getByRole('button', { name: 'Remove member' }).click();
    await removeDialog.getByText('This operation is not allowed.').waitFor({ timeout: 20_000 });
    await removeDialog.getByRole('button', { name: 'Cancel' }).click();
    await removeDialog.waitFor({ state: 'hidden', timeout: 20_000 });

    await page.locator('.account-layout__tabs a', { hasText: 'Invitations' }).first().click();
    await page.waitForURL(`**/accounts/${accountId}/invitations`, { timeout: 20_000 });

    const inviteC = await createInvitation(page, emailC, 'technical');
    await inviteC.waitFor({ state: 'hidden', timeout: 30_000 });
    const cardC = page.locator('.invitation').filter({ hasText: emailC });
    await cardC.waitFor({ timeout: 20_000 });
    const oldTokenC = await waitForMailToken(MAILPIT, emailC);
    await cardC.getByRole('button', { name: 'Resend invitation' }).click();
    const newTokenC = await waitForMailToken(MAILPIT, emailC, { exclude: oldTokenC });
    check(newTokenC !== oldTokenC, 'resend rotates the invitation token');

    const contextC = await browser.newContext({ viewport: { width: 393, height: 852 }, ignoreHTTPSErrors: true });
    await contextC.addInitScript(() => localStorage.setItem('platform.locale', 'en'));
    const pageC = await contextC.newPage();
    await pageC.goto(`${APP}/invitation?e2e=old#token=${encodeURIComponent(oldTokenC)}`, { waitUntil: 'networkidle' });
    try {
      await pageC.getByText('This invitation link is invalid or has expired.').waitFor({ timeout: 20_000 });
    } catch {
      throw new Error(`old invitation token page: ${await pageC.locator('body').innerText()}`);
    }
    await pageC.goto(`${APP}/invitation?e2e=new#token=${encodeURIComponent(newTokenC)}`, { waitUntil: 'networkidle' });
    try {
      await pageC.getByRole('heading', { name: 'Account invitation' }).waitFor({ timeout: 20_000 });
    } catch {
      throw new Error(`resent invitation page: ${await pageC.locator('body').innerText()}`);
    }
    await pageC.getByRole('button', { name: 'Sign in', exact: true }).first().click();
    await submitLogin(pageC, emailC, PASSWORD);
    await pageC.waitForURL('**/invitation', { timeout: 20_000 });
    await pageC.getByRole('button', { name: 'Decline invitation' }).click();
    const declineDialog = dialog(pageC, 'Decline invitation');
    await declineDialog.getByRole('button', { name: 'Decline invitation' }).click();
    await pageC.getByRole('heading', { name: 'Invitation declined.' }).waitFor({ timeout: 20_000 });
    check(!(await pageC.getByText('Open the invitation link from your email').isVisible().catch(() => false)), 'decline success is not replaced by missing-token state');
    await pageC.screenshot({ path: join(outDir, 'e2e-invitation-declined.png') });

    const inviteD = await createInvitation(page, emailD, 'admin');
    await inviteD.waitFor({ state: 'hidden', timeout: 30_000 });
    const tokenD = await waitForMailToken(MAILPIT, emailD);
    const cardD = page.locator('.invitation').filter({ hasText: emailD });
    await cardD.waitFor({ timeout: 20_000 });
    await cardD.getByRole('button', { name: 'Revoke invitation' }).click();
    const revokeDialog = dialog(page, 'Revoke invitation');
    await revokeDialog.getByRole('button', { name: 'Revoke invitation' }).click();
    await revokeDialog.waitFor({ state: 'hidden', timeout: 20_000 });
    await cardD.getByText('Revoked', { exact: true }).first().waitFor({ timeout: 20_000 });

    const contextD = await browser.newContext({ viewport: { width: 393, height: 852 }, ignoreHTTPSErrors: true });
    await contextD.addInitScript(() => localStorage.setItem('platform.locale', 'en'));
    const pageD = await contextD.newPage();
    await pageD.goto(`${APP}/invitation#token=${encodeURIComponent(tokenD)}`, { waitUntil: 'networkidle' });
    await pageD.getByText('This invitation was revoked.').waitFor({ timeout: 20_000 });

    const contextA2 = await browser.newContext({ viewport: { width: 393, height: 852 }, ignoreHTTPSErrors: true });
    await contextA2.addInitScript(() => localStorage.setItem('platform.locale', 'en'));
    const pageA2 = await contextA2.newPage();
    await pageA2.goto(`${APP}/login`, { waitUntil: 'networkidle' });
    await submitLogin(pageA2, emailA, PASSWORD);
    await pageA2.waitForURL('**/mfa', { timeout: 20_000 });
    await pageA2.locator('#mfa-code').waitFor({ state: 'visible', timeout: 20_000 });
    await scanAccessibility(pageA2, 'mfa-challenge');
    await pageA2.locator('#mfa-code').fill(await nextCode());
    const verifyButtonA2 = pageA2.getByRole('button', { name: 'Verify', exact: true });
    if (await verifyButtonA2.isVisible().catch(() => false)) {
      await verifyButtonA2.click().catch(() => {});
    }
    await pageA2.waitForURL('**/cabinet', { timeout: 20_000 });
    await pageA2.locator('.account-list__link').filter({ hasText: `Invitation E2E ${runId}` }).waitFor({ timeout: 20_000 });
    await stableScreenshot(pageA2, join(outDir, 'e2e-owner-after-mfa-login.png'));
    await contextA2.close();

    const lateContext = await browser.newContext({ viewport: { width: 393, height: 852 }, ignoreHTTPSErrors: true });
    await lateContext.addInitScript(() => localStorage.setItem('platform.locale', 'en'));
    const latePage = await lateContext.newPage();
    await latePage.route('**/api/control/auth/mfa/login/verify', async route => {
      try {
        const response = await route.fetch();
        await new Promise(resolve => setTimeout(resolve, 1_500));
        await route.fulfill({ response });
      } catch {
        // The abandoned MFA request was aborted by the page lifecycle.
      }
    });
    await latePage.goto(`${APP}/login`, { waitUntil: 'networkidle' });
    await submitLogin(latePage, emailA, PASSWORD);
    await latePage.waitForURL('**/mfa', { timeout: 20_000 });
    const startedMfa = latePage.waitForRequest('**/api/control/auth/mfa/login/verify', { timeout: 10_000 });
    await latePage.locator('#mfa-code').fill(await nextCode());
    const lateVerifyButton = latePage.getByRole('button', { name: 'Verify', exact: true });
    if (await lateVerifyButton.isVisible().catch(() => false)) {
      await lateVerifyButton.click().catch(() => {});
    }
    await startedMfa;
    await navigateClientSide(latePage, '/register');
    await latePage.waitForURL('**/register', { timeout: 20_000 });
    await latePage.waitForTimeout(2_000);
    check(new URL(latePage.url()).pathname === '/register', 'a late MFA response does not leave the new page');
    check(await latePage.locator('#register-email').isVisible(), 'the newer registration form remains intact after late MFA');
    await lateContext.close();

    await contextD.close();
    await contextC.close();
    await contextB.close();
  } catch (error) {
    await page.screenshot({ path: join(outDir, 'e2e-invitation-failure.png'), fullPage: true });
    if (serverErrors.length) failures.push(`server responses: ${serverErrors.join(' | ')}`);
    failures.push(`invitation/MFA flow: ${String(error)}`);
  }

  if (ownerErrors.length > 0) failures.push(`owner browser errors: ${ownerErrors.join(' | ')}`);
  await context.close();
  await browser.close();
}

if (process.argv.includes('--check-guard')) {
  const gate = await evaluateMutationGate(process.env, { verifyRuntime: false });
  if (gate.allowed) {
    console.error('E2E GUARD FAILED: mutating run would be allowed without explicit isolated markers.');
    process.exit(1);
  }
  console.log('E2E GUARD OK: invitation/MFA mutating run is refused without an approved isolated runtime.');
  for (const reason of gate.reasons) {
    console.log(`  - ${reason}`);
  }
  process.exit(0);
}

await main();

if (failures.length > 0) {
  console.error('INVITATION/MFA E2E FAILED:');
  for (const failure of failures) console.error(`  ${failure}`);
  process.exit(1);
}

console.log(
  'INVITATION/MFA E2E PASSED: MFA enrollment and step-up, invitation email, preview, login, accept, role change, last-owner guard, resend, old-token rejection, decline success, revoke, MFA login.',
);
