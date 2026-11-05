// Explicit HTTP fixtures plus a Chromium virtual authenticator. No working account is changed.
import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import { launchBrowser, navigateClientSide, stableScreenshot } from './e2e-support.mjs';
// WebAuthn RP IDs are DNS names, including the trustworthy localhost origin.
// Chromium rejects a numeric RP ID even when the loopback origin is secure.
const origin = new URL(process.env.BASE_URL ?? 'http://localhost:5173');
if (origin.hostname === '127.0.0.1') origin.hostname = 'localhost';
const base = origin.origin;
const browser = await launchBrowser(); const context = await browser.newContext({ viewport: { width: 1440, height: 1100 } });
await context.addInitScript(origin => { if (location.origin !== origin) return; localStorage.setItem('platform.locale', 'en'); localStorage.setItem('platform.theme', 'dark'); }, new URL(base).origin);
const page = await context.newPage(); const errors = []; const states = []; const mutations = [];
context.on('page', popup => popup.on('pageerror', error => errors.push(error.message)));
page.on('pageerror', error => errors.push(error.message));
const cdp = await context.newCDPSession(page); await cdp.send('WebAuthn.enable');
const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } });
const token = { status: 'authenticated', accessToken: 'fixture', refreshToken: 'fixture-refresh', sessionId: 'fixture-session', accessExpiresAt: '2099-01-01T00:00:00Z', sessionExpiresAt: '2099-01-01T00:00:00Z' };
let keys = []; let identities = []; let lastMethod = false; let recent = false; let conflict = false; let cancelProvider = false; let pendingState;
let providers = [{ code: 'google', loginEnabled: true }];
const key = { id: '01900000-0000-7000-8000-000000000001', label: 'Personal laptop', createdAt: '2026-11-03T00:00:00Z', lastUsedAt: null, transports: ['internal'], backupEligible: false, backupState: false };
const identity = provider => ({ provider, displayName: 'Fixture account', email: 'fixture@example.test', emailVerified: true, avatarUrl: null, linkedAt: '2026-11-03T00:00:00Z', lastLoginAt: null });
const challenge = () => Buffer.from(randomUUID()).toString('base64url');
await context.route('**/api/**', async route => {
  const request = route.request(); const url = new URL(request.url()); const path = url.pathname;
  const respond = (status, body) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
  const fail = (code, status = 409) => respond(status, { status: 'error', error: { code, message: 'Fixture error.' } });
  let data = {};
  if (path === '/api/auth/login' || path === '/api/auth/mfa/verify') data = token;
  else if (path === '/api/auth/logout') return route.fulfill({ status: 204 });
  else if (path === '/api/auth/providers') data = { items: providers };
  else if (path === '/api/auth/me') data = { account: { id: 'fixture-account', personId: null }, roles: [], permissions: [], locale: null, timezone: null, sessionId: 'fixture-session' };
  else if (path === '/api/auth/me/settings') data = { schema_version: 2, settings: { interface: { animations: true, transparency: true }, notifications: { browser: true, sound: true, email: false }, privacy: { show_online_status: false } } };
  else if (path === '/api/auth/me/passkeys') data = { items: keys };
  else if (path === '/api/auth/passkeys/registration/start') {
    if (recent) return fail('auth.recent_required', 403);
    data = { rp: { id: new URL(base).hostname, name: 'Platform' }, user: { id: 'AQIDBA', name: 'fixture', displayName: 'Fixture' }, challenge: challenge(), pubKeyCredParams: [{ type: 'public-key', alg: -7 }], timeout: 300000, authenticatorSelection: { residentKey: 'required', userVerification: 'required' }, attestation: 'none', excludeCredentials: [] };
  } else if (path === '/api/auth/passkeys/registration/complete') {
    const body = request.postDataJSON(); assert.ok(body.credential.response.attestationObject); assert.ok(body.credential.response.clientDataJSON); mutations.push(path); keys = [{ ...key, label: body.label }]; data = keys[0];
  } else if (path.startsWith('/api/auth/me/passkeys/')) {
    assert.equal(path, '/api/auth/me/passkeys/' + key.id);
    if (request.method() === 'PATCH') { key.label = request.postDataJSON().label; keys[0] = { ...key }; data = key; }
    else { if (lastMethod) return fail('auth.identity.last_method'); keys = []; data = { status: 'revoked' }; }
  } else if (path === '/api/auth/passkeys/authentication/start') data = { challenge: challenge(), rpId: new URL(base).hostname, timeout: 300000, userVerification: 'required', allowCredentials: [] };
  else if (path === '/api/auth/passkeys/authentication/complete') { const body = request.postDataJSON(); assert.ok(body.credential.response.signature); mutations.push(path); data = { status: 'mfa_required', challengeToken: 'fixture-passkey-mfa' }; }
  else if (path === '/api/auth/me/identities') data = { items: identities };
  else if (path.endsWith('/link/start')) {
    if (recent) return fail('auth.recent_required', 403);
    const provider = path.includes('/google/') ? 'google' : 'telegram'; pendingState = randomUUID();
    const endpoint = provider === 'google' ? 'https://accounts.google.com/o/oauth2/v2/auth' : 'https://oauth.telegram.org/auth';
    data = { authorizationUrl: endpoint + '?' + new URLSearchParams({ state: pendingState, redirect_uri: base + '/auth/callback/' + provider }), expiresAt: new Date(Date.now() + 300000).toISOString() };
  } else if (path.endsWith('/link/callback')) {
    assert.equal(url.searchParams.get('state'), pendingState); if (conflict) return fail('auth.identity.conflict');
    const provider = path.includes('/google/') ? 'google' : 'telegram'; identities.push(identity(provider)); data = identity(provider); mutations.push(path);
  } else if (path.startsWith('/api/auth/me/identities/') && request.method() === 'DELETE') {
    if (lastMethod) return fail('auth.identity.last_method'); const provider = path.split('/').at(-1); identities = identities.filter(item => item.provider !== provider); data = { status: 'unlinked' };
  }
  return respond(200, { status: 'success', data });
});
await context.route(/^https:\/\/(accounts\.google\.com|oauth\.telegram\.org)\//, async route => {
  const url = new URL(route.request().url()); const target = new URL(url.searchParams.get('redirect_uri'));
  target.searchParams.set('state', url.searchParams.get('state')); target.searchParams.set(cancelProvider ? 'error' : 'code', cancelProvider ? 'access_denied' : 'fixture-code');
  return route.fulfill({ status: 302, headers: { location: target.href }, body: '' });
});
async function inspect(label) {
  const headingOverlap=await page.evaluate(()=>{const title=document.querySelector('.admin__breadcrumbs');const actions=document.querySelector('.admin__topbar-actions');return title&&actions?title.getBoundingClientRect().right>actions.getBoundingClientRect().left+1:false;});assert.equal(headingOverlap,false,label+': heading overlaps actions');
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, label + ': overflow');
  if (await page.getByRole('dialog').count()) {
    await page.waitForFunction(() => { const dialog = document.querySelector('[role="dialog"]'); if (!dialog) return false; const box = dialog.getBoundingClientRect(); return box.top >= 0 && box.left >= 0 && box.bottom <= innerHeight + 1 && box.right <= innerWidth + 1; });
  }
  assert.deepEqual((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze()).violations.map(v => v.id), [], label + ': axe'); states.push(label);
}
mkdirSync('screenshots', { recursive: true });
try {
  await page.goto(base + '/admin/login?redirect=/admin/security/passkeys'); await page.locator('#admin-identifier').fill('fixture@example.test'); await page.locator('#admin-password').fill('fixture-password'); await page.locator('form button[type=submit]').click(); await page.getByText('No passkeys yet', { exact: true }).waitFor();
  await inspect('empty passkeys, basic Core account');
  await page.getByRole('button', { name: 'Add a passkey', exact: true }).click(); await page.locator('#core-passkey-label').fill('Personal laptop'); await page.getByRole('dialog').getByRole('button', { name: 'Continue on this device', exact: true }).click(); await page.getByText('Passkey added.', { exact: true }).waitFor();
  await page.getByRole('button', { name: 'Rename Personal laptop', exact: true }).click(); await page.locator('#core-passkey-label').fill('Work laptop'); await page.getByRole('dialog').getByRole('button', { name: 'Save', exact: true }).click(); await page.getByText('Passkey renamed.', { exact: true }).waitFor(); assert.equal(keys[0].label, 'Work laptop');
  for (const width of [320,393,768,1440]) { await page.setViewportSize({ width, height: 1100 }); await inspect('passkeys EN dark ' + width); }
  await stableScreenshot(page, 'screenshots/core-credentials-passkeys-desktop.png');
  await page.getByRole('button', { name: 'Remove passkey Work laptop', exact: true }).click(); await page.getByRole('dialog').getByRole('button', { name: 'Cancel', exact: true }).click(); assert.equal(keys.length, 1);
  lastMethod = true; await page.getByRole('button', { name: 'Remove passkey Work laptop', exact: true }).click(); await page.getByRole('dialog').getByRole('button', { name: 'Remove passkey', exact: true }).click(); await page.getByText('This is your last sign-in method. Add another method before removing it.', { exact: true }).waitFor(); assert.equal(keys.length, 1);
  lastMethod = false; await page.getByRole('dialog').getByRole('button', { name: 'Remove passkey', exact: true }).click(); await page.getByText('Passkey removed.', { exact: true }).waitFor();
  await cdp.send('WebAuthn.setAutomaticPresenceSimulation', { authenticatorId, enabled: false });
  await page.getByRole('button', { name: 'Add a passkey', exact: true }).click(); await page.getByRole('dialog').getByRole('button', { name: 'Continue on this device', exact: true }).click(); await page.getByText('Follow your browser or device instructions. You can cancel and try again.', { exact: true }).waitFor(); await page.getByRole('dialog').getByRole('button', { name: 'Cancel', exact: true }).click(); assert.equal(mutations.filter(v => v.endsWith('/registration/complete')).length, 1);
  await cdp.send('WebAuthn.setAutomaticPresenceSimulation', { authenticatorId, enabled: true }); states.push('native create/rename/revoke, last-method conflict, cancellation aborts native prompt');
  await navigateClientSide(page, '/admin/security/identities'); await page.getByRole('button', { name: 'Connect Google', exact: true }).waitFor(); assert.equal(await page.getByRole('button', { name: 'Connect Telegram', exact: true }).isDisabled(), true);
  cancelProvider = true; await page.getByRole('button', { name: 'Connect Google', exact: true }).click(); await page.getByText('Provider sign-in was cancelled. You can try again.', { exact: true }).waitFor(); assert.equal(identities.length, 0);
  cancelProvider = false; conflict = true; await page.getByRole('button', { name: 'Connect Google', exact: true }).click(); await page.getByText('This provider is already connected here or belongs to another account. Refresh the list or use a different provider account.', { exact: true }).waitFor(); assert.equal(identities.length, 0);
  conflict = false; await page.getByRole('button', { name: 'Connect Google', exact: true }).click(); await page.getByText('Account connected.', { exact: true }).waitFor(); assert.equal(identities.length, 1); assert.equal(new URL(page.url()).pathname, '/admin/security/identities');
  await page.getByRole('button', { name: 'Disconnect Google', exact: true }).click(); await page.getByRole('dialog').getByRole('button', { name: 'Cancel', exact: true }).click(); assert.equal(identities.length, 1);
  lastMethod = true; await page.getByRole('button', { name: 'Disconnect Google', exact: true }).click(); await page.getByRole('dialog').getByRole('button', { name: 'Disconnect', exact: true }).click(); await page.getByText('This is your last sign-in method. Add another method before removing it.', { exact: true }).waitFor(); lastMethod = false; await page.getByRole('dialog').getByRole('button', { name: 'Disconnect', exact: true }).click(); await page.getByText('Account disconnected.', { exact: true }).waitFor();
  providers.push({ code: 'telegram', loginEnabled: false }); await page.getByRole('button', { name: 'Refresh', exact: true }).click(); await page.getByRole('button', { name: 'Connect Telegram', exact: true }).click(); await page.getByText('Account connected.', { exact: true }).waitFor(); assert.equal(identities[0].provider, 'telegram');
  await inspect('popup linking/cancel/conflict, unlink cancel/last-method/retry and login-disabled provider');
  await page.getByRole('button', { name: 'Light theme', exact: true }).click(); await page.locator('.admin__locale').click(); await page.getByText('Внешний аккаунт подключён.', { exact: true }).waitFor(); await page.emulateMedia({ reducedMotion: 'reduce' }); await page.evaluate(() => document.documentElement.style.setProperty('--ui-font-scale', '1.4'));
  for (const width of [320,393,1440]) { await page.setViewportSize({ width, height: 1100 }); await inspect('identities RU light 140% reduced motion ' + width); }
  await stableScreenshot(page, 'screenshots/core-credentials-identities-desktop-ru.png');
  await page.setViewportSize({ width:393, height:1100 }); await navigateClientSide(page, '/admin/security/passkeys'); await page.getByRole('button', { name: 'Добавить ключ доступа', exact: true }).click(); await inspect('passkey create RU mobile 140%'); await stableScreenshot(page, 'screenshots/core-credentials-passkey-mobile.png'); await page.getByRole('dialog').getByRole('button', { name: 'Отмена', exact: true }).click();
  await page.locator('.admin__locale').click(); await page.setViewportSize({ width:1440, height:1100 }); recent = true; await page.getByRole('button', { name: 'Add a passkey', exact: true }).click(); await page.getByRole('dialog').getByRole('button', { name: 'Continue on this device', exact: true }).click(); await page.getByRole('button', { name: 'Sign out and sign in again', exact: true }).click(); await page.waitForURL(url => url.pathname === '/admin/login'); recent = false;
  await page.getByRole('button', { name: 'Sign in with a passkey', exact: true }).click(); await page.locator('#admin-totp').fill('123456'); await page.locator('form button[type=submit]').click(); await page.waitForURL(url => url.pathname.startsWith('/admin') && url.pathname !== '/admin/login'); states.push('recent-auth redirect and passkey sign-in through MFA continuation');
  assert.deepEqual(errors, []); writeFileSync('screenshots/core-credentials-ui-results.json', JSON.stringify({ passed:true, fixtureOnly:true, virtualAuthenticator:true, states }, null, 2)); console.log(JSON.stringify({ passed:true, fixtureOnly:true, states }));
} catch (error) { await page.screenshot({ path:'screenshots/core-credentials-ui-failure.png' }).catch(()=>{}); throw error; }
finally { await browser.close(); }
