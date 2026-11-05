// HTTP fixtures exercise UI state/races independently of real Core authorization.
import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import { launchBrowser, navigateClientSide, stableScreenshot } from './e2e-support.mjs';

const base = process.env.BASE_URL ?? 'http://127.0.0.1:5173';
const browser = await launchBrowser();
mkdirSync('screenshots', { recursive: true });
const errors = [];
const states = [];
const tokens = { status: 'authenticated', accessToken: 'fixture-only', refreshToken: 'fixture-only',
  accessExpiresAt: '2099-01-01', sessionExpiresAt: '2099-01-01', sessionId: 'fixture-session' };
try {
  for (const locale of ['en', 'ru']) {
    const context = await browser.newContext({ viewport: { width: 393, height: 1000 }, reducedMotion: 'reduce' });
    await context.addInitScript(({ locale }) => {
      localStorage.setItem('platform.locale', locale); localStorage.setItem('platform.theme', locale === 'ru' ? 'light' : 'dark');
    }, { locale });
    const page = await context.newPage(); page.on('pageerror', error => errors.push(error.message));
    let pending;
    let hold = false;
    let authResult = { status: 'password_change_required', challengeToken: 'fixture-change' };
    let changeRejected = false;
    let linkResult = { status: 'completed' };
    await context.route('**/api/**', async route => {
      const path = new URL(route.request().url()).pathname;
      if (!path.startsWith('/api/')) return route.continue();
      let data = { items: [], pagination: { total: 0, limit: 25, offset: 0 } };
      if (path === '/api/auth/login') {
        if (hold) await new Promise(resolve => { pending = resolve; });
        data = authResult;
      } else if (path === '/api/auth/password/change-required') {
        if (changeRejected) return route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ status: 'error', error: { code: 'auth.invalid_credentials' } }) });
        data = { status: 'mfa_required', challengeToken: 'fixture-mfa' };
      } else if (path === '/api/auth/mfa/verify') data = tokens;
      else if (path === '/api/auth/me') data = { account: { id: 'fixture-account', personId: null }, roles: [], permissions: [], sessionId: 'fixture-session' };
      else if (path === '/api/auth/mfa/recovery/confirm') data = linkResult;
      else if (path === '/api/auth/activate') data = { activated: true };
      else if (path === '/api/auth/password-reset/confirm') data = { completed: true };
      else if (path === '/api/auth/password-reset/request') data = { accepted: true };
      else if (path === '/api/auth/mfa/recovery/request') data = { status: 'accepted' };
      await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ status: 'success', data }) }).catch(() => {});
    });
    for (const width of [320, 393, 768, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      for (const path of ['/activate?token=fixture-only', '/password-reset?token=fixture-only', '/mfa-recovery?token=fixture-only', '/admin/forgot-password', '/admin/mfa-recovery']) {
        await page.goto(base + path); await page.locator('h1').waitFor();
        await page.evaluate(() => document.documentElement.style.setProperty('--ui-font-scale', '1.25'));
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, `${locale} ${path} at ${width}px`);
        const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
        assert.deepEqual(axe.violations.map(v => v.id), [], `${locale} ${path} at ${width}px`);
        states.push(`${locale} ${width}px ${path.split('?')[0]}`);
      }
    }
    await page.setViewportSize({ width: 393, height: 1000 });
    await page.goto(base + '/admin/login');
    await page.locator('#admin-identifier').fill('fixture@example.test'); await page.locator('#admin-password').fill('fixture-only-password');
    await page.locator('form button[type=submit]').click(); await page.locator('#admin-current-password').waitFor();
    await page.locator('#admin-current-password').fill('fixture-only-password');
    await page.locator('#admin-new-password').fill('fixture-only-new-password');
    await page.locator('#admin-password-confirmation').fill('wrong-confirmation'); await page.locator('form button[type=submit]').click();
    await page.getByText(locale === 'en' ? 'Passwords do not match.' : 'Пароли не совпадают.', { exact: true }).waitFor();
    await page.locator('#admin-password-confirmation').fill('fixture-only-new-password'); changeRejected = true;
    await page.locator('form button[type=submit]').click(); await page.getByRole('alert').waitFor();
    assert.equal(await page.locator('#admin-current-password').isVisible(), true);
    await stableScreenshot(page, `screenshots/core-auth-change-${locale}.png`);
    changeRejected = false; await page.locator('form button[type=submit]').click(); await page.locator('#admin-totp').waitFor();
    await page.locator('#admin-totp').fill('123456'); await page.locator('form button[type=submit]').click();
    await page.waitForURL(url => url.pathname === '/admin');
    // Leaving a pending login cancels it; a late success must never navigate or install a session.
    await page.goto(base + '/admin/login'); authResult = tokens; hold = true;
    await page.locator('#admin-identifier').fill('fixture@example.test'); await page.locator('#admin-password').fill('fixture-only-password');
    await page.locator('form button[type=submit]').click();
    await page.waitForFunction(() => document.querySelector('form button[type=submit]')?.getAttribute('aria-busy') === 'true');
    await navigateClientSide(page, '/'); await page.locator('main').waitFor();
    assert.ok(pending, 'The delayed login request did not start'); pending(); hold = false;
    await page.waitForTimeout(150); assert.equal(new URL(page.url()).pathname, '/');
    await navigateClientSide(page, '/admin'); await page.waitForURL(url => url.pathname === '/admin/login');
    for (const status of ['additional_recovery_required', 'external_reauth_required']) {
      linkResult = status === 'external_reauth_required'
        ? { status, recoveryToken: 'fixture-proof', providers: ['google'], expiresAt: '2099-01-01' } : { status };
      await page.goto(base + '/mfa-recovery?token=fixture-only'); await page.locator('form button[type=submit]').click();
      const heading = status === 'external_reauth_required'
        ? (locale === 'en' ? 'Confirm your identity with your provider' : 'Подтвердите личность через привязанный сервис')
        : (locale === 'en' ? 'Additional identity verification required' : 'Нужно дополнительное подтверждение личности');
      await page.getByRole('heading', { name: heading }).waitFor();
      assert.equal(new URL(page.url()).searchParams.has('token'), false);
    }
    states.push(`${locale}: required change/mismatch/rejection/MFA, late login ignored, external recovery never reported complete`);
    await context.close();
  }
  assert.deepEqual(errors, [], 'Browser errors');
  writeFileSync('screenshots/core-auth-ui-results.json', JSON.stringify({ passed: true, states }, null, 2));
  console.log(`CORE AUTH UI PASS: ${states.length} fixture states; RU/EN, light/dark, reduced motion, responsive, axe and auth races.`);
} finally { await browser.close(); }
