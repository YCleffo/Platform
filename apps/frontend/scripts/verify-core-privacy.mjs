// Explicit HTTP fixtures; all private responses are synthetic, no working database is used.
import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import {
  launchBrowser,
  navigateClientSide,
  selectDropdown,
  stableScreenshot,
} from './e2e-support.mjs';
const base = process.env.BASE_URL ?? 'http://localhost:5173';
const browser = await launchBrowser();
const context = await browser.newContext({ viewport: { width: 1440, height: 1100 } });
await context.addInitScript(() => {
  localStorage.setItem('platform.locale', 'en');
  localStorage.setItem('platform.theme', 'dark');
});
const page = await context.newPage();
const errors = [];
const states = [];
page.on('pageerror', (error) => errors.push(error.message));
const uuid = (n) => `01900000-0000-7000-8000-${String(n).padStart(12, '0')}`;
const own = uuid(1);
const child = uuid(2);
const limitedChild = uuid(3);
let serial = 1000;
const authority = {
  id: uuid(80),
  subjectPersonId: child,
  subjectName: 'Represented fixture',
  validUntil: '2027-12-31T00:00:00Z',
  scopes: [
    'privacy.request.submit',
    'privacy.request.view',
    'privacy.export.request',
    'privacy.data.access',
    'privacy.data.export',
    'privacy.processing_information.response',
  ],
};
let stallDiscovery = false;
const limited = {
  id: uuid(81),
  subjectPersonId: limitedChild,
  subjectName: 'Limited fixture',
  validUntil: null,
  scopes: ['privacy.request.submit'],
};
let revoked = false;
const row = (type = 'access', grant) => ({
  id: uuid(serial++),
  subjectPersonId: grant?.subjectPersonId ?? own,
  representationId: grant?.id ?? null,
  requestType: type,
  status: 'submitted',
  submittedAt: '2026-11-04T00:00:00Z',
  completedAt: null,
  resolutionCode: null,
  deadlines: [],
});
let rows = Array.from({ length: 30 }, () => row());
let failPage = false;
let uncertainSubmit = false;
let failResponse = false;
let wrongSubject = false;
let posts = 0;
const issued = [];
let latest;
let lastRaw;
const visible = (item) =>
  item.subjectPersonId === own || (!revoked && item.representationId === authority.id);
await context.route('**/api/**', async (route) => {
  const request = route.request();
  const url = new URL(request.url());
  const path = url.pathname;
  const method = request.method();
  const send = (status, body) =>
    route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
  const fail = (status, code) => send(status, { error: { code, message: 'Fixture failure' } });
  let data = {};
  if (path === '/api/auth/login')
    data = {
      status: 'authenticated',
      accessToken: 'fixture',
      refreshToken: 'fixture-refresh',
      sessionId: 'fixture-session',
      accessExpiresAt: '2099-01-01T00:00:00Z',
      sessionExpiresAt: '2099-01-01T00:00:00Z',
    };
  else if (path === '/api/auth/logout') return route.fulfill({ status: 204 });
  else if (path === '/api/auth/providers') data = { items: [] };
  else if (path === '/api/auth/me')
    data = {
      account: { id: uuid(9), personId: own },
      roles: [],
      permissions: [],
      locale: null,
      timezone: null,
      sessionId: 'fixture-session',
    };
  else if (path === '/api/auth/me/settings')
    data = {
      schema_version: 2,
      settings: {
        interface: { animations: true, transparency: true },
        notifications: { browser: true, sound: true, email: false },
        privacy: { show_online_status: false },
      },
    };
  else if (path === '/api/auth/me/privacy/representations') {
    if (stallDiscovery) return;
    data = { items: [...(!revoked ? [authority] : []), limited] };
  } else if (path === '/api/auth/me/privacy/requests' && method === 'GET') {
    if (failPage && Number(url.searchParams.get('offset')) > 0)
      return fail(503, 'http.unavailable');
    const offset = Number(url.searchParams.get('offset') ?? 0);
    const limit = Number(url.searchParams.get('limit') ?? 100);
    data = rows.filter(visible).slice(offset, offset + limit);
  } else if (
    (path === '/api/auth/me/privacy/requests' ||
      /^\/api\/auth\/me\/privacy\/representations\/[^/]+\/requests$/.test(path)) &&
    method === 'POST'
  ) {
    const body = request.postDataJSON();
    const selected = path.includes('/representations/')
      ? [authority, limited].find((a) => path.includes(a.id))
      : undefined;
    if (selected) assert.equal(body.subjectPersonId, selected.subjectPersonId);
    else assert.deepEqual(Object.keys(body), ['requestType']);
    latest = row(body.requestType, selected);
    rows.unshift(latest);
    posts++;
    if (uncertainSubmit) return fail(503, 'http.unavailable');
    data = { id: latest.id };
    return send(201, { status: 'success', data });
  } else if (/\/requests\/[^/]+\/(data|processing-information)$/.test(path)) {
    issued.push(path);
    const id = path.split('/').at(-2);
    const item = rows.find((item) => item.id === id);
    if (!item || !visible(item) || item.status !== 'in_progress')
      return fail(404, 'http.not_found');
    if (item.subjectPersonId !== own)
      assert.ok(path.includes(`/representations/${item.representationId}/`));
    else assert.ok(!path.includes('/representations/'));
    item.status = 'completed';
    item.completedAt = '2026-11-04T00:10:00Z';
    item.deadlines = item.deadlines.map((d) => ({
      ...d,
      satisfiedAt: item.completedAt,
      overdue: false,
    }));
    if (failResponse) return fail(502, 'http.unavailable');
    const sections = [
      {
        contributorCode: 'fixture.person',
        dataCategories: ['identity'],
        records: [{ name: 'owned response fixture' }],
      },
    ];
    const doc = path.endsWith('/data')
      ? {
          schemaVersion: '1',
          subjectPersonId: wrongSubject ? uuid(99) : item.subjectPersonId,
          generatedAt: '2026-11-04T00:10:00Z',
          sections,
        }
      : {
          schemaVersion: 'privacy.processing_information.v1',
          subjectPersonId: item.subjectPersonId,
          operator: { name: 'Synthetic operator', address: 'Fixture address' },
          processed: true,
          purposes: [],
          actualPersonalData: sections,
        };
    lastRaw = JSON.stringify(doc) + '\n';
    return route.fulfill({ status: 200, contentType: 'application/json', body: lastRaw });
  } else if (/^\/api\/auth\/me\/privacy\/requests\/[^/]+$/.test(path)) {
    const item = rows.find((item) => item.id === path.split('/').at(-1));
    if (!item || !visible(item)) return fail(404, 'http.not_found');
    data = item;
  }
  return send(200, { status: 'success', data });
});
async function inspect(label) {
  assert.equal(
    await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
    true,
    label + ': overflow',
  );
  if (await page.getByRole('dialog').count())
    await page.waitForFunction(() => {
      const b = document.querySelector('[role=dialog]')?.getBoundingClientRect();
      return (
        b && b.top >= 0 && b.left >= 0 && b.bottom <= innerHeight + 1 && b.right <= innerWidth + 1
      );
    });
  assert.deepEqual(
    (
      await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze()
    ).violations.map((v) => v.id),
    [],
    label + ': axe',
  );
  states.push(label);
}
async function list() {
  await navigateClientSide(page, '/admin/profile/privacy');
  await page.getByRole('heading', { name: 'Privacy requests', exact: true }).waitFor();
}
async function create(type = 'access', grant) {
  await list();
  await page.getByRole('button', { name: 'New request', exact: true }).click();
  if (grant) await selectDropdown(page, 'core-privacy-subject', grant.id);
  await selectDropdown(page, 'core-privacy-type', type);
  if (type === 'erasure' || type === 'processing_termination') {
    assert.equal(
      await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Submit request', exact: true })
        .isDisabled(),
      true,
    );
    await page.locator('#core-privacy-ack').check();
  }
  // A click can finish before the asynchronous route fixture creates the row.
  const submitted = page.waitForResponse(
    (response) =>
      response.request().method() === 'POST' &&
      /^\/api\/auth\/me\/privacy\/(?:representations\/[^/]+\/)?requests$/.test(
        new URL(response.url()).pathname,
      ),
  );
  await page
    .getByRole('dialog')
    .getByRole('button', { name: 'Submit request', exact: true })
    .dblclick();
  const response = await submitted;
  assert.equal(response.status(), 201);
  const createdId = (await response.json()).data.id;
  assert.equal(createdId, latest.id);
  if (grant === limited) {
    await page
      .getByText('Request submitted. This authority does not permit viewing it.', { exact: false })
      .waitFor();
    return;
  }
  await page.waitForURL((url) => url.pathname === '/admin/profile/privacy/' + createdId);
  await page.getByText(createdId, { exact: true }).waitFor();
}
async function ready() {
  latest.status = 'in_progress';
  latest.deadlines = [
    {
      code:
        latest.requestType === 'processing_information'
          ? 'processing_information_response'
          : 'access_response',
      triggeredAt: '2026-11-04T00:00:00Z',
      calendarType: 'working_days',
      dueAt: '2026-12-01T00:00:00Z',
      extendedDueAt: '2026-12-05T00:00:00Z',
      effectiveDueAt: '2026-12-05T00:00:00Z',
      satisfiedAt: null,
      overdue: false,
    },
  ];
  await page.getByRole('button', { name: 'Refresh', exact: true }).click();
  await page.getByRole('button', { name: 'Receive response', exact: true }).waitFor();
}
async function receive() {
  await page.getByRole('button', { name: 'Receive response', exact: true }).click();
  await page
    .getByRole('dialog')
    .getByRole('button', { name: 'Receive response', exact: true })
    .click();
}
async function download() {
  const dialog = page.getByRole('dialog', { name: 'Response to your request', exact: true });
  await dialog.waitFor();
  const event = page.waitForEvent('download');
  await dialog.getByRole('button', { name: 'Save JSON file', exact: true }).click();
  const file = await event;
  const stream = await file.createReadStream();
  const chunks = [];
  for await (const chunk of stream) chunks.push(chunk);
  assert.equal(Buffer.concat(chunks).toString('utf8'), lastRaw);
  assert.ok(file.suggestedFilename().endsWith('.json'));
  const toolbar = dialog.locator('.app-dialog__toolbar');
  await toolbar.locator('p').waitFor();
  const centers = await toolbar.evaluate((el) => {
    const b = el.querySelector('button').getBoundingClientRect();
    const p = el.querySelector('p').getBoundingClientRect();
    return { button: b.y + b.height / 2, text: p.y + p.height / 2 };
  });
  assert.ok(
    Math.abs(centers.button - centers.text) < 2,
    'Download status must be vertically centered beside its button',
  );
  await stableScreenshot(page, 'screenshots/core-privacy-response-download.png');
  await dialog.getByRole('button', { name: 'Close', exact: true }).click();
}
mkdirSync('screenshots', { recursive: true });
try {
  await page.goto(base + '/admin/login?redirect=/admin/profile/privacy');
  await page.locator('#admin-identifier').fill('fixture@example.test');
  await page.locator('#admin-password').fill('fixture-password');
  await page.locator('form button[type=submit]').click();
  await page.locator('.core-privacy__list > .app-card').first().waitFor();
  assert.equal(await page.locator('.core-privacy__list > .app-card').count(), 25);
  failPage = true;
  await page.getByRole('button', { name: 'Load more', exact: true }).click();
  await page.getByRole('button', { name: 'Try again', exact: true }).waitFor();
  assert.equal(await page.locator('.core-privacy__list > .app-card').count(), 25);
  failPage = false;
  await page.getByRole('button', { name: 'Try again', exact: true }).click();
  await page.waitForFunction(
    () => document.querySelectorAll('.core-privacy__list > .app-card').length === 30,
  );
  await inspect('bounded pagination; failed next page retains rows and retries');
  rows = [];
  await page.getByRole('button', { name: 'Refresh', exact: true }).click();
  await page.getByText('No requests yet', { exact: true }).waitFor();
  await inspect('empty self-service without platform permissions');
  const before = posts;
  await create('erasure');
  assert.equal(posts, before + 1);
  assert.equal(latest.status, 'submitted');
  await inspect('destructive request acknowledgement; double click sends one POST');
  await create('access');
  assert.equal(
    await page.getByRole('button', { name: 'Receive response', exact: true }).count(),
    0,
  );
  await ready();
  await receive();
  await inspect('self access: one-time response in fullscreen global dialog');
  await stableScreenshot(page, 'screenshots/core-privacy-response-desktop.png');
  await download();
  assert.equal(latest.status, 'completed');
  await page.getByRole('button', { name: 'Refresh', exact: true }).click();
  await page.getByRole('button', { name: 'View response', exact: true }).waitFor();
  await create('processing_information');
  await ready();
  await receive();
  await download();
  await inspect('self processing information and exact JSON download');
  await create('export', authority);
  await ready();
  await receive();
  await download();
  assert.ok(issued.at(-1).includes('/representations/' + authority.id + '/'));
  await inspect('representative export uses selected authority and subject');
  await create('processing_information', authority);
  await ready();
  await receive();
  await download();
  assert.ok(issued.at(-1).endsWith('/processing-information'));
  await inspect('representative processing response and scope-aware creation');
  stallDiscovery = true;
  await page.getByRole('button', { name: 'Refresh', exact: true }).click();
  await page.getByRole('alert').waitFor({ timeout: 20000 });
  assert.equal(await page.getByRole('button', { name: 'Refresh', exact: true }).isEnabled(), true);
  stallDiscovery = false;
  await page.getByRole('button', { name: 'Refresh', exact: true }).click();
  await page.getByRole('button', { name: 'Refresh', exact: true }).waitFor();
  await page.waitForFunction(() => !document.querySelector('.core-privacy [role=alert]'));
  await inspect('stalled authority lookup releases loading; explicit refresh recovers');
  await create('access');
  await ready();
  failResponse = true;
  const calls = issued.length;
  await receive();
  await page
    .getByText(
      'The response could not be confirmed. Its status has been checked. Do not assume that a second copy can be issued.',
      { exact: true },
    )
    .waitFor();
  await page.locator('[role="dialog"]').waitFor({ state: 'hidden' });
  assert.equal(issued.length, calls + 1);
  assert.equal(
    await page.getByRole('button', { name: 'Receive response', exact: true }).count(),
    0,
  );
  assert.equal(await page.getByRole('button', { name: 'Save JSON file', exact: true }).count(), 0);
  failResponse = false;
  await inspect('stateful GET is never replayed after lost/502 response');
  await create('export');
  await ready();
  wrongSubject = true;
  await receive();
  await page
    .getByText(
      'The response could not be confirmed. Its status has been checked. Do not assume that a second copy can be issued.',
      { exact: true },
    )
    .waitFor();
  assert.equal(await page.getByRole('dialog').count(), 0);
  wrongSubject = false;
  await inspect('wrong-subject document rejected before rendering or download');
  await create('access', authority);
  await ready();
  revoked = true;
  await receive();
  await page
    .getByText(
      'This request does not exist or you no longer have access. Return to your list or refresh.',
      { exact: true },
    )
    .waitFor();
  assert.equal(
    await page.getByRole('button', { name: 'Receive response', exact: true }).count(),
    0,
  );
  await inspect('revoked representation denies detail and response');
  await create('access', limited);
  assert.equal(new URL(page.url()).pathname, '/admin/profile/privacy');
  await inspect('submit-only authority does not pretend to grant read access');
  uncertainSubmit = true;
  await list();
  await page.getByRole('button', { name: 'New request', exact: true }).click();
  const oldPosts = posts;
  await page
    .getByRole('dialog')
    .getByRole('button', { name: 'Submit request', exact: true })
    .click();
  await page
    .getByRole('dialog')
    .getByRole('button', { name: 'Check request list', exact: true })
    .waitFor();
  assert.equal(
    await page
      .getByRole('dialog')
      .getByRole('button', { name: 'Submit request', exact: true })
      .isDisabled(),
    true,
  );
  await page.getByRole('dialog').getByRole('button', { name: 'Cancel', exact: true }).click();
  await page.getByRole('button', { name: 'New request', exact: true }).click();
  assert.equal(
    await page
      .getByRole('dialog')
      .getByRole('button', { name: 'Submit request', exact: true })
      .isDisabled(),
    true,
  );
  uncertainSubmit = false;
  await page
    .getByRole('dialog')
    .getByRole('button', { name: 'Check request list', exact: true })
    .click();
  await page.getByRole('dialog').waitFor({ state: 'hidden' });
  assert.equal(posts, oldPosts + 1);
  await inspect(
    'ambiguous POST requires checking the list even after closing and reopening the dialog',
  );
  for (const width of [320, 393, 768, 1440]) {
    await page.setViewportSize({ width, height: 1100 });
    await inspect('privacy list EN dark ' + width);
  }
  await stableScreenshot(page, 'screenshots/core-privacy-list-desktop.png');
  await page.getByRole('button', { name: 'Light theme', exact: true }).click();
  await page.locator('.admin__locale').click();
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.evaluate(() => document.documentElement.style.setProperty('--ui-font-scale', '1.4'));
  for (const width of [320, 393, 1440]) {
    await page.setViewportSize({ width, height: 1100 });
    await inspect('privacy list RU light 140% ' + width);
  }
  await page.setViewportSize({ width: 393, height: 1100 });
  await page.getByRole('button', { name: 'Новый запрос', exact: true }).click();
  await inspect('create dialog RU 140% mobile');
  await stableScreenshot(page, 'screenshots/core-privacy-create-mobile.png');
  await page.getByRole('dialog').getByRole('button', { name: 'Отмена', exact: true }).click();
  await page.getByRole('button', { name: 'Выйти', exact: true }).click();
  await page.waitForURL((url) => url.pathname === '/admin/login');
  assert.equal(await page.locator('.core-privacy__list').count(), 0);
  assert.deepEqual(errors, []);
  writeFileSync(
    'screenshots/core-privacy-ui-results.json',
    JSON.stringify({ passed: true, fixtureOnly: true, states }, null, 2),
  );
  console.log(JSON.stringify({ passed: true, fixtureOnly: true, states }));
} catch (error) {
  await page.screenshot({ path: 'screenshots/core-privacy-ui-failure.png' }).catch(() => {});
  throw error;
} finally {
  await browser.close();
}
