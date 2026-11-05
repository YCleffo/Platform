// UI regression with explicit HTTP fixtures. No request reaches a real API.
import assert from 'node:assert/strict';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import ts from 'typescript';
import AxeBuilder from '@axe-core/playwright';
import { launchBrowser, navigateClientSide, stableScreenshot } from './e2e-support.mjs';
const source = ts.transpileModule(readFileSync('src/modules/admin/workbench/catalog.ts', 'utf8'), {
  compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 },
}).outputText;
const { coreOperations, coreResources } = await import(
  'data:text/javascript;base64,' + Buffer.from(source).toString('base64')
);
const operatorSource = ts.transpileModule(
  readFileSync('src/modules/commercial/operator/operations.ts', 'utf8'),
  { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } },
).outputText;
const { operatorOperations } = await import(
  'data:text/javascript;base64,' + Buffer.from(operatorSource).toString('base64')
);
const en = (
  await import(
    'data:text/javascript;base64,' +
      Buffer.from(
        ts.transpileModule(readFileSync('src/shared/i18n/workbench.ts', 'utf8'), {
          compilerOptions: { module: ts.ModuleKind.ES2022 },
        }).outputText,
      ).toString('base64')
  )
).workbenchEn;
const opEn = (
  await import(
    'data:text/javascript;base64,' +
      Buffer.from(
        ts.transpileModule(readFileSync('src/shared/i18n/operator.ts', 'utf8'), {
          compilerOptions: { module: ts.ModuleKind.ES2022 },
        }).outputText,
      ).toString('base64')
  )
).operatorEn;
const base = process.env.BASE_URL ?? 'http://localhost:5194';
const browser = await launchBrowser();
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
await context.addInitScript(() => {
  localStorage.setItem('platform.locale', 'en');
  localStorage.setItem('platform.theme', 'dark');
});
const page = await context.newPage();
const errors = [];
const calls = [];
const states = [];
const id = (n) => `01900000-0000-7000-8000-${String(n).padStart(12, '0')}`;
const row = {
  id: id(1),
  name: 'Fixture record',
  code: 'custom.fixture',
  givenName: 'Fixture',
  familyName: 'Person',
  displayName: 'Fixture person',
  accountType: 'service',
  status: 'active',
  label: 'Fixture service',
  isSystem: false,
  isProtected: false,
  personId: id(1),
  createdAt: '2026-10-01T00:00:00Z',
  releasedAt: null,
};
let restricted = false;
let requireStepUp = true;
let uncertainPerson = false;
page.on('pageerror', (e) => errors.push(e.message));
await context.route('**/api/**', async (route) => {
  const req = route.request();
  const path = new URL(req.url()).pathname;
  if (!path.startsWith('/api/')) return route.continue();
  const method = req.method();
  const body = req.postDataJSON();
  calls.push({
    method,
    path,
    query: Object.fromEntries(new URL(req.url()).searchParams),
    body,
    authorization: req.headers().authorization ?? null,
  });
  const send = (data, status = 200) => route.fulfill({ status, json: data });
  const core = (data) => send({ status: 'success', data });
  if (path === '/api/auth/login')
    return core({
      status: 'authenticated',
      accessToken: 'core-fixture',
      refreshToken: 'core-refresh',
      accessExpiresAt: '2099-01-01T00:00:00Z',
      sessionExpiresAt: '2099-01-01T00:00:00Z',
      sessionId: id(50),
    });
  if (path === '/api/auth/me')
    return core({
      account: { id: id(1), personId: id(1) },
      roles: restricted ? [] : ['core.superadmin'],
      permissions: restricted ? ['core.rbac.read'] : [],
      locale: 'en',
      timezone: 'UTC',
      sessionId: id(50),
    });
  if (path === '/api/auth/logout' || path === '/api/control/auth/logout')
    return route.fulfill({ status: 204 });
  if (path === '/api/control/auth/login')
    return send({
      access_token: body.email.startsWith('operator') ? 'operator-fixture' : 'customer-fixture',
      refresh_token: 'fixture-refresh',
      access_expires_at: '2099-01-01T00:00:00Z',
      session_expires_at: '2099-01-01T00:00:00Z',
    });
  if (
    path === '/api/control/operator/me' &&
    req.headers().authorization !== 'Bearer operator-fixture'
  )
    return send(
      { error: { code: 'access_denied', message: 'Operator authorization required.' } },
      403,
    );
  if (path === '/api/control/operator/me')
    return send({
      user: { id: id(10), email: 'operator@example.test' },
      privacy: true,
      finance: true,
    });
  if (path === '/api/control/me')
    return send({
      id: id(10),
      email: 'customer@example.test',
      status: 'active',
      email_verified_at: '2026-10-01T00:00:00Z',
      last_login_at: null,
      created_at: '2026-10-01T00:00:00Z',
      updated_at: '2026-10-01T00:00:00Z',
    });
  const account = {
    id: id(1),
    code: 'fixture',
    display_name: 'Fixture account',
    legal_name: null,
    status: 'active',
    onboarding_state: 'completed',
    role: 'owner',
    created_at: '2026-10-01T00:00:00Z',
    updated_at: '2026-10-01T00:00:00Z',
    permissions: {
      read_account: true,
      view_members: true,
      manage_members: true,
      manage_ownership: true,
      manage_billing: true,
      manage_technical: true,
    },
  };
  if (path === '/api/control/accounts')
    return send({ items: [account], total: 1, limit: 25, offset: 0 });
  if (path === '/api/control/accounts/' + id(1)) return send(account);
  if (path === '/api/control/accounts/' + id(1) + '/memberships')
    return method === 'POST'
      ? send({ id: id(2) }, 201)
      : send({ items: [], total: 0, limit: 25, offset: 0 });
  if (path === '/api/control/operator/privacy/requests')
    return send({
      items: [{ id: id(1), subject_kind: 'other', status: 'submitted', request_type: 'access' }],
      total: 1,
    });
  if (path === '/api/control/operator/privacy/incidents')
    return send({ items: [{ id: id(1), status: 'investigating' }], total: 1 });
  if (path === '/api/control/operator/compensations')
    return send({
      items: [
        {
          id: id(1),
          account_name: 'Fixture company',
          amount_minor: 125000,
          currency: 'USD',
          status: 'paid_unapplied',
          compensation_status: 'recovery_required',
          attestation_id: null,
        },
        {
          id: id(2),
          account_name: 'Review company',
          amount_minor: '42000',
          currency: 'EUR',
          status: 'paid_unapplied',
          compensation_status: 'recovery_required',
          attestation_id: id(3),
          attestation_status: 'proposed',
          proposed_by: id(20),
          external_transaction_reference: 'urn:transaction:1',
          document_reference: 'urn:document:1',
          document_sha256: 'a'.repeat(64),
        },
      ],
      total: 2,
    });
  if (
    path === '/api/control/finance/plan-change-compensations/' + id(1) &&
    method === 'POST' &&
    requireStepUp
  ) {
    requireStepUp = false;
    return send({ error: { code: 'recent_mfa_required', message: 'Step up required.' } }, 403);
  }
  if (path === '/api/control/auth/reauthenticate') return route.fulfill({ status: 204 });
  if (path.startsWith('/api/control/')) return send({ id: id(1), status: 'completed' });
  if (path.includes('/privacy/work-items/'))
    return core({ items: [row, { ...row, id: id(2), name: 'Second record' }], total: 2 });
  if (
    path === '/api/core/modules' ||
    path === '/api/core/settings/system' ||
    path === '/api/core/feature-flags'
  )
    return core([]);
  if (path === '/api/core/persons' && method === 'POST' && uncertainPerson) {
    uncertainPerson = false;
    return send({ status: 'error', code: 'temporary_unavailable', message: 'Unavailable' }, 503);
  }
  if (path === '/api/core/persons' && restricted)
    return send({ status: 'error', message: 'denied', code: 'auth.permission_denied' }, 403);
  if (path === '/api/core/accounts' && method === 'GET')
    return core({
      items: [
        row,
        { ...row, id: id(2), name: 'Second record' },
        { ...row, id: id(3), status: 'pending_approval' },
        { ...row, id: id(4), status: 'disabled' },
      ],
      total: 4,
    });
  if (
    [
      '/api/core/persons',
      '/api/core/accounts',
      '/api/core/roles',
      '/api/core/organizations',
      '/api/core/audit-events',
      '/api/core/privacy/legal-holds',
    ].includes(path) &&
    method === 'GET'
  )
    return core({
      items: [row, { ...row, id: id(2), displayName: 'Second person', name: 'Second record' }],
      total: 2,
    });
  if (path === '/api/core/permissions')
    return core({
      items: [
        { code: 'core.persons.read', module: 'core', description: 'Read people' },
        { code: 'core.persons.manage', module: 'core', description: 'Manage people' },
      ],
      total: 2,
    });
  if (path.endsWith('/permissions') && method === 'GET')
    return core({ permissions: ['core.persons.read'] });
  if (
    (path.endsWith('/members') ||
      path.endsWith('/contacts') ||
      path.endsWith('/service-credentials') ||
      path.endsWith('/roles')) &&
    method === 'GET'
  )
    return core([row, { ...row, id: id(2), personId: id(2), name: 'Second record' }]);
  return core({ ...row, ...(body && typeof body === 'object' ? body : {}), id: id(1) });
});
const messagesSource = readFileSync('src/shared/i18n/messages/en.ts', 'utf8')
  .replace(/^import .*;$/gm, '')
  .replace(/workbench:\s*workbenchEn/, 'workbench: {}')
  .replace(/operator:\s*operatorEn/, 'operator: {}');
const messages = (
  await import(
    'data:text/javascript;base64,' +
      Buffer.from(
        ts.transpileModule(messagesSource, { compilerOptions: { module: ts.ModuleKind.ES2022 } })
          .outputText,
      ).toString('base64')
  )
).default;
function label(key) {
  return key.startsWith('operator.') ? opEn[key.split('.')[1]] : messages.admin[key.split('.')[1]];
}

async function select(field, index = 0) {
  const trigger = field.locator('.app-dropdown__trigger');
  await trigger.waitFor();
  await trigger.waitFor({ state: 'visible' });
  await page.waitForFunction((el) => !el.disabled, await trigger.elementHandle());
  await trigger.click();
  const menu = page.getByRole('menu').last();
  await menu.waitFor();
  const choices = menu.locator('[role^="menuitem"]');
  await choices.nth(index).click();
}
async function fill(op) {
  const dialog = page.getByRole('dialog');
  await dialog.waitFor();
  await page.waitForFunction(() => !document.querySelector('[role=dialog] [role=status]'));
  for (const field of op.fields) {
    if (field.readOnly) continue;
    const box = dialog.locator(`[data-field="${field.name}"]`);
    if (!(await box.count())) continue;
    if (field.kind === 'entity' || field.source) {
      await select(box, field.name === 'representativePersonId' ? 1 : 0);
      if (field.kind === 'multi') await page.keyboard.press('Escape');
      continue;
    }
    if (field.kind === 'select' || field.kind === 'multi') {
      if (!field.initial) await select(box);
      continue;
    }
    if (field.kind === 'boolean') continue;
    if (field.kind === 'codes') {
      if (field.optional) continue;
      const input = box.locator('input');
      await input.fill(field.rule === 'uuid' ? id(1) : 'contact_data');
      await box.getByRole('button', { name: 'Add', exact: true }).click();
      continue;
    }
    if (
      field.optional &&
      !['subjectPersonId', 'billingProfileId', 'responseEvidenceReference'].includes(field.name)
    )
      continue;
    const input = box.locator('input').first();
    const value =
      field.rule === 'uuid'
        ? id(1)
        : field.rule === 'sha256'
          ? 'a'.repeat(64)
          : field.rule === 'urn'
            ? 'urn:fixture:document'
            : field.kind === 'email'
              ? 'person@example.test'
              : field.kind === 'datetime'
                ? field.name === 'expiresAt'
                  ? '2027-01-01T12:00'
                  : '2026-10-01T12:00'
                : field.kind === 'number'
                  ? '25'
                  : field.kind === 'date'
                    ? '2000-01-01'
                    : field.name === 'value'
                      ? 'person@example.test'
                      : field.rule === 'code'
                        ? 'fixture_code'
                        : 'Fixture value';
    await input.fill(value);
  }
  if (op.danger) await dialog.getByRole('checkbox', { name: en.confirmAction }).check();
  if (op.key === 'representation.create')
    await stableScreenshot(page, 'screenshots/workflows-core-form.png');
  const count = calls.length;
  await dialog
    .getByRole('button', { name: op.method === 'GET' ? 'Open' : 'Save', exact: true })
    .click();
  if (op.key === 'commercial.propose') {
    await dialog.locator('.operator-security').waitFor();
    assert.equal(await dialog.locator('form form').count(), 0, 'Step-up must not nest forms');
    await dialog.locator('.operator-security .app-disclosure__trigger').click();
    await dialog.locator('input[type=password]').fill('Fixture-password');
    await dialog.getByRole('textbox', { name: opEn.mfaCode, exact: true }).fill('123456');
    const beforeStep = calls.filter(
      (c) =>
        c.method === 'POST' && c.path === '/api/control/finance/plan-change-compensations/' + id(1),
    ).length;
    await dialog.getByRole('button', { name: opEn.reauthenticate, exact: true }).click();
    await dialog.locator('.operator-security [role=status]').waitFor();
    assert.equal(
      calls.filter(
        (c) =>
          c.method === 'POST' &&
          c.path === '/api/control/finance/plan-change-compensations/' + id(1),
      ).length,
      beforeStep,
      'Step-up must not auto-submit a financial mutation',
    );
    assert.equal(
      await dialog.locator('[data-field=documentSha256] input').inputValue(),
      'a'.repeat(64),
    );
    await dialog.getByRole('button', { name: 'Save', exact: true }).click();
    states.push('operator explicit retry after step-up preserves evidence');
  }
  await page.getByRole('dialog').locator('.wb-record').first().waitFor();
  assert.ok(calls.length > count, op.key + ' sent a request');
  assert.equal(await page.getByRole('dialog').locator('textarea').count(), 0);
  await page.keyboard.press('Escape');
  await page.getByRole('dialog').waitFor({ state: 'hidden' });
  states.push(op.key);
}
async function navigate(path, selector) {
  await navigateClientSide(page, path);
  await page.locator(selector).waitFor();
  await page.waitForFunction(() => !document.querySelector('main .app-skeleton'));
}
mkdirSync('screenshots', { recursive: true });
try {
  await page.goto(base + '/admin/login');
  await page.locator('#admin-identifier').fill('operator@example.test');
  await page.locator('#admin-password').fill('Fixture-password');
  await page.locator('form button[type=submit]').click();
  await page.waitForURL((u) => u.pathname === '/admin');
  for (const resource of coreResources) {
    const path =
      resource.key === 'roles' || resource.key === 'permissions'
        ? '/admin/rbac'
        : resource.key === 'legalHolds'
          ? '/admin/privacy'
          : '/admin/' + resource.key;
    await navigate(
      path,
      resource.key === 'roles' || resource.key === 'permissions'
        ? '.admin-tabs'
        : resource.key === 'legalHolds'
          ? '.admin-tabs'
          : '.admin-resource',
    );
    if (resource.key === 'permissions')
      await page.locator('.admin-tabs__nav button').nth(1).click();
    if (resource.key === 'legalHolds') await page.locator('.admin-tabs__nav button').nth(1).click();
    await page.locator('.admin-resource tbody tr').first().waitFor();
    if (resource.create) {
      const op = coreOperations.find((o) => o.key === resource.create);
      await page
        .locator('.admin-resource__head')
        .getByRole('button', { name: 'Create', exact: true })
        .click();
      await fill(op);
    }
    for (const key of resource.global ?? []) {
      const op = coreOperations.find((o) => o.key === key);
      await page
        .locator('.admin-resource__head')
        .getByRole('button', { name: label(op.label), exact: true })
        .click();
      await fill(op);
    }
    if (resource.key === 'audit') {
      const filters = page.locator('.admin-resource__filters');
      await filters.locator('[data-field=action] input').fill('auth.');
      await select(filters.locator('[data-field=success]'));
      await select(filters.locator('[data-field=actorAccountId]'));
      await filters.getByRole('button', { name: 'Apply filters', exact: true }).click();
      await page.waitForFunction(
        () => !document.querySelector('.admin-resource__head [aria-busy=true]'),
      );
      const request = calls.filter((c) => c.path === '/api/core/audit-events').at(-1);
      assert.equal(request.query.action, 'auth.');
      assert.equal(request.query.success, 'true');
      assert.equal(request.query.actorAccountId, id(1));
      states.push('audit authoritative filters');
    }
    for (const key of resource.actions) {
      const op = coreOperations.find((o) => o.key === key);
      const selectedIndex = op.key === 'account.approve' ? 2 : op.key === 'account.enable' ? 3 : 0;
      if (selectedIndex === 0 && op.blocked?.(row)) continue;
      const first = page.locator('.admin-resource tbody tr').nth(selectedIndex);
      await first.locator('.app-dropdown__trigger').click();
      await page.getByRole('menu').last().getByText(label(op.label), { exact: true }).click();
      await fill(op);
    }
  }
  await navigate('/admin/privacy', '.admin-tabs');
  await page.locator('.admin-tabs__nav button').nth(2).click();
  await page.locator('.wb-workflows').waitFor();
  for (const op of coreOperations.filter((o) =>
    ['representation', 'erasure', 'incident', 'distribution'].some((g) =>
      o.key.startsWith(g + '.'),
    ),
  )) {
    await page
      .locator('.wb-workflows')
      .getByRole('button', { name: label(op.label), exact: true })
      .click();
    await fill(op);
  }
  await stableScreenshot(page, 'screenshots/workflows-core-desktop.png');
  for (const op of coreOperations)
    assert.ok(
      calls.some(
        (call) =>
          call.method === op.method &&
          new RegExp('^/api' + op.path.replace(/\{\w+\}/g, '[^/]+') + '$').test(call.path),
      ),
      op.key + ' has no observed request',
    );
  await navigate('/admin/persons', '.admin-resource');
  await page.locator('.admin-resource tbody tr').first().waitFor();
  restricted = true;
  await page
    .locator('.admin-resource__head')
    .getByRole('button', { name: 'Refresh', exact: true })
    .click();
  await page.locator('.admin-resource .app-error').waitFor();
  assert.equal(await page.locator('.admin-resource tbody tr').count(), 0);
  restricted = false;
  await page
    .locator('.admin-resource__head')
    .getByRole('button', { name: 'Refresh', exact: true })
    .click();
  await page.locator('.admin-resource tbody tr').first().waitFor();
  states.push('403 evicts warmed directory rows');
  uncertainPerson = true;
  await page
    .locator('.admin-resource__head')
    .getByRole('button', { name: 'Create', exact: true })
    .click();
  let unknownDialog = page.getByRole('dialog');
  await unknownDialog.locator('[data-field=givenName] input').fill('Unknown');
  await unknownDialog.locator('[data-field=familyName] input').fill('Outcome');
  const beforeUnknown = calls.filter(
    (c) => c.method === 'POST' && c.path === '/api/core/persons',
  ).length;
  await unknownDialog.getByRole('button', { name: 'Save', exact: true }).click();
  await unknownDialog.getByText(en.uncertain, { exact: true }).waitFor();
  assert.equal(
    await unknownDialog.getByRole('button', { name: 'Save', exact: true }).isDisabled(),
    true,
  );
  assert.equal(
    calls.filter((c) => c.method === 'POST' && c.path === '/api/core/persons').length,
    beforeUnknown + 1,
  );
  await page.keyboard.press('Escape');
  await page.getByRole('dialog').waitFor({ state: 'hidden' });
  await page
    .locator('.admin-resource__head')
    .getByRole('button', { name: 'Create', exact: true })
    .click();
  unknownDialog = page.getByRole('dialog');
  await unknownDialog.getByText(en.uncertain, { exact: true }).waitFor();
  assert.equal(
    await unknownDialog.getByRole('button', { name: 'Save', exact: true }).isDisabled(),
    true,
  );
  await page.keyboard.press('Escape');
  await page.getByRole('dialog').waitFor({ state: 'hidden' });
  states.push('uncertain write requires review even after reopening');
  await navigateClientSide(page, '/login');
  await page.locator('#login-email').fill('customer@example.test');
  await page.locator('#login-password').fill('Fixture-password');
  await page.locator('#login-password').press('Enter');
  await page.waitForURL((u) => u.pathname === '/cabinet');
  await navigateClientSide(page, '/accounts/' + id(1) + '/members');
  await page.getByRole('button', { name: opEn.addExisting, exact: true }).waitFor();
  await page.getByRole('button', { name: opEn.addExisting, exact: true }).click();
  await page.locator('#membership-existing-user').fill(id(2));
  await page.getByRole('dialog').getByRole('checkbox', { name: en.confirmAction }).check();
  await stableScreenshot(page, 'screenshots/workflows-direct-membership.png');
  await page.getByRole('dialog').getByRole('button', { name: 'Add', exact: true }).click();
  await page.getByRole('dialog').waitFor({ state: 'hidden' });
  const membership = calls.find(
    (c) => c.method === 'POST' && c.path === '/api/control/accounts/' + id(1) + '/memberships',
  );
  assert.deepEqual(membership.body, { user_id: id(2), role: 'technical' });
  assert.equal(membership.authorization, 'Bearer customer-fixture');
  states.push('commercial.direct-membership');
  await navigate('/operator', '.operator-console');
  await page.locator('#operator-email').fill('operator@example.test');
  await page.locator('#operator-password').fill('Fixture-password');
  await page.locator('.operator-console__login button[type=submit]').click();
  await page.locator('.operator-console__tabs').waitFor();
  for (const [tab, keys] of [
    ['requests', ['intake', 'resolve']],
    ['incidents', ['detect', 'event', 'close']],
    ['compensations', ['current', 'propose', 'approve', 'reject']],
  ]) {
    await page
      .locator('.operator-console__tabs')
      .getByRole('button', { name: opEn[tab], exact: true })
      .click();
    for (const key of keys) {
      const op = operatorOperations.find((o) => o.key === 'commercial.' + key);
      await page
        .locator('.operator-console')
        .getByRole('button', { name: opEn[key], exact: true })
        .first()
        .click();
      await fill(op);
    }
  }
  assert.ok((await page.locator('.operator-console').innerText()).includes('$1,250.00'));
  assert.ok((await page.locator('.operator-console').innerText()).includes('€420.00'));
  await stableScreenshot(page, 'screenshots/workflows-commercial-desktop.png');
  const operatorCalls = calls.filter(
    (c) => c.path.includes('/operator/') || c.path.includes('/finance/'),
  );
  assert.ok(operatorCalls.every((c) => c.authorization === 'Bearer operator-fixture'));
  for (const width of [320, 393, 768, 1440]) {
    await page.setViewportSize({ width, height: 1000 });
    assert.equal(
      await page.evaluate(
        () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
      ),
      false,
    );
  }
  await page.setViewportSize({ width: 393, height: 1000 });
  await stableScreenshot(page, 'screenshots/workflows-commercial-mobile.png');
  const accessibility = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze();
  assert.deepEqual(accessibility.violations, []);
  await page.getByRole('button', { name: opEn.signOut, exact: true }).click();
  await page.locator('.operator-console__login').waitFor();
  await navigateClientSide(page, '/accounts/' + id(1) + '/members');
  await page.getByRole('button', { name: opEn.addExisting, exact: true }).waitFor();
  assert.equal(new URL(page.url()).pathname, '/accounts/' + id(1) + '/members');
  states.push('operator logout preserves customer session');
  assert.deepEqual(errors, []);
  writeFileSync(
    'screenshots/workflows-results.json',
    JSON.stringify(
      {
        fixtureOnly: true,
        passed: true,
        states,
        calls: calls.map(({ method, path }) => ({ method, path })),
        coreOperations: coreOperations.length + coreResources.length,
        commercialOperations: operatorOperations.length + 1,
        violations: 0,
      },
      null,
      2,
    ),
  );
  console.log(
    JSON.stringify({
      fixtureOnly: true,
      passed: true,
      states: states.length,
      coreOperations: 53,
      commercialOperations: 10,
    }),
  );
} catch (error) {
  await page.screenshot({ path: 'screenshots/workflows-failure.png' });
  writeFileSync(
    'screenshots/workflows-failure.json',
    JSON.stringify({ states, errors, lastCalls: calls.slice(-8) }, null, 2),
  );
  throw error;
} finally {
  await browser.close();
}
