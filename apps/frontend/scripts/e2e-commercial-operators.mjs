// Two independent real operators, real MFA/HTTP/PG. Only the initial paid recovery case is synthetic setup.
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';
import { guard, actor, uiLogin, fixture, api, sql, env, suffix, observations } from './acceptance-support.mjs';
import { launchBrowser, stableScreenshot } from './e2e-support.mjs';
await guard();
const owner = await actor('operator-owner'); const first = await actor('operator-first'); const second = await actor('operator-second');
for (const operator of [first, second]) {
  sql(`INSERT INTO commercial.financial_operators(control_user_id,authorization_reference) VALUES ('${operator.id}','urn:test:financial-authorization'); INSERT INTO commercial.privacy_operators(control_user_id,authorization_reference) VALUES ('${operator.id}','urn:test:privacy-authorization');`);
}
await api('/api/control/operator/me', { token: owner.token, expected: 403 });
fixture('catalog');
const recovery = fixture('recovery', { owner: owner.id });
const browser = await launchBrowser(env.E2E_LOOPBACK_ALIAS === '1' ? { args: ['--host-resolver-rules=MAP e2e.platform.test 127.0.0.1'] } : {});
const contexts = await Promise.all([0, 1].map(async () => {
  const c = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 1000 } });
  await c.addInitScript(() => { localStorage.setItem('platform.locale', 'en'); localStorage.setItem('platform.theme', 'dark'); }); return c;
}));
const pages = await Promise.all(contexts.map((c) => c.newPage()));
const tokens = []; const errors = []; const states = [];
for (const [index, page] of pages.entries()) {
  page.on('pageerror', (error) => errors.push(error.message));
  page.on('response', async (r) => { if (new URL(r.url()).pathname === '/api/control/auth/mfa/login/verify' && r.ok()) tokens[index] = (await r.json()).access_token; });
}
const [a, b] = pages;
const hash = createHash('sha256').update('Synthetic external transfer evidence ' + suffix).digest('hex');
const evidence = { externalTransactionReference: 'urn:test:transfer:' + suffix, documentReference: 'urn:test:document:' + suffix, documentSha256: hash };
mkdirSync('screenshots', { recursive: true });
async function tab(page, name) { await page.locator('.operator-console__tabs').getByRole('button', { name, exact: true }).click(); }
async function fill(page, values) { for (const [name, value] of Object.entries(values)) await page.getByRole('dialog').locator(`[data-field="${name}"] input`).fill(value); }
async function confirm(page) { const d = page.getByRole('dialog'); const check = d.getByRole('checkbox', { name: 'I checked the target and confirm this action.', exact: true }); if (await check.count()) await check.check(); }
async function save(page, expected = [200, 201]) {
  await confirm(page);
  const response = page.waitForResponse((r) => r.request().method() === 'POST' && /\/api\/control\/(finance|privacy\/operator)\//.test(new URL(r.url()).pathname));
  await page.getByRole('dialog').getByRole('button', { name: 'Save', exact: true }).click();
  const result = await response; assert.ok(expected.includes(result.status()), 'Operator write HTTP ' + result.status()); return result;
}
async function dismiss(page) { await page.locator('.app-dialog--full').waitFor(); await page.keyboard.press('Escape'); await page.getByRole('dialog').waitFor({ state: 'hidden' }); }
try {
  await uiLogin(a, first, true); await uiLogin(b, second, true);
  await tab(a, 'External compensation');
  await a.getByRole('button', { name: 'Propose compensation', exact: true }).first().click(); await fill(a, evidence);
  sql(`UPDATE commercial.control_sessions SET primary_authenticated_at=now()-interval '1 hour',mfa_authenticated_at=now()-interval '1 hour' WHERE control_user_id='${first.id}' AND revoked_at IS NULL AND mfa_authenticated_at IS NOT NULL`);
  const step = await save(a, [403]); assert.ok(['recent_authentication_required', 'recent_mfa_required'].includes((await step.json()).error.code));
  const security = a.getByRole('dialog').locator('.operator-security'); await security.locator('.app-disclosure__trigger').click();
  await security.locator('input[type=password]').fill(env.E2E_PASSWORD);
  await security.getByRole('checkbox', { name: 'Use a recovery code', exact: true }).check();
  await security.getByRole('textbox', { name: 'Authenticator or recovery code', exact: true }).fill(first.recovery.shift());
  const reauth = a.waitForResponse((r) => r.url().endsWith('/auth/reauthenticate'));
  await security.getByRole('button', { name: 'Confirm operator identity', exact: true }).click(); assert.equal((await reauth).status(), 200);
  assert.equal(sql(`SELECT count(*) FROM commercial.external_compensation_attestations WHERE plan_change_id='${recovery.change_id}'`), '0', 'Step-up must not auto-submit a financial action');
  assert.equal(await a.getByRole('dialog').locator('[data-field=documentSha256] input').inputValue(), hash);
  await save(a); await dismiss(a);
  let proposal = JSON.parse(sql(`SELECT json_build_object('id',id,'status',status) FROM commercial.external_compensation_attestations WHERE plan_change_id='${recovery.change_id}' ORDER BY proposed_at DESC LIMIT 1`));
  assert.equal(proposal.status, 'proposed');
  await a.getByRole('button', { name: 'View attestation', exact: true }).first().click();
  const current = a.waitForResponse((r) => r.request().method() === 'GET' && r.url().endsWith('/finance/plan-change-compensations/' + recovery.change_id));
  await a.getByRole('dialog').getByRole('button', { name: 'Open', exact: true }).click();
  assert.equal((await current).status(), 200); await dismiss(a);
  await api(`/api/control/finance/plan-change-compensations/attestations/${proposal.id}/approve`, { method: 'POST', token: tokens[0], body: evidence, expected: [403, 404] });
  states.push('two real MFA operators; expired authentication preserves evidence; explicit re-submit after step-up; self-review denied by HTTP');

  await tab(b, 'External compensation'); await b.getByRole('button', { name: 'Approve compensation', exact: true }).first().click();
  assert.equal(await b.getByRole('dialog').locator('[data-field=documentSha256] input').isEditable(), false);
  sql(`UPDATE commercial.financial_operators SET revoked_at=now() WHERE control_user_id='${second.id}'`);
  await save(b, [403, 404]);
  await b.getByRole('dialog').waitFor({ state: 'hidden' });
  assert.equal(await b.getByRole('button', { name: 'External compensation', exact: true }).count(), 0);
  assert.equal(sql(`SELECT status FROM commercial.external_compensation_attestations WHERE id='${proposal.id}'`), 'proposed');
  sql(`UPDATE commercial.financial_operators SET revoked_at=NULL WHERE control_user_id='${second.id}'`);
  await b.getByRole('button', { name: 'Sign out operator', exact: true }).click(); await uiLogin(b, second, true); await tab(b, 'External compensation');
  await api(`/api/control/finance/plan-change-compensations/attestations/${proposal.id}/approve`, { method: 'POST', token: tokens[1], body: { ...evidence, documentSha256: '0'.repeat(64) }, expected: 409 });
  await b.getByRole('button', { name: 'Reject compensation', exact: true }).first().click(); await fill(b, { rejectionReason: 'Document requires correction.' }); await save(b); await dismiss(b);
  assert.equal(sql(`SELECT status FROM commercial.external_compensation_attestations WHERE id='${proposal.id}'`), 'rejected');
  assert.equal(sql(`SELECT compensation_status FROM commercial.subscription_plan_changes WHERE id='${recovery.change_id}'`), 'recovery_required');
  await a.getByRole('button', { name: 'Refresh', exact: true }).click(); await a.getByRole('button', { name: 'Propose compensation', exact: true }).first().click(); await fill(a, evidence); await save(a); await dismiss(a);
  proposal = JSON.parse(sql(`SELECT json_build_object('id',id,'status',status) FROM commercial.external_compensation_attestations WHERE plan_change_id='${recovery.change_id}' ORDER BY proposed_at DESC LIMIT 1`));
  await b.getByRole('button', { name: 'Refresh', exact: true }).click(); await b.getByRole('button', { name: 'Approve compensation', exact: true }).first().click(); await save(b); await dismiss(b);
  assert.equal(sql(`SELECT status||'/'||compensation_status FROM commercial.subscription_plan_changes WHERE id='${recovery.change_id}'`), 'refunded/succeeded');
  assert.equal(sql(`SELECT count(*) FROM commercial.refunds WHERE payment_id='${recovery.payment_id}'`), '0');
  await stableScreenshot(b, 'screenshots/operators-real-finance.png');
  states.push('permission revoked during open review evicts the form and finance UI; wrong hash rejected; reject/correct/repropose/independent approve reaches refunded once');

  await tab(a, 'Subject requests'); await a.getByRole('button', { name: 'Register a request', exact: true }).click();
  const d = a.getByRole('dialog');
  async function choose(name, value) { await d.locator(`[data-field=${name}] .app-dropdown__trigger`).click(); await a.getByRole('menu').last().locator(`[data-menu-id="${value}"]`).click(); }
  await choose('subjectKind', 'other'); await choose('requestType', 'access'); await choose('sourceChannel', 'paper');
  const now = new Date(Date.now() - 60000); const local = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}T${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}`;
  await fill(a, { subjectReference: 'urn:test:subject:' + suffix, identityEvidenceReference: 'urn:test:identity:' + suffix, receivedAt: local });
  await save(a); await dismiss(a);
  await a.getByRole('button', { name: 'Resolve request', exact: true }).first().click(); await fill(a, { responseEvidenceReference: 'urn:test:response:' + suffix }); await save(a); await dismiss(a);
  assert.equal(sql(`SELECT status FROM commercial.privacy_subject_requests WHERE subject_reference='urn:test:subject:${suffix}'`), 'completed');
  states.push('privacy intake and evidence-bound resolution via actual operator UI/HTTP/PG');
  await stableScreenshot(a, 'screenshots/operators-real-privacy.png');
  await tab(a, 'Incidents'); await a.getByRole('button', { name: 'Register an incident', exact: true }).click();
  await fill(a, { detectedAt: local, estimatedSubjects: '1' });
  await a.getByRole('dialog').locator('[data-field=categories] input').fill('contact.email');
  await a.getByRole('dialog').locator('[data-field=categories]').getByRole('button', { name: 'Add', exact: true }).click();
  await save(a); await dismiss(a);
  await a.getByRole('button', { name: 'Close incident', exact: true }).first().click();
  await save(a, [409]);
  // Response headers arrive before the form finishes handling the rejection.
  // Cancel auto-waits until the busy form permits dismissal; Escape does not.
  await a.getByRole('dialog').getByRole('button', { name: 'Cancel', exact: true }).click();
  await a.getByRole('dialog').waitFor({ state: 'hidden' });
  for (const code of ['containment', 'investigation', 'initial_notification', 'investigation_notification']) {
    await a.getByRole('button', { name: 'Record incident event', exact: true }).first().click();
    await choose('eventCode', code); await fill(a, { evidenceReference: 'urn:test:incident:' + code + ':' + suffix });
    await save(a); await dismiss(a);
  }
  await a.getByRole('button', { name: 'Close incident', exact: true }).first().click(); await save(a); await dismiss(a);
  assert.equal(sql(`SELECT status FROM commercial.privacy_incidents WHERE created_by_user_id='${first.id}' ORDER BY created_at DESC LIMIT 1`), 'closed');
  states.push('incident intake, early close rejected, four required evidence events, completed close via UI/HTTP/PG');
  for (const page of pages) await page.getByRole('button', { name: 'Sign out operator', exact: true }).click();
  assert.deepEqual(errors, []);
  writeFileSync('screenshots/operators-real-results.json', JSON.stringify({ passed: true, states, observations, syntheticSetup: 'Initial paid recovery fixture and external evidence references only; HTTP/DB/MFA/permissions are real.' }, null, 2));
  console.log(JSON.stringify({ passed: true, groups: states.length, realOperators: 2 }));
} catch (error) { await stableScreenshot(a, 'screenshots/operators-real-failure-a.png'); await stableScreenshot(b, 'screenshots/operators-real-failure-b.png'); throw error; }
finally { await Promise.all(contexts.map((c) => c.close())); await browser.close(); }
