// Real Core API + PostgreSQL + Redis + SMTP. Only the isolated OIDC provider transport is a fixture.
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import { evaluateCoreMutationGate } from './core-e2e-guard.mjs';
import { launchBrowser, stableScreenshot, waitForMailToken } from './e2e-support.mjs';

const env = process.env;
const APP = env.APP_URL;
const API = env.CORE_API_URL;
const browser = await launchBrowser();
const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } });
await context.addInitScript(() => { localStorage.setItem('platform.locale', 'en'); localStorage.setItem('platform.theme', 'dark'); });
const page = await context.newPage();
const failures = [];
page.on('pageerror', error => failures.push(error.message));
const states = [];
mkdirSync('screenshots', { recursive: true });

function fixture(command, email, ...extra) {
  assert.ok(env.CORE_TEST_CONTAINER, 'CORE_TEST_CONTAINER must be explicitly supplied');
  const args = ['exec', env.CORE_TEST_CONTAINER, 'php', 'tests/e2e/core-auth-fixture.php', command];
  if (email) args.push(email);
  args.push(...extra);
  return JSON.parse(execFileSync('docker', args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }));
}
async function api(path, body, status = 200, access) {
  const response = await context.request.post(`${API}/api${path}`, { data: body, headers: {
    'X-Client-Locale': 'en-US', ...(access ? { Authorization: `Bearer ${access}` } : {}),
  } });
  assert.equal(response.status(), status, `${path}: unexpected HTTP status`);
  const result = await response.json();
  if (status < 300) assert.equal(result.status, 'success', `${path}: invalid envelope`);
  return result.data;
}
async function loginApi(account, password = account.password) {
  return api('/auth/login', { identifierType: 'email', identifier: account.email, password });
}
async function submit() { await page.locator('form button[type=submit]').click(); }
async function loginBrowser(account, password = account.password) {
  await page.goto(`${APP}/admin/login`);
  await page.locator('#admin-identifier').fill(account.email);
  await page.locator('#admin-password').fill(password);
  await submit();
}
async function fillNewPassword(password) {
  await page.locator('#core-link-password').fill(password);
  await page.locator('#core-link-confirmation').fill(password);
  await submit();
}
async function token(account, kind, exclude = null) {
  return waitForMailToken(env.MAILPIT_URL, account.email, { exclude, pattern: new RegExp(`${kind}\\?token=([^\\s"'<>&]+)`) });
}
async function assertSessionRevoked(access) {
  assert.equal((await context.request.get(`${API}/api/auth/me`, { headers: { Authorization: `Bearer ${access}` } })).status(), 401);
}
async function completed(title) {
  await page.getByRole('heading', { name: title, exact: true }).waitFor();
  assert.equal(new URL(page.url()).searchParams.has('token'), false, 'Consumed token remains in URL');
}

try {
  const gate = await evaluateCoreMutationGate(env, async url => {
    const response = await context.request.get(url, { timeout: 5000 });
    return { status: response.status(), json: () => response.json() };
  });
  assert.equal(gate.allowed, true, `Core browser mutation refused: ${gate.reasons.join('; ')}`);
  const seeded = fixture('seed');
  assert.equal(seeded.nonce, createHash('sha256').update(env.E2E_ISOLATION_PROOF).digest('hex'));
  const accounts = seeded.accounts;
  const first = await loginApi(accounts.operator);
  assert.equal(first.status, 'mfa_required');
  const operator = await api('/auth/mfa/verify', { challengeToken: first.challengeToken, totpCode: null, recoveryCode: accounts.operator.recoveryCode });
  await api(`/core/accounts/${accounts.activation.id}/invite`, { email: accounts.activation.email }, 202, operator.accessToken);
  const activation = await token(accounts.activation, 'activate');
  await page.goto(`${APP}/activate?token=${encodeURIComponent(activation)}`);
  await page.reload(); // The email URL remains usable across reload until it is consumed.
  await fillNewPassword('short');
  await page.getByText('Use 12–1024 characters for your Core password.', { exact: true }).first().waitFor();
  const activatedPassword = `${env.E2E_PASSWORD}-activated`;
  await fillNewPassword(activatedPassword);
  await completed('Account activated'); states.push('activation: email, reload, validation, completion');
  await page.goto(`${APP}/activate?token=${encodeURIComponent(activation)}`);
  await fillNewPassword(activatedPassword);
  await page.getByRole('alert').filter({ hasText: 'invalid, expired or already used' }).waitFor();
  await loginBrowser(accounts.activation, activatedPassword);
  await page.waitForURL(url => url.pathname === '/admin'); states.push('activation: replay denied and fresh login');
  // A new context/page load drops in-memory tokens before the next identity is tested.
  await page.goto(`${APP}/admin/forgot-password`);
  const oldReset = await loginApi(accounts.reset);
  await page.locator('#core-recovery-email').fill(accounts.reset.email); await submit();
  await page.getByRole('heading', { name: 'Check your email' }).waitFor();
  const reset = await token(accounts.reset, 'password-reset');
  await page.goto(`${APP}/password-reset?token=${encodeURIComponent(reset)}`);
  await page.locator('#core-link-password').fill(`${env.E2E_PASSWORD}-reset`);
  await page.locator('#core-link-confirmation').fill('different password'); await submit();
  await page.getByText('Passwords do not match.', { exact: true }).waitFor();
  const resetPassword = `${env.E2E_PASSWORD}-reset`;
  await fillNewPassword(resetPassword); await completed('Password updated');
  await assertSessionRevoked(oldReset.accessToken);
  await page.goto(`${APP}/password-reset?token=${encodeURIComponent(reset)}`); await fillNewPassword(resetPassword);
  await page.getByRole('alert').filter({ hasText: 'invalid, expired or already used' }).waitFor();
  await api('/auth/login', { identifierType: 'email', identifier: accounts.reset.email, password: accounts.reset.password }, 401);
  await loginBrowser(accounts.reset, resetPassword); await page.waitForURL(url => url.pathname === '/admin');
  states.push('password reset: email, mismatch, replay, old password and session revoked, fresh login');
  await api('/auth/password-reset/request', { email: accounts.reset.email }, 202);
  const expired = await token(accounts.reset, 'password-reset', reset); fixture('expire', accounts.reset.email);
  await page.goto(`${APP}/password-reset?token=${encodeURIComponent(expired)}`); await fillNewPassword(`${resetPassword}-next`);
  await page.getByRole('alert').filter({ hasText: 'invalid, expired or already used' }).waitFor();
  states.push('expired reset link denied');

  const mfaFirst = await loginApi(accounts.mfa);
  const oldMfa = await api('/auth/mfa/verify', { challengeToken: mfaFirst.challengeToken, totpCode: null, recoveryCode: accounts.mfa.recoveryCode });
  await page.goto(`${APP}/admin/mfa-recovery`);
  await page.locator('#core-recovery-email').fill(accounts.mfa.email); await submit();
  await page.getByRole('heading', { name: 'Check your email' }).waitFor();
  const recovery = await token(accounts.mfa, 'mfa-recovery');
  await page.goto(`${APP}/mfa-recovery?token=${encodeURIComponent(recovery)}`);
  await page.locator('#core-link-password').fill('wrong password'); await submit();
  await page.getByRole('alert').waitFor();
  await page.locator('#core-link-password').fill(accounts.mfa.password); await submit(); await completed('Authentication recovered');
  await assertSessionRevoked(oldMfa.accessToken);
  const mfaState = fixture('state', accounts.mfa.email);
  assert.equal(Number(mfaState.sessions), 0); assert.equal(Number(mfaState.factors), 0); assert.equal(Number(mfaState.codes), 0);
  await page.goto(`${APP}/mfa-recovery?token=${encodeURIComponent(recovery)}`);
  await page.locator('#core-link-password').fill(accounts.mfa.password); await submit(); await page.getByRole('alert').waitFor();
  await loginBrowser(accounts.mfa); await page.waitForURL(url => url.pathname === '/admin');
  states.push('MFA recovery: email, wrong password, replay, factors/codes/sessions revoked, fresh login');

  // Only provider transport is a nonce/PKCE-bound disposable fixture. Core HTTP,
  // identity linkage, recovery proof, Redis state, sessions and SMTP are real.
  for (const [provider, kind] of [['google', 'externalGoogle'], ['telegram', 'externalTelegram']]) {
    const account = accounts[kind];
    let callback = null;
    const handler = async route => {
      const url = new URL(route.request().url());
      const code = fixture('provider-code', account.email, provider, url.searchParams.get('nonce') ?? '', url.searchParams.get('code_challenge') ?? '').code;
      callback = new URL(url.searchParams.get('redirect_uri'));
      callback.searchParams.set('state', url.searchParams.get('state'));
      callback.searchParams.set('code', code);
      await route.fulfill({ status: 302, headers: { location: callback.href } });
    };
    const pattern = provider === 'google' ? 'https://accounts.google.com/**' : 'https://oauth.telegram.org/**';
    await context.route(pattern, handler);
    await page.goto(`${APP}/admin/mfa-recovery`);
    await page.locator('#core-recovery-email').fill(account.email); await submit();
    await page.getByRole('heading', { name: 'Check your email' }).waitFor();
    const recoveryToken = await token(account, 'mfa-recovery');
    await page.goto(`${APP}/mfa-recovery?token=${encodeURIComponent(recoveryToken)}`);
    await submit();
    await page.getByRole('button', { name: provider === 'google' ? /Google/ : /Telegram/ }).click();
    await completed('Authentication recovered');
    const recovered = fixture('state', account.email);
    assert.equal(Number(recovered.factors), 0); assert.equal(Number(recovered.codes), 0); assert.equal(Number(recovered.sessions), 0);
    assert.ok(callback);
    await page.goto(callback.href);
    await page.getByRole('alert').waitFor();
    assert.equal(new URL(page.url()).search, '', 'Provider code/state remains in the URL');
    await page.goto(`${APP}/admin/login`);
    await page.getByRole('button', { name: provider === 'google' ? /Google/ : /Telegram/ }).click();
    await page.waitForURL(url => url.pathname === '/admin');
    assert.equal(Number(fixture('state', account.email).sessions), 1);
    await context.unroute(pattern, handler);
    states.push(`${provider}: passwordless MFA recovery through SMTP + PKCE fixture, replay denied, fresh SSO login`);
  }

  // A real denied identity must not observe the preceding operator's warmed rows.
  await loginBrowser(accounts.operator);
  await page.getByRole('button', { name: 'Recovery code', exact: true }).click();
  await page.locator('#admin-recovery').fill(accounts.operator.recoveryCodes[1]); await submit();
  await page.waitForURL(url => url.pathname === '/admin');
  await page.locator('a[href="/admin/persons"]').first().click();
  await page.locator('table tbody tr').first().waitFor();
  await page.getByRole('button', { name: 'Sign out', exact: true }).click();
  await page.locator('#admin-identifier').fill(accounts.reader.email);
  await page.locator('#admin-password').fill(accounts.reader.password); await submit();
  await page.waitForURL(url => url.pathname === '/admin');
  // Client-side navigation retains the cache module in this browser document.
  await page.evaluate(() => { history.pushState({}, '', '/admin/persons'); dispatchEvent(new PopStateEvent('popstate')); });
  await page.getByRole('status').filter({ hasText: 'No access to this section.' }).waitFor();
  assert.equal(await page.locator('table tbody tr').count(), 0);
  const denied = await context.request.get(`${API}/api/core/persons`, { headers: { Authorization: `Bearer ${(await loginApi(accounts.reader)).accessToken}` } });
  assert.equal(denied.status(), 403);
  states.push('limited identity: persons API 403, no former operator rows');

  for (const kind of ['forced', 'forcedMfa']) {
    const account = accounts[kind];
    await loginBrowser(account); await page.getByRole('heading', { name: 'Change your Core password', exact: true }).waitFor();
    assert.equal(Number(fixture('state', account.email).sessions), 0, 'Session issued before required change');
    await stableScreenshot(page, `screenshots/core-auth-${kind}.png`);
    await page.locator('#admin-current-password').fill('wrong password');
    await page.locator('#admin-new-password').fill(`${env.E2E_PASSWORD}-${kind}`);
    await page.locator('#admin-password-confirmation').fill(`${env.E2E_PASSWORD}-${kind}`); await submit();
    await page.getByRole('alert').waitFor();
    await page.locator('#admin-current-password').fill(account.password); await submit();
    if (kind === 'forcedMfa') {
      await page.locator('#admin-totp').waitFor(); assert.equal(Number(fixture('state', account.email).sessions), 0);
      await page.getByRole('button', { name: 'Recovery code', exact: true }).click();
      await page.locator('#admin-recovery').fill(account.recoveryCode); await submit();
    }
    await page.waitForURL(url => url.pathname === '/admin'); states.push(`${kind}: wrong current password, required change${kind === 'forcedMfa' ? ', MFA' : ''}, final session`);
  }
  for (const width of [320, 393, 768, 1440]) {
    await page.setViewportSize({ width, height: 1000 });
    for (const path of ['/activate', '/password-reset', '/mfa-recovery', '/admin/forgot-password', '/admin/mfa-recovery']) {
      await page.goto(APP + path);
      await page.locator('h1').waitFor();
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, `${path} overflows at ${width}px`);
      const accessibility = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
      assert.deepEqual(accessibility.violations.map(v => v.id), [], `${path} accessibility at ${width}px`);
    }
  }
  await page.goto(`${APP}/admin/forgot-password`);
  await stableScreenshot(page, 'screenshots/core-auth-recovery.png');
  states.push('20 real public page states: 320/393/768/1440, no overflow, axe WCAG A/AA');
  assert.deepEqual(failures, [], 'Browser runtime errors');
  writeFileSync('screenshots/core-auth-results.json', JSON.stringify({ passed: true, states }, null, 2));
  console.log(JSON.stringify({ passed: true, states }));
} catch (error) {
  console.error('Core browser failure:', error);
  try {
    await page.waitForLoadState('domcontentloaded');
    console.error('Core browser state:', new URL(page.url()).pathname,
      await page.locator('h1, [role="alert"]').allTextContents(), failures);
    await stableScreenshot(page, 'screenshots/core-auth-failure.png');
  } catch (diagnosticError) {
    console.error('Core browser diagnostics unavailable:', diagnosticError.message);
  }
  throw error;
} finally { await context.close(); await browser.close(); }
