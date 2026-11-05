// Real HTTP/PG/SMTP/MFA and provider gateway parsers; only the external provider wire is synthetic.
import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync } from 'node:fs';
import { randomUUID } from 'node:crypto';
import { guard, actor, uiLogin, fixture, api, sql, provider, command, env, suffix, observations } from './acceptance-support.mjs';
import { launchBrowser, createCommercialAccount, navigateClientSide, selectDropdown, stableScreenshot } from './e2e-support.mjs';

await guard();
const catalog = fixture('catalog');
const owner = await actor('paid-owner');
const browser = await launchBrowser(env.E2E_LOOPBACK_ALIAS === '1' ? { args: ['--host-resolver-rules=MAP e2e.platform.test 127.0.0.1'] } : {});
const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } });
await context.addInitScript(() => { localStorage.setItem('platform.locale', 'en'); localStorage.setItem('platform.theme', 'dark'); });
const page = await context.newPage();
const errors = []; const states = [];
let access;
let checkoutRequest;
page.on('pageerror', (error) => errors.push(error.message));
page.on('response', async (response) => {
  const path = new URL(response.url()).pathname;
  if (/\/api\/control\/auth\/(login|mfa\/login\/verify)$/.test(path) && response.ok()) {
    const value = await response.json().catch(() => null); if (value?.access_token) access = value.access_token;
  }
  if (path.endsWith('/checkout') && response.request().method() === 'POST') {
    checkoutRequest = { path, key: response.request().headers()['idempotency-key'], body: response.request().postDataJSON() };
  }
});
// The synthetic provider page is the only browser route fixture; application API requests are untouched.
await context.route('https://provider.e2e.test/**', (route) => route.fulfill({ contentType: 'text/html', body: '<h1>Synthetic provider confirmation</h1><p>No real charge is made.</p>' }));
mkdirSync('screenshots', { recursive: true });
const get = (path) => api(path, { token: access });
async function billing(account) {
  await navigateClientSide(page, '/cabinet');
  await navigateClientSide(page, `/accounts/${account}/billing`);
  await page.locator('#billing-legal-name').waitFor();
}
async function settle(paymentId) {
  const pair = Object.entries(provider().payments ?? {}).find(([, payment]) => payment.metadata.payment_id === paymentId);
  assert.ok(pair, 'Provider must know the real local payment');
  const [id] = pair;
  const updated = provider({ payments: { [id]: { status: 'succeeded', paid: true, captured_at: new Date().toISOString() } } });
  const body = { type: 'notification', event: 'payment.succeeded', object: updated.payments[id] };
  await api('/api/control/webhooks/yookassa', { method: 'POST', body });
  await api('/api/control/webhooks/yookassa', { method: 'POST', body });
  return id;
}
try {
  await uiLogin(page, owner);
  const account = await createCommercialAccount(page, `paid-${suffix}`, `Paid ${suffix}`);
  await billing(account);
  await page.locator('#billing-legal-name').fill('Isolated paid customer');
  await page.locator('#billing-contact-email').fill(owner.email);
  await page.getByRole('button', { name: 'Save billing profile', exact: true }).click();
  await page.getByText('Billing profile saved.', { exact: true }).waitFor();
  await page.getByRole('button', { name: 'Confirm catalog review', exact: true }).click();
  await page.getByText('Catalog review confirmed. Subscription is available.', { exact: true }).waitFor();
  await selectDropdown(page, 'billing-plan', catalog.initial);
  await page.locator('#billing-quantity').fill('2');
  await page.locator('#billing-save-method').check();
  const checkout = page.waitForResponse((r) => r.url().endsWith('/checkout') && r.request().method() === 'POST');
  await page.getByRole('button', { name: 'Create subscription and continue', exact: true }).click();
  const checkoutResponse = await checkout;
  const checkoutValue = await checkoutResponse.json();
  assert.equal(checkoutResponse.status(), 200, checkoutValue?.error?.code);
  assert.equal(checkoutValue.status, 'pending');
  const pending = await get(`/api/control/accounts/${account}/billing-state`);
  assert.ok(pending.pending_invoice_id && pending.pending_payment_id);
  const invoice = await get(`/api/control/accounts/${account}/invoices/${pending.pending_invoice_id}`);
  const payment = await get(`/api/control/accounts/${account}/payments/${pending.pending_payment_id}`);
  assert.equal(invoice.total_minor, 2000); assert.equal(payment.amount_minor, 2000); assert.equal(payment.status, 'pending');
  assert.equal((await get(`/api/control/accounts/${account}/entitlements`)).subscription, null);
  const replay = await api(checkoutRequest.path, { method: 'POST', token: access, key: checkoutRequest.key, body: checkoutRequest.body });
  assert.equal(replay.attempt_id, checkoutValue.attempt_id);
  assert.equal(Object.values(provider().payments).filter((item) => item.metadata.payment_id === payment.id).length, 1);
  states.push('paid checkout via UI; exact invoice/payment DTO, amount and no entitlement before settlement; same-key replay creates one provider payment');

  const second = await context.newPage();
  await uiLogin(second, owner);
  await navigateClientSide(second, `/accounts/${account}/billing`);
  await second.locator('#billing-legal-name').waitFor();
  assert.equal(sql(`SELECT count(*) FROM commercial.invoices WHERE commercial_account_id='${account}'`), '1');
  await second.close();
  await stableScreenshot(page, 'screenshots/billing-paid-pending.png');
  await page.getByRole('button', { name: 'Open payment page', exact: true }).first().click();
  await page.getByRole('heading', { name: 'Synthetic provider confirmation' }).waitFor();
  const providerId = await settle(payment.id);
  await uiLogin(page, owner);
  await billing(account);
  await page.locator('.billing__badges').getByText('Active', { exact: true }).waitFor();
  const initial = await get(`/api/control/accounts/${account}/billing-state`);
  assert.ok(initial.subscription_id); assert.equal(initial.pending_payment_id, null);
  assert.equal((await get(`/api/control/accounts/${account}/payments/${payment.id}`)).status, 'succeeded');
  assert.equal((await get(`/api/control/accounts/${account}/payments/${payment.id}/receipt`)).status, 'succeeded');
  assert.equal(sql(`SELECT count(*) FROM commercial.saved_payment_methods WHERE commercial_account_id='${account}' AND status='active'`), '1');
  await api('/api/control/webhooks/yookassa', { method: 'POST', body: { type: 'notification', event: 'payment.waiting_for_capture', object: { id: providerId } } });
  assert.equal((await get(`/api/control/accounts/${account}/payments/${payment.id}`)).status, 'succeeded');
  states.push('second-tab recovery; external confirmation page; authoritative webhook fetch, duplicate/reordered webhook, succeeded receipt and saved card; entitlement survives reload/login');

  await selectDropdown(page, 'billing-plan', catalog.upgrade);
  await page.locator('#billing-quantity').fill('2');
  const changeResponse = page.waitForResponse((r) => /\/plan-changes$/.test(r.url()) && r.request().method() === 'POST');
  await page.getByRole('button', { name: 'Request plan change', exact: true }).click();
  const change = await (await changeResponse).json();
  assert.equal(change.status, 'payment_required');
  await page.getByRole('button', { name: 'Pay for the change', exact: true }).click();
  await page.getByRole('button', { name: 'Open payment page', exact: true }).first().waitFor();
  const changed = await get(`/api/control/accounts/${account}/plan-changes/${change.id}`);
  assert.ok(changed.amount_due_minor > 0 && changed.amount_due_minor <= 2000);
  const upgradePaymentId = sql(`SELECT id FROM commercial.payments WHERE invoice_id='${changed.invoice_id}'`);
  await settle(upgradePaymentId);
  await billing(account);
  const applied = await get(`/api/control/accounts/${account}/plan-changes/${change.id}`);
  assert.equal(applied.status, 'applied');
  assert.equal(sql(`SELECT count(*) FROM commercial.saved_payment_methods WHERE commercial_account_id='${account}' AND is_default`), '1');
  assert.equal((await get(`/api/control/accounts/${account}/entitlements`)).subscription.id, applied.target_subscription_id);
  assert.equal((await get(`/api/control/accounts/${account}/invoices/${invoice.id}`)).total_minor, 2000);
  states.push('paid upgrade via UI and authoritative settlement; new entitlement and unchanged historical invoice');

  await selectDropdown(page, 'billing-plan', catalog.downgrade);
  const downgradeResponse = page.waitForResponse((r) => /\/plan-changes$/.test(r.url()) && r.request().method() === 'POST');
  await page.getByRole('button', { name: 'Request plan change', exact: true }).click();
  const downgrade = await (await downgradeResponse).json();
  assert.equal(downgrade.status, 'scheduled');
  assert.equal((await get(`/api/control/accounts/${account}/entitlements`)).subscription.id, applied.target_subscription_id);
  const cancelled = page.waitForResponse((r) => r.url().endsWith(`/plan-changes/${downgrade.id}/cancel`) && r.request().method() === 'POST');
  await page.getByRole('button', { name: 'Cancel change', exact: true }).click();
  assert.equal((await cancelled).status(), 200);
  assert.equal((await get(`/api/control/accounts/${account}/plan-changes/${downgrade.id}`)).status, 'cancelled');
  states.push('downgrade schedules at the period boundary, keeps current access and cancels via the UI; time itself is not advanced');

  // Refund allocations are real HTTP/DB mutations; the provider refund and fiscal wire remain synthetic.
  const line = invoice.lines.find((item) => item.unit_amount_minor > 0);
  assert.ok(line);
  for (let index = 0; index < 2; index++) {
    const refund = await api(`/api/control/accounts/${account}/payments/${payment.id}/refunds`, { method: 'POST', token: access, expected: 201, key: randomUUID(), body: { items: [{ invoice_line_id: line.id, quantity: 1, unit_amount_minor: line.unit_amount_minor }] } });
    command('control:payments:reconcile-yookassa-refunds');
    const read = await get(`/api/control/accounts/${account}/refunds/${refund.id}`);
    assert.equal(read.status, 'succeeded'); assert.equal(read.amount_minor, 1000);
  }
  const refunds = await get(`/api/control/accounts/${account}/payments/${payment.id}/refunds`);
  assert.equal(refunds.items.filter((item) => item.status === 'succeeded').reduce((sum, item) => sum + item.amount_minor, 0), 2000);
  await api(`/api/control/accounts/${account}/payments/${payment.id}/refunds`, { method: 'POST', token: access, expected: 409, key: randomUUID(), body: { items: [{ invoice_line_id: line.id, quantity: 1, unit_amount_minor: line.unit_amount_minor }] } });
  assert.equal((await get(`/api/control/accounts/${account}/invoices/${invoice.id}`)).status, 'paid');
  assert.equal((await get(`/api/control/accounts/${account}/entitlements`)).subscription.id, applied.target_subscription_id);
  await stableScreenshot(page, 'screenshots/billing-paid-active.png');
  states.push('partial plus remaining full refund, successful provider/fiscal processing, exact 2000 minor units, immutable paid invoice and independent active entitlement');
  for (const mode of ['uncertain', 'declined', 'capture']) {
    await navigateClientSide(page, '/cabinet');
    const target = await createCommercialAccount(page, `${mode}-${suffix}`, `${mode} ${suffix}`);
    await billing(target);
    await page.locator('#billing-legal-name').fill('Isolated ' + mode);
    await page.locator('#billing-contact-email').fill(owner.email);
    await page.getByRole('button', { name: 'Save billing profile', exact: true }).click();
    await page.getByText('Billing profile saved.', { exact: true }).waitFor();
    await page.getByRole('button', { name: 'Confirm catalog review', exact: true }).click();
    await page.getByText('Catalog review confirmed. Subscription is available.', { exact: true }).waitFor();
    await selectDropdown(page, 'billing-plan', catalog.initial);
    provider({ next_payment: mode });
    const response = page.waitForResponse((r) => r.url().endsWith('/checkout') && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'Create subscription and continue', exact: true }).click();
    const result = await response; assert.equal(result.status(), mode === 'uncertain' ? 503 : 200, mode + ': ' + JSON.stringify(await result.json()));
    const request = { path: new URL(result.url()).pathname, key: result.request().headers()['idempotency-key'], body: result.request().postDataJSON() };
    const id = request.path.split('/payments/')[1].split('/')[0];
    assert.equal((await get(`/api/control/accounts/${target}/entitlements`)).subscription, null);
    const attempt = sql(`SELECT status FROM commercial.payment_attempts WHERE payment_id='${id}' ORDER BY id DESC LIMIT 1`);
    // Capture is nonterminal at the local attempt boundary, so it stays pending.
    assert.equal(attempt, mode === 'declined' ? 'cancelled' : 'pending');
    if (mode === 'uncertain') {
      const recovered = await api(request.path, { method: 'POST', token: access, key: request.key, body: request.body });
      assert.equal(recovered.status, 'pending');
    } else assert.equal((await result.json()).status, mode === 'declined' ? 'cancelled' : 'waiting_for_capture');
    assert.equal(Object.values(provider().payments).filter((item) => item.metadata.payment_id === id).length, 1);
    if (mode !== 'declined') {
      await settle(id); await billing(target);
      await page.locator('.billing__badges').getByText('Active', { exact: true }).waitFor();
    }
  }
  states.push('real gateway uncertain response with same-key recovery, authoritative decline and waiting-for-capture; none grants early entitlement; settlement converges without a second provider payment; over-refund denied');
  assert.deepEqual(errors, []);
  writeFileSync('screenshots/billing-paid-results.json', JSON.stringify({ passed: true, boundary: 'real application HTTP/PG/SMTP/MFA; synthetic provider wire; no real charge', states, observations }, null, 2));
  console.log(JSON.stringify({ passed: true, groups: states.length, boundary: 'real backend; synthetic provider wire' }));
} catch (error) {
  await stableScreenshot(page, 'screenshots/billing-paid-failure.png');
  console.error('Paid lifecycle failure:', new URL(page.url()).pathname, await page.locator('[role=alert]').allTextContents());
  throw error;
} finally { await context.close(); await browser.close(); }
