// Browser UX regression with explicit HTTP fixtures. Financial/provider correctness is tested by the backend suites.
import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import { launchBrowser, navigateClientSide, stableScreenshot } from './e2e-support.mjs';

const base = process.env.BASE_URL ?? 'http://127.0.0.1:5173';
const browser = await launchBrowser();
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
await context.addInitScript(() => { localStorage.setItem('platform.locale', 'en'); localStorage.setItem('platform.theme', 'dark'); });
const page = await context.newPage();
const errors = []; const states = [];
page.on('pageerror', error => errors.push(error.message));
mkdirSync('screenshots', { recursive: true });
const now = '2026-11-03T00:00:00Z';
const id = n => `019c0000-0000-7000-8000-${String(n).padStart(12, '0')}`;
const account = { id: id(1), code: 'fixture', display_name: 'Billing fixture', legal_name: 'Saved company', status: 'active', onboarding_state: 'ready_for_subscription', role: 'owner', created_at: now, updated_at: now,
  permissions: { read_account: true, view_members: true, manage_members: true, manage_ownership: true, manage_billing: true, manage_technical: true } };
let profile = { legal_name: 'Saved company', country_code: 'RU', contact_name: 'Saved contact', contact_email: 'fixture@example.test', tax_identifier: null, address: { city: 'Saved city', line1: '', postal_code: '' } };
const plan = { id: id(2), code: 'fixture', version: 1, name: 'Fixture plan', features: {}, prices: [{ id: id(3), currency: 'RUB', amount_minor: 1000, billing_period: 'month' }] };
const subscription = { id: id(4), status: 'draft', billing_status: 'current', past_due_since: null, grace_until: null, auto_renew_enabled: true, renewal_required: false,
  commercial_account_id: account.id, billing_profile_id: id(5), current_period_start: now, current_period_end: '2026-12-03T00:00:00Z', created_at: now, updated_at: now,
  items: [{ id: id(6), quantity: 3, valid_from: now, valid_until: null, plan, price: plan.prices[0] }], renewal: null };
const line = { id: id(7), subscription_item_id: id(6), plan_price_id: id(3), plan_code: 'fixture', plan_version: 1, plan_name: 'Fixture plan', billing_period: 'month', description: 'Fixture seats', quantity: 3, unit_amount_minor: 1000, total_minor: 3000, created_at: now };
const invoice = { id: id(8), subscription_id: subscription.id, billing_profile_id: id(5), billing_reason: 'subscription_create', service_period_start: now, service_period_end: subscription.current_period_end, status: 'issued', currency: 'RUB', total_minor: 3000, issued_at: now, paid_at: null, voided_at: null, created_at: now, bill_to: profile, lines: [line] };
const payment = { id: id(9), invoice_id: invoice.id, status: 'pending', currency: 'RUB', amount_minor: 3000, refunded_minor: 0, refundable_minor: 0, created_at: now, succeeded_at: null, cancelled_at: null, attempts: [] };
let refunds = [];
let checkoutCalls = 0; let subscriptionCreates = 0; let checkoutRelease; let profilePayload; let refundPayload;
let change = null;
const state = () => ({ billing_profile: profile, subscription_id: subscription.status === 'cancelled' ? null : subscription.id,
  pending_invoice_id: invoice.status === 'issued' ? invoice.id : null, pending_payment_id: payment.status === 'pending' ? payment.id : null,
  latest_invoice_id: invoice.id, latest_payment_id: payment.id, plan_change_id: change?.status === 'scheduled' ? change.id : null,
  history: { total: 2, limit: 25, offset: 0, items: [
    { kind: 'invoice', id: invoice.id, status: invoice.status, created_at: now, currency: 'RUB', amount_minor: 3000 },
    { kind: 'payment', id: payment.id, status: payment.status, created_at: now, currency: 'RUB', amount_minor: 3000 },
  ] } });
await context.route('**/api/**', async route => {
  const request = route.request(); const path = new URL(request.url()).pathname;
  if (!path.startsWith('/api/')) return route.continue();
  let data;
  if (path === '/api/control/auth/login') data = { access_token: 'fixture-only', refresh_token: 'fixture-only' };
  else if (path === '/api/control/me') data = { id: id(20), email: 'fixture@example.test', status: 'active', email_verified_at: now, last_login_at: now, created_at: now, updated_at: now };
  else if (path === '/api/control/accounts') data = { items: [account], total: 1, limit: 20, offset: 0 };
  else if (path === `/api/control/accounts/${account.id}`) data = account;
  else if (path === '/api/control/catalog/plans') data = { plans: [plan] };
  else if (path.endsWith('/billing-state')) {
    data = state(); const query = new URL(request.url()).searchParams.get('q');
    if (query) { data.history.items = data.history.items.filter(item => item.id.includes(query)); data.history.total = data.history.items.length; }
  } else if (path.endsWith('/billing-profile')) { profilePayload = request.postDataJSON(); profile = profilePayload; data = { id: id(5) }; }
  else if (path.endsWith('/entitlements')) data = { commercial_account_id: account.id, subscription: subscription.status === 'active' ? { id: subscription.id, current_period_start: now, current_period_end: subscription.current_period_end, billing_status: 'current', past_due_since: null, grace_until: null } : null, features: {} };
  else if (path.endsWith('/payment-methods')) data = { items: [] };
  else if (path.endsWith('/subscriptions') && request.method() === 'POST') { subscriptionCreates++; data = { id: subscription.id, status: 'draft' }; }
  else if (path.endsWith(`/subscriptions/${subscription.id}`)) data = subscription;
  else if (path.endsWith('/auto-renew/cancel')) { subscription.auto_renew_enabled = false; data = { id: subscription.id, auto_renew_enabled: false }; }
  else if (path.endsWith(`/subscriptions/${subscription.id}/cancel`)) { subscription.status = 'cancelled'; data = { id: subscription.id, status: 'cancelled' }; }
  else if (path.endsWith('/plan-changes') && request.method() === 'POST') {
    change = { id: id(30), source_subscription_id: subscription.id, target_subscription_id: id(31), old_plan_price_id: id(3), new_plan_price_id: id(3), old_quantity: 3, new_quantity: 2, change_kind: 'downgrade', status: 'scheduled', compensation_status: null, compensation_reason: null, compensation_origin: null, calculation: {}, currency: 'RUB', proration_net_minor: 0, amount_due_minor: 0, period_start: now, period_end: subscription.current_period_end, invoice_id: null, applied_at: null, invoice_status: null, payment_id: null, payment_status: null };
    data = { ...change };
  } else if (change && path.endsWith(`/plan-changes/${change.id}/cancel`)) { change.status = 'cancelled'; data = { id: change.id, status: 'cancelled' }; }
  else if (change && path.endsWith(`/plan-changes/${change.id}`)) data = change;
  else if (path.endsWith(`/invoices/${invoice.id}`)) data = invoice;
  else if (path.endsWith(`/payments/${payment.id}/checkout`)) {
    checkoutCalls++; await new Promise(resolve => { checkoutRelease = resolve; });
    data = { payment_id: payment.id, attempt_id: id(10), provider: 'yookassa', method: 'bank_card', status: 'pending', confirmation: null };
  } else if (path.endsWith(`/payments/${payment.id}/receipt`)) data = { id: id(12), payment_id: payment.id, type: 'payment', status: 'succeeded', external_receipt_id: 'fixture-only', amount_minor: 3000, currency: 'RUB', created_at: now, completed_at: now, items: [{ description: line.description, quantity: 3, unit_amount_minor: 1000, total_minor: 3000, currency: 'RUB', vat_code: 1, payment_subject: 'service', payment_mode: 'full_payment' }] };
  else if (path.endsWith(`/payments/${payment.id}/refunds`)) {
    if (request.method() === 'POST') {
      refundPayload = request.postDataJSON(); const item = refundPayload.items[0];
      const refund = { id: id(11), payment_id: payment.id, amount_minor: item.quantity * 1000, currency: 'RUB', status: 'pending', provider_refund_id: null, created_at: now, last_attempted_at: null, completed_at: null, receipt: null,
        items: [{ ...item, description: line.description, total_minor: item.quantity * 1000, currency: 'RUB', vat_code: 1, payment_subject: 'service', payment_mode: 'full_payment' }] };
      refunds.push(refund); payment.refundable_minor -= refund.amount_minor; data = refund;
    } else data = { items: refunds };
  } else if (refunds.some(refund => path.endsWith(`/refunds/${refund.id}`))) data = refunds.find(refund => path.endsWith(`/refunds/${refund.id}`));
  else if (path.endsWith(`/payments/${payment.id}`)) data = payment;
  else return route.fulfill({ status: 404, json: { error: { code: 'resource_not_found', message: 'Fixture route not defined' } } });
  await route.fulfill({ status: 200, json: data });
});
async function signIn() {
  await page.goto(base + '/login'); await page.locator('#login-email').fill('fixture@example.test'); await page.locator('#login-password').fill('Fixture-only-password');
  await page.locator('form button[type=submit]').click(); await page.waitForURL(url => url.pathname === '/cabinet');
  await navigateClientSide(page, `/accounts/${account.id}/billing`);
  await page.locator('#billing-legal-name').waitFor();
}
try {
  await signIn();
  await page.setViewportSize({ width: 320, height: 740 });
  await page.waitForFunction(() => {
    const strip = document.querySelector('.account-layout__tabs');
    const active = strip?.querySelector('[aria-current="page"]');
    if (!active || !(strip.scrollWidth > strip.clientWidth)) return false;
    const bounds = strip.getBoundingClientRect();
    const tab = active.getBoundingClientRect();
    return bounds.left <= tab.left + 1 && bounds.right >= tab.right - 1;
  }, undefined, { timeout: 3000 });
  states.push('mobile billing opens with the active account section visible');
  await page.setViewportSize({ width: 1440, height: 900 });

  const legalName = page.locator('#billing-legal-name');
  await legalName.fill('Unsaved billing draft');
  const country = page.locator('#billing-country');
  await country.fill('R');
  await page.getByRole('button', { name: 'Save billing profile', exact: true }).click();
  await page.getByRole('alert').filter({ hasText: 'Enter a legal name and a two-letter country code.' }).waitFor();
  const billingPath = new URL(page.url()).pathname;
  await page.getByRole('button', { name: 'EN: Change language' }).click();
  await page.waitForFunction(() => document.documentElement.lang === 'ru');
  assert.equal(new URL(page.url()).pathname, billingPath);
  assert.equal(await legalName.inputValue(), 'Unsaved billing draft');
  assert.equal(await country.inputValue(), 'R');
  // The message is fixed when validation runs, so an already shown alert keeps its language after the switch.
  assert.match(await page.getByRole('alert').first().innerText(), /Enter a legal name and a two-letter country code\./);
  await page.getByRole('button', { name: 'Сохранить профиль для счетов', exact: true }).click();
  await page.getByRole('alert').filter({ hasText: 'Укажите юридическое название и двухбуквенный код страны.' }).waitFor();
  await page.getByText('месяц · ×3', { exact: true }).waitFor();
  await page.getByRole('button', { name: 'RU: Сменить язык' }).click();
  await page.waitForFunction(() => document.documentElement.lang === 'en');
  assert.equal(await legalName.inputValue(), 'Unsaved billing draft');
  await page.getByRole('button', { name: 'Save billing profile', exact: true }).click();
  await page.getByRole('alert').filter({ hasText: 'Enter a legal name and a two-letter country code.' }).waitFor();
  await page.getByText('month · ×3', { exact: true }).waitFor();
  await legalName.fill('Saved company');
  await country.fill('RU');
  states.push('billing period and validation follow RU/EN: re-validation speaks the active language and keeps the unfinished draft');

  assert.equal(await page.locator('#billing-legal-name').inputValue(), 'Saved company');
  await page.locator('#billing-legal-name').fill('Edited company'); await page.getByRole('button', { name: 'Save billing profile', exact: true }).click();
  await page.getByText('Billing profile saved.', { exact: true }).waitFor(); assert.equal(profilePayload.legal_name, 'Edited company');
  states.push('profile restored; edits survive save and authoritative reload');
  await page.getByRole('button', { name: 'Continue the existing operation', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[aria-busy=true]'));
  assert.equal(await page.getByRole('button', { name: 'Continue the existing operation', exact: true }).isDisabled(), true);
  checkoutRelease(); await page.getByText('Payment page is ready.', { exact: true }).waitFor();
  assert.equal(checkoutCalls, 1); assert.equal(subscriptionCreates, 0);
  await signIn(); assert.equal(await page.locator('#billing-legal-name').inputValue(), 'Edited company');
  await page.getByText('An unfinished billing operation', { exact: true }).waitFor();
  assert.equal(subscriptionCreates, 0); states.push('unfinished checkout survives reload; no duplicate subscription; duplicate-submit guard');
  payment.status = 'succeeded'; payment.succeeded_at = now; payment.refundable_minor = 3000; invoice.status = 'paid'; invoice.paid_at = now; subscription.status = 'active';
  await page.getByRole('button', { name: 'Check server status', exact: true }).click();
  await page.getByText('An unfinished billing operation', { exact: true }).waitFor({ state: 'hidden' });
  await page.locator('.billing__history li').filter({ hasText: 'Payment' }).getByRole('button').click();
  await page.getByRole('heading', { name: `Payment ${payment.id}`, exact: true }).waitFor();
  await page.getByRole('button', { name: 'Refund payment', exact: true }).click();
  const refundDialog = page.getByRole('dialog').filter({ hasText: 'Choose items and quantities' });
  await refundDialog.locator('input[type=number]').fill('1'); await refundDialog.getByRole('button', { name: 'Refund payment', exact: true }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Refund payment', exact: true }).click();
  await page.getByText('Refund created.', { exact: true }).waitFor(); assert.equal(refundPayload.items[0].quantity, 1);
  await page.getByRole('button', { name: 'Refund payment', exact: true }).click();
  await refundDialog.waitFor(); assert.equal(await refundDialog.locator('input[type=number]').getAttribute('max'), '2');
  await page.keyboard.press('Escape'); states.push('settlement requires server refresh; partial refund and pending quantity reservation');
  refunds[0].receipt = { id: id(13), status: 'pending', external_receipt_id: null, created_at: now, completed_at: null };
  await page.getByRole('button', { name: 'Refunds', exact: true }).click();
  await page.getByRole('button', { name: `View refund ${id(11)}`, exact: true }).click();
  const refundDetails = page.getByRole('region', { name: `Refund details ${id(11)}`, exact: true });
  await refundDetails.getByText('Fixture seats × 1', { exact: true }).waitFor();
  await refundDetails.getByText(`Receipt ${id(13)} · Pending`, { exact: true }).waitFor();
  states.push('refund detail shows the selected quantity and linked receipt status');
  await page.getByRole('button', { name: 'Receipt', exact: true }).click(); await page.getByRole('heading', { name: `Receipt ${id(12)}`, exact: true }).waitFor();
  await page.locator('#billing-history-query').fill('zz'); await page.getByRole('button', { name: 'Find', exact: true }).click();
  await page.getByText(/Enter the document number shown/).waitFor();
  await page.locator('#billing-history-query').fill(payment.id.slice(-8)); await page.getByRole('button', { name: 'Find', exact: true }).click();
  await page.waitForFunction(() => document.querySelectorAll('.billing__history li').length === 1);
  await page.getByRole('button', { name: 'Cancel auto-renewal', exact: true }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Cancel auto-renewal', exact: true }).click();
  await page.getByText('Auto-renewal is disabled.', { exact: true }).waitFor(); assert.equal(subscription.status, 'active');
  states.push('receipt, history search, period-end auto-renew cancellation preserves active access');
  await page.getByRole('button', { name: 'EN: Change language' }).click();
  await page.locator('#billing-plan').click();
  await page.getByRole('menuitemradio', { name: /Fixture plan · месяц/u }).waitFor();
  await page.keyboard.press('Escape');
  await page.getByRole('button', { name: 'RU: Сменить язык' }).click();
  await page.locator('#billing-quantity').fill('2');
  await page.getByRole('button', { name: 'Request plan change', exact: true }).click();
  await page.getByText('Plan change requested.', { exact: true }).waitFor();
  await page.getByRole('button', { name: 'Cancel change', exact: true }).click();
  await page.getByText('Plan change cancelled.', { exact: true }).waitFor();
  assert.equal(change.status, 'cancelled');
  states.push('scheduled downgrade and cancellation preserve the active subscription');
  // Inspect the persisted page after the temporary action confirmations expire.
  await page.waitForFunction(() => document.querySelectorAll('.app-toast').length === 0);
  for (const width of [393, 768, 1024, 1440]) {
    await page.setViewportSize({ width, height: 1000 });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, `Billing overflow at ${width}`);
    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
    assert.deepEqual(axe.violations.map(v => v.id), []);
    await stableScreenshot(page, `screenshots/billing-${width}.png`);
  }
  await page.getByRole('button', { name: 'End access now', exact: true }).click();
  await page.getByRole('dialog').getByText(/Access ends immediately/).waitFor();
  await page.getByRole('dialog').getByRole('button', { name: 'End access now', exact: true }).click();
  await page.getByText('Subscription cancelled.', { exact: true }).waitFor();
  assert.equal(subscription.status, 'cancelled');
  await page.getByRole('heading', { name: 'Subscription access is inactive', exact: true }).waitFor();
  states.push('immediate cancellation has explicit immediate-access warning and separate refund semantics');
  assert.deepEqual(errors, []); writeFileSync('screenshots/billing-results.json', JSON.stringify({ passed: true, fixture: 'HTTP UI only', states }, null, 2));
  console.log(JSON.stringify({ passed: true, fixture: 'HTTP UI only', states }));
} catch (error) {
  console.error('Billing browser state:', await page.locator('[role=alert]').allTextContents(), errors);
  await stableScreenshot(page, 'screenshots/billing-failure.png'); throw error;
} finally { await context.close(); await browser.close(); }
