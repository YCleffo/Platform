import { chromium } from 'playwright';

/** Exercise the real menu button and option; no DOM assignments or native select shortcuts. */
export async function selectDropdown(page, id, value) {
  await page.locator(`[id="${id}"]`).click();
  await page.locator(`[id="${id}-menu"] [data-menu-id="${value}"]`).click();
}

export async function stableScreenshot(page, path) {
  await page.waitForFunction(() => !document.documentElement.hasAttribute('data-theme-changing'));
  await page.screenshot({ path, animations: 'disabled' });
}

export async function launchBrowser() {
  for (const channel of ['chrome', 'msedge']) {
    try {
      return await chromium.launch({ channel, headless: true });
    } catch {
      // try next channel
    }
  }
  return chromium.launch({ headless: true });
}

export async function listMailMessages(mailpit) {
  const response = await fetch(`${mailpit}/api/v1/messages?limit=200`);
  if (!response.ok) throw new Error(`Mailpit list failed: HTTP ${response.status}`);
  return (await response.json()).messages ?? [];
}

export async function waitForMailToken(
  mailpit,
  email,
  { exclude = null, pattern = /#token=([^\s"'<>&]+)/, timeout = 90_000 } = {},
) {
  const deadline = Date.now() + timeout;
  while (Date.now() < deadline) {
    for (const message of await listMailMessages(mailpit)) {
      if (!JSON.stringify(message.To ?? '').includes(email)) continue;
      const full = await (await fetch(`${mailpit}/api/v1/message/${message.ID}`)).json();
      const blob = `${full.Text ?? ''}\n${full.HTML ?? ''}`;
      const match = pattern.exec(blob);
      if (!match) continue;
      const token = decodeURIComponent(match[1]);
      if (token !== exclude) return token;
    }
    await new Promise((resolve) => setTimeout(resolve, 1000));
  }
  throw new Error(`Email for ${email} with a usable link did not arrive.`);
}

export async function waitForVerificationToken(mailpit, email) {
  return waitForMailToken(mailpit, email, { pattern: /verify-email\?token=([^\s"'<>&]+)/, timeout: 60_000 });
}

export async function registerAndVerify({ api, mailpit, email, password }) {
  const registered = await fetch(`${api}/api/control/auth/register`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password }),
  });
  if (registered.status !== 202) throw new Error(`Register ${email} failed: HTTP ${registered.status}`);
  const token = await waitForVerificationToken(mailpit, email);
  const verified = await fetch(`${api}/api/control/auth/verify`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token }),
  });
  if (verified.status !== 200) throw new Error(`Verify ${email} failed: HTTP ${verified.status}`);
}

export async function submitLogin(page, email, password) {
  await page.fill('#login-email', email);
  await page.fill('#login-password', password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await page.waitForURL((url) => url.pathname !== '/login', { timeout: 20_000 });
}

export async function createCommercialAccount(page, code, name) {
  await page.locator('button', { hasText: 'Create account' }).first().click();
  const createDialog = page.locator('[role="dialog"]').filter({ hasText: 'Create a commercial account' });
  await createDialog.waitFor({ state: 'visible' });
  await createDialog.locator('#account-code').fill(code);
  await createDialog.locator('#account-name').fill(name);
  await createDialog.locator('button', { hasText: 'Create account' }).first().click();
  try {
    await createDialog.waitFor({ state: 'hidden', timeout: 20_000 });
  } catch (error) {
    const messages = await createDialog.locator('[role="alert"]').allTextContents();
    throw new Error(`Create account did not complete: ${messages.join('; ') || 'no API error displayed'}`, {
      cause: error,
    });
  }

  const link = page.locator('a[href^="/accounts/"]').filter({ hasText: name }).first();
  await link.waitFor({ state: 'visible', timeout: 20_000 });
  const href = await link.getAttribute('href');
  if (href === null) throw new Error(`Account link for ${name} has no href.`);
  return href.split('/').pop();
}

export async function navigateClientSide(page, path) {
  await page.evaluate((target) => {
    window.history.pushState({}, '', target);
    window.dispatchEvent(new PopStateEvent('popstate'));
  }, path);
}

export async function assertPrimaryNavigation(page, path) {
  const expected = path.startsWith('/settings') ? '/settings' : path.startsWith('/accounts/') ? '/cabinet' : path;
  for (const selector of ['.app-layout__nav-link--active', '.app-bottom-nav__link--active']) {
    const active = page.locator(selector);
    if ((await active.count()) !== 1 || (await active.getAttribute('href')) !== expected) {
      throw new Error(`Primary navigation does not select only ${path}: ${selector}`);
    }
  }
}

export async function verifySecuritySession(page) {
  await navigateClientSide(page, '/settings/security');
  await page.locator('.security-view__session-list').waitFor({ state: 'visible', timeout: 20_000 });
  await assertPrimaryNavigation(page, '/settings/security');
  if ((await page.locator('.security-view__session .app-error-state').count()) !== 0) {
    throw new Error('Security Center rejected the session API response.');
  }
}

export async function assertAccountNavigation(page, path) {
  const active = page.locator('.account-layout__tab--active');
  if ((await active.count()) !== 1 || (await active.getAttribute('href')) !== path) {
    throw new Error(`Account navigation does not select only ${path}.`);
  }
  const indicators = await page.locator('.account-layout__tabs a').evaluateAll(tabs => tabs.filter(tab => !['none', 'normal'].includes(getComputedStyle(tab, '::after').content)).map(tab => tab.getAttribute('href')));
  if (indicators.length !== 1 || indicators[0] !== path) throw new Error(`Account navigation has stale visual indicators: ${indicators}`);
}

export function dialog(page, title) {
  return page.locator('[role="dialog"]').filter({ hasText: title });
}
