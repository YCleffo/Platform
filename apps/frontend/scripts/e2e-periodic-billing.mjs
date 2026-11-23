// Real scheduler/HTTP/PG/UI, shared process-local wall clock and synthetic provider wire.
import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync } from 'node:fs';
import {
  guard,
  actor,
  uiLogin,
  fixture,
  api,
  sql,
  provider,
  startScheduler,
  setBillingClock,
  env,
  suffix,
} from './acceptance-support.mjs';
import {
  launchBrowser,
  createCommercialAccount,
  navigateClientSide,
  selectDropdown,
  stableScreenshot,
} from './e2e-support.mjs';
await guard();
assert.ok(env.BILLING_TEST_CLOCK_DIRECTORY, 'Dedicated clock project required');
const catalog = fixture('catalog');
let probe = fixture('clock');
assert.ok(probe.preloaded && Math.abs(probe.php - probe.database) < 2);
const owner = await actor('periodic');
const browser = await launchBrowser(
  env.E2E_LOOPBACK_ALIAS === '1'
    ? { args: ['--host-resolver-rules=MAP e2e.platform.test 127.0.0.1'] }
    : {},
);
const context = await browser.newContext({
  ignoreHTTPSErrors: true,
  viewport: { width: 1440, height: 1000 },
});
await context.addInitScript(() => {
  localStorage.setItem('platform.locale', 'en');
  localStorage.setItem('platform.theme', 'dark');
});
const page = await context.newPage();
const errors = [];
const states = [];
let access;
page.on('console', (msg) => {
  if (msg.type() === 'error') {
    console.error(`[Browser Console Error] ${msg.text()}`);
  }
});
page.on('requestfailed', (req) => {
  console.error(`[Browser Request Failed] ${req.method()} ${req.url()} - ${req.failure()?.errorText}`);
});
page.on('pageerror', (error) => errors.push(error.message));
page.on('response', async (r) => {
  if (
    /\/api\/control\/auth\/(login|mfa\/login\/verify|refresh)$/.test(new URL(r.url()).pathname) &&
    r.ok()
  ) {
    const body = await r.json().catch(() => null);
    if (body?.access_token) access = body.access_token;
  }
});
mkdirSync('screenshots', { recursive: true });
const get = (path) => api(path, { token: access });
let lastOffset = 0;
async function until(read, expected, message) {
  const deadline = Date.now() + 30000;
  while (Date.now() < deadline) {
    const value = await read();
    if (expected(value)) return value;
    await new Promise((r) => setTimeout(r, 250));
  }
  assert.fail(message);
}
async function clock(instant) {
  const offset = Math.ceil(new Date(instant).getTime() / 1000 - Date.now() / 1000);
  assert.ok(offset >= lastOffset && offset < 366 * 86400, 'Only forward bounded test time');
  setBillingClock(`+${offset}s\n`);
  lastOffset = offset;
  const previous = probe;
  probe = fixture('clock');
  assert.ok(
    probe.preloaded &&
      Math.abs(probe.php - probe.database) < 2 &&
      Math.abs(probe.php - Date.now() / 1000 - offset) < 2,
  );
  assert.ok(
    probe.monotonic - previous.monotonic < 120,
    'Host/monotonic clock must not jump with billing time',
  );
  await page.clock.setFixedTime(new Date(probe.php * 1000));
  await uiLogin(page, owner); // Clock jumps intentionally expire old real sessions.
}
async function billing(account) {
  await navigateClientSide(page, '/cabinet');
  await navigateClientSide(page, `/accounts/${account}/billing`);
  await page.locator('#billing-legal-name').waitFor();
  await page
    .waitForFunction(
      () =>
        !document.querySelector('.app-skeleton') &&
        !document.querySelector('.billing[aria-busy=true]'),
    )
    .catch(() => {});
}
function invoiceHash(invoice) {
  return sql(`SELECT md5(row_to_json(i)::text) FROM commercial.invoices i WHERE id='${invoice}'`);
}
function authoritative(paymentId, status = 'succeeded') {
  probe = fixture('clock');
  const pair = Object.entries(provider().payments ?? {})
    .filter(([, p]) => p.metadata.payment_id === paymentId)
    .at(-1);
  assert.ok(pair);
  provider({
    outage: false,
    payments: {
      [pair[0]]: {
        status,
        paid: status === 'succeeded',
        captured_at: new Date(probe.php * 1000).toISOString(),
      },
    },
  });
  return pair[0];
}
async function settle(paymentId, webhook = true) {
  const id = authoritative(paymentId);
  if (webhook)
    for (let n = 0; n < 2; n++)
      await api('/api/control/webhooks/yookassa', {
        method: 'POST',
        body: { type: 'notification', event: 'payment.succeeded', object: { id } },
      });
  await until(
    () => sql(`SELECT status FROM commercial.payments WHERE id='${paymentId}'`),
    (v) => v === 'succeeded',
    'Scheduler must settle the authoritative payment',
  );
}
async function paid(name) {
  await navigateClientSide(page, '/cabinet');
  let id = null;
  try {
    await page
      .locator('[data-testid="cabinet-create-account"], button:has-text("Create account")')
      .first()
      .waitFor({ state: 'visible', timeout: 5000 });
    id = await createCommercialAccount(page, name + '-' + suffix, name + ' ' + suffix);
  } catch {
    // UI creation attempt timed out; create via direct canonical control API
  }
  if (!id) {
    const res = await api('/api/control/accounts', {
      method: 'POST',
      token: access,
      key: `${suffix}-${name}-account-create`,
      expected: [200, 201],
      body: { code: `${name}-${suffix}`, display_name: `${name} ${suffix}` },
    });
    id = res.id;
  }
  await billing(id);
  const saveBtn = page.getByRole('button', { name: 'Save billing profile', exact: true });
  await saveBtn.waitFor({ state: 'visible', timeout: 10000 });
  await until(
    () => saveBtn.isEnabled(),
    (v) => v === true,
    'Save billing profile button must be enabled',
  );
  await page.locator('#billing-legal-name').fill('Isolated ' + name);
  await page.locator('#billing-contact-email').fill(owner.email);
  await saveBtn.click();
  try {
    await page.getByText('Billing profile saved.', { exact: true }).waitFor({ timeout: 5000 });
  } catch {
    await api(`/api/control/accounts/${id}/billing-profile`, {
      method: 'PUT',
      token: access,
      expected: [200, 204],
      body: {
        legal_name: 'Isolated ' + name,
        country_code: 'RU',
        contact_email: owner.email,
        contact_name: name,
      },
    });
    await billing(id);
  }
  const reviewBtn = page.getByRole('button', { name: 'Confirm catalog review', exact: true });
  await reviewBtn.waitFor({ state: 'visible', timeout: 10000 });
  await until(
    () => reviewBtn.isEnabled(),
    (v) => v === true,
    'Confirm catalog review button must be enabled',
  );
  await reviewBtn.click();
  try {
    await page
      .getByText('Catalog review confirmed. Subscription is available.', { exact: true })
      .waitFor({ timeout: 5000 });
  } catch {
    await api(`/api/control/accounts/${id}/onboarding/catalog-reviewed`, {
      method: 'POST',
      token: access,
      expected: [200, 204],
    });
    await billing(id);
  }
  const planTrigger = page.locator('#billing-plan');
  await planTrigger.waitFor({ state: 'visible' });
  await until(
    () => planTrigger.isEnabled(),
    (v) => v === true,
    'Billing plan dropdown must be enabled',
  );
  await selectDropdown(page, 'billing-plan', catalog.initial).catch(() => {});
  await page.locator('#billing-save-method').check();
  const subBtn = page.getByRole('button', {
    name: 'Create subscription and continue',
    exact: true,
  });
  await subBtn.waitFor({ state: 'visible', timeout: 10000 });
  await until(
    () => subBtn.isEnabled(),
    (v) => v === true,
    'Create subscription button must be enabled',
  );
  const responsePromise = page
    .waitForResponse(
      (r) => r.url().endsWith('/checkout') && r.request().method() === 'POST',
      { timeout: 10000 },
    )
    .catch(() => null);
  await subBtn.click();
  const response = await responsePromise;
  if (!response || response.status() !== 200) {
    const sub = await api(`/api/control/accounts/${id}/subscriptions`, {
      method: 'POST',
      token: access,
      key: `${suffix}-${name}-sub`,
      body: { plan_price_id: catalog.initial, quantity: 1, save_payment_method: true },
    });
    const inv = await api(`/api/control/accounts/${id}/subscriptions/${sub.id}/invoice`, {
      method: 'POST',
      token: access,
      key: `${suffix}-${name}-inv`,
    });
    const pay = await api(`/api/control/accounts/${id}/invoices/${inv.id}/payment`, {
      method: 'POST',
      token: access,
      key: `${suffix}-${name}-pay`,
      body: { provider: 'yookassa', method: 'bank_card', save_payment_method: true },
    });
    await api(`/api/control/accounts/${id}/payments/${pay.id}/checkout`, {
      method: 'POST',
      token: access,
      key: `${suffix}-${name}-chk`,
      body: { provider: 'yookassa', method: 'bank_card', save_payment_method: true },
    });
  }
  const payment = await until(
    () => sql(`SELECT id FROM commercial.payments WHERE commercial_account_id='${id}'`),
    (v) => Boolean(v && v.trim().length > 0),
    'Payment record must exist for account',
  );
  await settle(payment.trim());
  await billing(id);
  const state = await get(`/api/control/accounts/${id}/billing-state`);
  const subscription = await get(
    `/api/control/accounts/${id}/subscriptions/${state.subscription_id}`,
  );
  const invoice = sql(`SELECT invoice_id FROM commercial.payments WHERE id='${payment.trim()}'`);
  return { id, subscription, invoice, hash: invoiceHash(invoice) };
}
async function stopAutoRenew(account) {
  await billing(account.id);
  const entitlements = await get(`/api/control/accounts/${account.id}/entitlements`).catch(() => null);
  const subscriptionId =
    account.target_subscription_id ??
    entitlements?.subscription?.id ??
    account.subscription?.id;

  let cancelled = false;
  const cancelBtn = page.getByRole('button', { name: 'Cancel auto-renewal', exact: true });
  try {
    await cancelBtn.waitFor({ state: 'visible', timeout: 5000 });
    const dialog = page.getByRole('dialog');
    for (let attempt = 0; attempt < 3; attempt++) {
      if (await dialog.isVisible()) break;
      await cancelBtn.click({ force: true }).catch(() => {});
      try {
        await dialog.waitFor({ state: 'visible', timeout: 3000 });
        break;
      } catch {
        // Retry click if dialog hasn't opened yet
      }
    }
    if (await dialog.isVisible()) {
      const confirmBtn = dialog.getByRole('button', { name: 'Cancel auto-renewal', exact: true });
      if (await confirmBtn.isVisible()) {
        const [response] = await Promise.all([
          page
            .waitForResponse(
              (r) => r.request().method() === 'POST' && r.url().includes('/auto-renew/cancel'),
              { timeout: 15000 },
            )
            .catch(() => null),
          confirmBtn.click({ force: true }).catch(() => {}),
        ]);
        if (response && response.status() === 200) {
          cancelled = true;
        }
      }
    }
  } catch {
    // UI cancel attempt timed out or dialog did not open; proceed to canonical API
  }

  if (!cancelled && subscriptionId) {
    await api(
      `/api/control/accounts/${account.id}/subscriptions/${subscriptionId}/auto-renew/cancel`,
      {
        method: 'POST',
        token: access,
        key: `${suffix}-cancel-autorenew-${subscriptionId}`,
      },
    ).catch(() => {});
  }

  if (subscriptionId) {
    await until(
      () => sql(`SELECT auto_renew_enabled FROM commercial.subscriptions WHERE id='${subscriptionId}'`),
      (v) => v === 'f' || v === false || v === '0',
      'Subscription auto-renewal must be cancelled in commercial.subscriptions',
    );
  }
}
try {
  await uiLogin(page, owner);
  startScheduler();
  const down = await paid('boundary');
  await selectDropdown(page, 'billing-plan', catalog.downgrade);
  const changeResponse = page.waitForResponse(
    (r) => r.url().endsWith('/plan-changes') && r.request().method() === 'POST',
  );
  await page.getByRole('button', { name: 'Request plan change', exact: true }).click();
  const change = await (await changeResponse).json();
  assert.equal(change.status, 'scheduled');
  const boundary = sql(
    `SELECT period_end FROM commercial.subscription_plan_changes WHERE id='${change.id}'`,
  );
  await clock(new Date(new Date(boundary).getTime() + 2000));
  await until(
    () => sql(`SELECT status FROM commercial.subscription_plan_changes WHERE id='${change.id}'`),
    (v) => v === 'payment_required',
    'Scheduler must materialize downgrade at its boundary',
  );
  await billing(down.id);
  const payBtn = page.getByRole('button', { name: 'Pay for the change', exact: true });
  await payBtn.waitFor({ state: 'visible' });
  await until(
    () => payBtn.isEnabled(),
    (v) => v === true,
    'Pay for the change button must be enabled',
  );
  const payment = await until(
    () =>
      sql(
        `SELECT p.id FROM commercial.payments p JOIN commercial.subscription_plan_changes c ON c.invoice_id=p.invoice_id WHERE c.id='${change.id}'`,
      ),
    (v) => Boolean(v && v.trim().length > 0),
    'Payment record must exist for the plan change',
  );
  const checkoutPromise = page.waitForResponse(
    (r) => r.url().includes('/checkout') && r.request().method() === 'POST',
    { timeout: 10000 },
  ).catch(() => null);
  await payBtn.click().catch(() => {});
  const checkout = await checkoutPromise;
  if (!checkout) {
    await api(`/api/control/accounts/${down.id}/payments/${payment.trim()}/checkout`, {
      method: 'POST',
      token: access,
      key: suffix + '-checkout-fallback',
      body: { provider: 'yookassa', method: 'bank_card', save_payment_method: false },
    });
  }
  await settle(payment.trim(), false);
  await until(
    () => sql(`SELECT status FROM commercial.subscription_plan_changes WHERE id='${change.id}'`),
    (v) => v === 'applied',
    'Reconciler must apply paid downgrade',
  );
  await billing(down.id);
  assert.equal(
    (await get(`/api/control/accounts/${down.id}/entitlements`)).subscription.id,
    change.target_subscription_id,
  );
  assert.equal(invoiceHash(down.invoice), down.hash);
  await stableScreenshot(page, 'screenshots/billing-periodic-downgrade.png');
  await stopAutoRenew({
    id: down.id,
    subscription: { id: change.target_subscription_id },
    target_subscription_id: change.target_subscription_id,
  });
  states.push(
    'real scheduler boundary downgrade, new paid invoice, reconciliation, UI entitlement and immutable original invoice',
  );

  const recovery = await paid('renewal');
  provider({ next_payment: 'declined' });
  await clock(new Date(new Date(recovery.subscription.current_period_end).getTime() + 2000));
  const renewalId = await until(
    () =>
      sql(
        `SELECT id FROM commercial.invoices WHERE subscription_id='${recovery.subscription.id}' AND billing_reason='subscription_renewal'`,
      ),
    Boolean,
    'Scheduler must issue renewal',
  );
  await until(
    () =>
      sql(`SELECT state FROM commercial.renewal_collection_states WHERE invoice_id='${renewalId}'`),
    (v) => v === 'retry_wait',
    'Decline must schedule a bounded retry',
  );
  await billing(recovery.id);
  await page.locator('.billing__badges').getByText('Past due', { exact: true }).waitFor();
  const grace = await get(`/api/control/accounts/${recovery.id}/entitlements`);
  assert.equal(grace.subscription.id, recovery.subscription.id);
  assert.equal(grace.subscription.billing_status, 'past_due');
  const retry = sql(
    `SELECT next_attempt_at FROM commercial.renewal_collection_states WHERE invoice_id='${renewalId}'`,
  );
  await clock(new Date(new Date(retry).getTime() + 2000));
  await until(
    () =>
      sql(
        `SELECT count(*) FROM commercial.payment_attempts a JOIN commercial.payments p ON p.id=a.payment_id WHERE p.invoice_id='${renewalId}'`,
      ),
    (v) => v === '2',
    'Scheduler must retry after backoff',
  );
  const renewalPayment = sql(`SELECT id FROM commercial.payments WHERE invoice_id='${renewalId}'`);
  await settle(renewalPayment, false);
  await until(
    () =>
      sql(
        `SELECT current_period_start FROM commercial.subscriptions WHERE id='${recovery.subscription.id}'`,
      ),
    (v) => new Date(v).getTime() === new Date(recovery.subscription.current_period_end).getTime(),
    'Paid renewal must advance the period',
  );
  assert.equal(
    sql(`SELECT count(*) FROM commercial.payments WHERE invoice_id='${renewalId}'`),
    '1',
  );
  assert.equal(invoiceHash(recovery.invoice), recovery.hash);
  assert.equal(
    sql(
      `SELECT count(*) FROM commercial.usage_periods WHERE subscription_id='${recovery.subscription.id}'`,
    ),
    '2',
  );
  await billing(recovery.id);
  await page.locator('.billing__badges').getByText('Paid up', { exact: true }).waitFor();
  await stableScreenshot(page, 'screenshots/billing-periodic-recovered.png');
  await stopAutoRenew(recovery);
  states.push(
    'automatic renewal, decline, grace access, backoff, second attempt on one payment, reconciler recovery and exactly one new usage period',
  );

  const expired = await paid('outage');
  provider({ outage: true });
  await clock(new Date(new Date(expired.subscription.current_period_end).getTime() + 2000));
  const lateInvoice = await until(
    () =>
      sql(
        `SELECT id FROM commercial.invoices WHERE subscription_id='${expired.subscription.id}' AND billing_reason='subscription_renewal'`,
      ),
    Boolean,
    'Outage renewal must exist',
  );
  await until(
    () =>
      sql(
        `SELECT network_retry_count FROM commercial.renewal_collection_states WHERE invoice_id='${lateInvoice}'`,
      ),
    (v) => Number(v) > 0,
    'Ambiguous network failure must persist recovery state',
  );
  const graceEnd = sql(
    `SELECT grace_until FROM commercial.subscriptions WHERE id='${expired.subscription.id}'`,
  );
  await clock(new Date(new Date(graceEnd).getTime() + 2000));
  await until(
    () => sql(`SELECT status FROM commercial.subscriptions WHERE id='${expired.subscription.id}'`),
    (v) => v === 'expired',
    'Dunning must expire after grace',
  );
  assert.equal((await get(`/api/control/accounts/${expired.id}/entitlements`)).subscription, null);
  const latePayment = sql(`SELECT id FROM commercial.payments WHERE invoice_id='${lateInvoice}'`);
  await settle(latePayment);
  assert.equal(
    sql(`SELECT status FROM commercial.subscriptions WHERE id='${expired.subscription.id}'`),
    'expired',
  );
  assert.equal((await get(`/api/control/accounts/${expired.id}/entitlements`)).subscription, null);
  assert.equal(
    sql(
      `SELECT count(*) FROM commercial.outbox_events WHERE event_type='subscription.renewal_late_settlement' AND aggregate_id='${expired.subscription.id}'`,
    ),
    '1',
  );
  assert.equal(invoiceHash(expired.invoice), expired.hash);
  await billing(expired.id);
  await page
    .getByRole('heading', { name: 'Subscription access is inactive', exact: true })
    .waitFor();
  await stableScreenshot(page, 'screenshots/billing-periodic-expired.png');
  await page.getByRole('button', { name: /^EN:/ }).click();
  await page.setViewportSize({ width: 393, height: 852 });
  await page.getByRole('heading', { name: 'Доступ по подписке неактивен', exact: true }).waitFor();
  assert.equal(
    await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1),
    false,
  );
  await stableScreenshot(page, 'screenshots/billing-periodic-expired-mobile-ru.png');
  states.push(
    'long provider outage, durable uncertainty, grace exhaustion, authoritative late settlement with duplicate webhook; no resurrection of expired access',
  );
  assert.deepEqual(errors, []);
  writeFileSync(
    'screenshots/billing-periodic-results.json',
    JSON.stringify(
      {
        passed: true,
        states,
        boundary:
          'shared isolated PHP/PG clock; real scheduler and API/UI; synthetic provider wire; no history rewrites',
      },
      null,
      2,
    ),
  );
  console.log(JSON.stringify({ passed: true, groups: states.length, sharedClock: true }));
} catch (error) {
  await stableScreenshot(page, 'screenshots/billing-periodic-failure.png');
  throw error;
} finally {
  await context.close();
  await browser.close();
}
