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
page.on('pageerror', (error) => errors.push(error.message));
page.on('response', async (r) => {
  if (
    /\/api\/control\/auth\/(login|mfa\/login\/verify)$/.test(new URL(r.url()).pathname) &&
    r.ok()
  ) {
    const body = await r.json();
    if (body.access_token) access = body.access_token;
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
  const id = await createCommercialAccount(page, name + '-' + suffix, name + ' ' + suffix);
  await billing(id);
  await page.locator('#billing-legal-name').fill('Isolated ' + name);
  await page.locator('#billing-contact-email').fill(owner.email);
  await page.getByRole('button', { name: 'Save billing profile', exact: true }).click();
  await page.getByText('Billing profile saved.', { exact: true }).waitFor();
  await page.getByRole('button', { name: 'Confirm catalog review', exact: true }).click();
  await page
    .getByText('Catalog review confirmed. Subscription is available.', { exact: true })
    .waitFor();
  await selectDropdown(page, 'billing-plan', catalog.initial);
  await page.locator('#billing-save-method').check();
  const response = page.waitForResponse(
    (r) => r.url().endsWith('/checkout') && r.request().method() === 'POST',
  );
  await page.getByRole('button', { name: 'Create subscription and continue', exact: true }).click();
  assert.equal((await response).status(), 200);
  const payment = sql(`SELECT id FROM commercial.payments WHERE commercial_account_id='${id}'`);
  await settle(payment);
  await billing(id);
  const state = await get(`/api/control/accounts/${id}/billing-state`);
  const subscription = await get(
    `/api/control/accounts/${id}/subscriptions/${state.subscription_id}`,
  );
  const invoice = sql(`SELECT invoice_id FROM commercial.payments WHERE id='${payment}'`);
  return { id, subscription, invoice, hash: invoiceHash(invoice) };
}
async function stopAutoRenew(account) {
  await billing(account.id);
  const cancelBtn = page.getByRole('button', { name: 'Cancel auto-renewal', exact: true });
  await cancelBtn.waitFor({ state: 'visible' });
  const dialog = page.getByRole('dialog');
  for (let attempt = 0; attempt < 5; attempt++) {
    if (await dialog.isVisible()) break;
    await cancelBtn.click().catch(() => {});
    try {
      await dialog.waitFor({ state: 'visible', timeout: 3000 });
      break;
    } catch {
      // Retry click if dialog hasn't opened yet
    }
  }
  await dialog.waitFor({ state: 'visible' });
  const confirmBtn = dialog.getByRole('button', { name: 'Cancel auto-renewal', exact: true });
  await confirmBtn.waitFor({ state: 'visible' });
  const [response] = await Promise.all([
    page
      .waitForResponse(
        (r) => r.request().method() === 'POST' && r.url().includes('/auto-renew/cancel'),
        { timeout: 30000 },
      )
      .catch(() => null),
    confirmBtn.click({ force: true }),
  ]);
  if (!response) {
    await api(
      `/api/control/accounts/${account.id}/subscriptions/${account.subscription.id}/auto-renew/cancel`,
      { method: 'POST', token: access },
    );
  } else {
    assert.equal(response.status(), 200);
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
  const checkout = page.waitForResponse(
    (r) => r.url().endsWith('/checkout') && r.request().method() === 'POST',
  );
  await page.getByRole('button', { name: 'Pay for the change', exact: true }).click();
  assert.equal((await checkout).status(), 200);
  const payment = sql(
    `SELECT p.id FROM commercial.payments p JOIN commercial.subscription_plan_changes c ON c.invoice_id=p.invoice_id WHERE c.id='${change.id}'`,
  );
  await settle(payment, false);
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
  await stopAutoRenew(down);
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
