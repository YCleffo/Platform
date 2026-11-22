// Browser acceptance against disposable Core, PostgreSQL and object storage.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync } from 'node:fs';
import { request as playwrightRequest } from 'playwright';
import AxeBuilder from '@axe-core/playwright';
import { evaluateCoreMutationGate } from './core-e2e-guard.mjs';
import { launchBrowser, navigateClientSide, selectDropdown } from './e2e-support.mjs';
import { verifyLearning } from './e2e-education-learning.mjs';
import { captureAuthenticated, observeAuthenticated } from './frontend09-authenticated-evidence.mjs';

const env = process.env;
const role = process.argv[2];
if (role === '--check-guard') {
  assert.equal((await evaluateCoreMutationGate(env)).allowed, false);
  console.log('Education role suites refuse mutations without a proven disposable runtime.');
  process.exit(0);
}
assert.ok(
  ['student', 'parent', 'device', 'teacher', 'storage', 'classroom'].includes(role),
  'Choose student, parent, device, teacher, storage or classroom.',
);
const proof = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
try {
  const gate = await evaluateCoreMutationGate(env, async (url) => {
    const response = await proof.get(url, { timeout: 15000 });
    return { status: response.status(), json: () => response.json() };
  });
  assert.equal(gate.allowed, true, gate.reasons.join('; '));
} finally {
  await proof.dispose();
}

function runFixture(command, ...args) {
  assert.ok(env.CORE_TEST_CONTAINER);
  try {
    return JSON.parse(
      execFileSync(
        'docker',
        [
          'exec',
          env.CORE_TEST_CONTAINER,
          'php',
          'tests/e2e/education-fixture.php',
          command,
          createHash('sha256').update(env.E2E_ISOLATION_PROOF).digest('hex'),
          ...args,
        ],
        { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
      ),
    );
  } catch {
    throw new Error('Disposable Education role fixture failed. Private output suppressed.');
  }
}

const fixture = runFixture('seed-roles', role);
const organization = fixture.organizationId;
const browser = await launchBrowser();
const errors = [];
const origin = env.APP_URL.replace(/\/$/, '');

async function login(actor, redirect) {
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    viewport: { width: 393, height: 852 },
  });
  await context.addInitScript(() => localStorage.setItem('platform.locale', 'ru'));
  const page = await context.newPage();
  page.setDefaultTimeout(30000);
  const contextStatuses = [];
  page.on('response', (response) => {
    if (new URL(response.url()).pathname === '/api/education/context')
      contextStatuses.push(response.status());
  });
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto(`${origin}/education/login?redirect=${encodeURIComponent(redirect)}`);
  let accessToken;
  let headers;
  async function authenticate(target) {
    await page.locator('#education-email').fill(actor.email);
    await page.locator('#education-password').fill(actor.password);
    const [response] = await Promise.all([
      page.waitForResponse((result) => new URL(result.url()).pathname === '/api/auth/login'),
      page.locator('form button[type="submit"]').click(),
    ]);
    assert.equal(response.status(), 200, 'Core login failed');
    accessToken = (await response.json()).data.accessToken;
    headers = Object.fromEntries(
      Object.entries(await response.request().allHeaders()).filter(([key]) =>
        key.startsWith('x-client-'),
      ),
    );
    try {
      await page.waitForURL((url) => url.pathname + url.search === target);
    } catch (error) {
      const loginFeedback = (await page.locator('.education-login [role="alert"]').allTextContents())
        .map((message) => message.trim()).filter(Boolean).join(' ').slice(0, 200);
      throw new Error(
        `Expected ${target}, reached ${new URL(page.url()).pathname}; Education context HTTP statuses: ${contextStatuses.join(', ') || 'none'}; sign-in feedback: ${loginFeedback || 'none'}.`,
        { cause: error },
      );
    }
  }
  await authenticate(redirect);
  async function reopen() {
    const { pathname, search } = new URL(page.url());
    await page.reload();
    await authenticate(pathname + search);
  }
  async function api(path, {
    method = 'GET', body, status = 200, resource = 'education', organizationId = organization,
  } = {}) {
    const result = await context.request.fetch(`${origin}/api/${resource}${path}`, {
      method,
      headers: {
        ...headers,
        authorization: `Bearer ${accessToken}`,
        'x-education-organization': organizationId,
      },
      ...(body === undefined ? {} : { data: body }),
    });
    assert.ok(
      [status].flat().includes(result.status()),
      `${method} ${path} returned ${result.status()}`,
    );
    assert.equal(result.headers()['cache-control'], 'no-store');
    return (await result.json()).data;
  }
  async function signIn(target) {
    await page.goto(`${origin}/education/login?redirect=${encodeURIComponent(target)}`);
    await authenticate(target);
  }
  return { page, api, reopen, signIn };
}

async function schedule(teacher, personId, title, hours = 2) {
  const group = await teacher.api('/groups', {
    method: 'POST',
    body: { name: `Группа · ${title}` },
    status: 201,
  });
  const student = await teacher.api('/students', {
    method: 'POST',
    body: { groupId: group.id, personId },
    status: 201,
  });
  const startsAt = new Date(Date.now() + hours * 3600000);
  const lesson = await teacher.api('/lessons', {
    method: 'POST',
    status: 201,
    body: {
      groupId: group.id,
      title,
      startsAt: startsAt.toISOString(),
      endsAt: new Date(startsAt.getTime() + 1800000).toISOString(),
    },
  });
  return { id: student.id, groupId: group.id, lesson, title };
}

async function inviteParent(page, student, parent) {
  await navigateClientSide(page, `/students/${student.id}`);
  const family = page.locator('.edu-family');
  await family.locator('#family-person-search').fill('Александра');
  await family.locator('form').first().getByRole('button', { name: 'Поиск на странице' }).click();
  await selectDropdown(page, 'family-person', parent.personId);
  for (const [action, suffix] of [
    ['Добавить представителя', ''],
    ['Подтвердить представительство', `/${parent.personId}/confirm`],
  ]) {
    const button = family.getByRole('button', { name: action });
    await button.waitFor();
    const [response] = await Promise.all([
      page.waitForResponse(
        (response) =>
          response.request().method() === 'POST' &&
          new URL(response.url()).pathname ===
            `/api/education/students/${student.id}/guardians${suffix}`,
      ),
      button.click(),
    ]);
    if (!response.ok()) {
      const failure = await response.json();
      throw new Error(`${action}: HTTP ${response.status()} (${failure.error?.code ?? 'unknown'})`);
    }
  }
  return issueParentCode(page, student.id, parent.personId, 'Выдать приглашение');
}

async function issueParentCode(page, studentId, parentId, label) {
  const family = page.locator('.edu-family');
  const [response] = await Promise.all([
    page.waitForResponse(
      (result) =>
        result.request().method() === 'POST' &&
        new URL(result.url()).pathname ===
          `/api/education/students/${studentId}/guardians/${parentId}/invite`,
    ),
    family.getByRole('button', { name: label, exact: true }).click(),
  ]);
  assert.ok(response.ok(), `Parent invitation issuance returned HTTP ${response.status()}`);
  const { data } = await response.json();
  const shown = await family.locator('.edu-family-invite code').innerText();
  assert.ok(shown.trim() === data.code, 'The parent invitation code was not shown to the issuer.');
  return data;
}

async function activateParent(page, code, success) {
  await page.locator('#parent-invite-code').fill(code);
  const [response] = await Promise.all([
    page.waitForResponse(
      (response) => new URL(response.url()).pathname === '/api/education/parent/activate',
    ),
    page.locator('form button[type="submit"]').click(),
  ]);
  assert.equal(response.status(), success ? 200 : 404);
  if (success) await page.waitForURL((url) => url.pathname === '/parent/home');
  else await page.locator('.edu-error[role="alert"]').waitFor();
}

async function activateStudent(teacher, first) {
  await navigateClientSide(teacher.page, `/students/${first.id}`);
  const issue = teacher.page.getByRole('button', { name: 'Пригласить ученика' });
  await issue.waitFor();
  const [response] = await Promise.all([
    teacher.page.waitForResponse(
      (response) =>
        new URL(response.url()).pathname === `/api/education/students/${first.id}/invite`,
    ),
    issue.click(),
  ]);
  assert.ok(response.ok(), 'Teacher could not issue a student invitation.');
  const {
    data: { code },
  } = await response.json();
  const displayedCode = teacher.page
    .locator('p')
    .filter({ hasText: 'Код приглашения (одноразовый, действует 72 часа)' })
    .locator('strong');
  assert.ok(
    (await displayedCode.innerText()).trim() === code,
    'The UI did not show the issued student invite.',
  );
  const student = await login(fixture.accounts.student, '/education/student-activate');
  const { page } = student;
  await page.locator('#education-student-invite').fill(code);
  let activated;
  try {
    [activated] = await Promise.all([
      page.waitForResponse(
        (response) => new URL(response.url()).pathname === '/api/education/student-activate',
      ),
      page.locator('form button[type="submit"]').click(),
    ]);
  } catch (error) {
    const path = new URL(page.url()).pathname;
    const alert = await page
      .locator('main .edu-error[role="alert"]')
      .first()
      .textContent({ timeout: 1000 })
      .catch(() => '');
    throw new Error(
      `Student activation did not reach Core at ${path}${alert ? `; UI: ${alert}` : ''}.`,
      { cause: error },
    );
  }
  if (!activated.ok()) {
    const failure = await activated.json();
    throw new Error(
      `Student activation: HTTP ${activated.status()} (${failure.error?.meta?.educationCode ?? failure.error?.code ?? 'unknown'})`,
    );
  }
  await page.waitForURL((url) => url.pathname === '/student/home');
  return student;
}

async function verifyStudent(teacher, first) {
  const student = await activateStudent(teacher, first);
  const { page, api } = student;
  await page.getByText(first.title, { exact: true }).waitFor();
  await page.locator(`a[href="/student/diary/${first.lesson.id}"]`).click();
  await page.getByRole('heading', { name: first.title }).waitFor();
  await page.locator('.app-bottom-nav a[aria-current="page"][href="/student/diary"]:visible').waitFor();
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.locator('.edu-student-tabs a[aria-current="page"][href="/student/diary"]:visible').waitFor();
  assert.equal(await page.locator('.edu-student-tabs a[href="/student/schedule"]').isVisible(), true);
  assert.equal(await page.locator('.app-bottom-nav').isVisible(), false);
  await page.setViewportSize({ width: 393, height: 852 });
  assert.equal((await api('/student/home')).nextLesson.id, first.lesson.id);
  await page.locator('a[href="/student/documents"]:visible').click();
  const bytes = Buffer.from('Disposable student document\n');
  const name = 'student-document.txt';
  await page
    .locator('input[type="file"]')
    .setInputFiles({ name, mimeType: 'text/plain', buffer: bytes });
  const file = page.locator('.edu-list__item').filter({ hasText: name });
  await file.waitFor();
  await student.reopen();
  await file.waitFor();
  const downloaded = page.waitForEvent('download');
  await file.getByRole('button', { name: 'Скачать' }).click();
  const download = await downloaded;
  assert.deepEqual(readFileSync(await download.path()), bytes);
  mkdirSync('screenshots/education-real', { recursive: true });
  await page.screenshot({
    path: 'screenshots/education-real/student-documents.png',
    animations: 'disabled',
  });
  console.log(
    'Student invitation, login, lesson details and private S3 upload/download verified after reload.',
  );
  await verifyLearning({ teacher, student, first });
}

async function verifyParent(teacher, first) {
  const second = await schedule(teacher, fixture.candidates[0].id, 'Занятие второго ребёнка', 3);
  const other = await schedule(teacher, fixture.candidates[1].id, 'Занятие другого ученика', 4);
  const firstInvite = await inviteParent(teacher.page, first, fixture.accounts.parent);
  const expired = await inviteParent(teacher.page, second, fixture.accounts.parent);
  assert.equal(runFixture('expire-parent-invite', expired.id).updated, true);
  const guest = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
  try {
    const response = await guest.get(`${origin}/api/education/parent/context`);
    assert.equal(response.status(), 401);
  } finally {
    await guest.dispose();
  }
  const { page, api } = await login(fixture.accounts.parent, '/parent/activate');
  await observeAuthenticated(page);
  await activateParent(page, 'invalid-disposable-invitation', false);
  await activateParent(page, expired.code, false);
  const secondInvite = await issueParentCode(
    teacher.page,
    second.id,
    fixture.accounts.parent.personId,
    'Повторить приглашение',
  );
  await activateParent(page, firstInvite.code, true);
  await page.getByText(first.title, { exact: true }).waitFor();
  await page.locator('a[href="/parent/activate"]').click();
  await activateParent(page, firstInvite.code, false);
  await activateParent(page, secondInvite.code, true);

  for (const width of [320, 393, 768, 1024, 1440]) {
    await page.setViewportSize({ width, height: 852 });
    await selectDropdown(page, 'education-parent-child', `${organization}:${first.id}`);
    await page.getByText(first.title, { exact: true }).waitFor();
    assert.equal(await page.getByText(second.title, { exact: true }).count(), 0);
    assert.ok(
      await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
      `Parent home overflow at ${width}px`,
    );
    await selectDropdown(page, 'education-parent-child', `${organization}:${second.id}`);
    await page.getByText(second.title, { exact: true }).waitFor();
    assert.equal(await page.getByText(first.title, { exact: true }).count(), 0);
  }

  const parentHome = new URL(page.url());
  const timeOrigin = await page.evaluate(() => performance.timeOrigin);
  assert.equal((await api('/parent/context')).locale, 'ru-RU');
  const english = page.getByRole('button', { name: 'EN — English', exact: true });
  await english.click();
  await page.getByRole('heading', { name: 'Parent cabinet', exact: true }).waitFor();
  assert.equal(await english.getAttribute('aria-pressed'), 'true');
  assert.equal(await page.locator('html').getAttribute('lang'), 'en');
  assert.equal(page.url(), parentHome.href);
  await page.getByText(second.title, { exact: true }).waitFor();
  const languageAxe = await new AxeBuilder({ page }).include('.edu-parent-topbar')
    .withRules(['label-content-name-mismatch']).analyze();
  assert.deepEqual(languageAxe.violations.map(({ id }) => id), []);
  await captureAuthenticated(page, { suite: 'education-parent', screen: 'home' });

  await page.locator('a[href="/parent/activate"]').click();
  await page.waitForURL((url) => url.pathname === '/parent/activate');
  await Promise.all([
    page.waitForResponse((response) =>
      response.ok() && new URL(response.url()).pathname === '/api/education/parent/context'),
    navigateClientSide(page, parentHome.pathname + parentHome.search),
  ]);
  await page.getByText(second.title, { exact: true }).waitFor();
  assert.equal(await page.locator('html').getAttribute('lang'), 'en');
  assert.equal(await page.evaluate(() => performance.timeOrigin), timeOrigin);
  await page.getByRole('button', { name: 'RU — Русский', exact: true }).click();
  await page.getByRole('heading', { name: 'Кабинет родителя', exact: true }).waitFor();
  assert.equal(await page.locator('html').getAttribute('lang'), 'ru');
  assert.equal(page.url(), parentHome.href);
  console.log('Parent RU/EN, selected child and language persistence after context refresh verified.');

  for (const section of ['schedule', 'diary', 'homework', 'progress', 'notifications']) {
    await Promise.all([
      page.waitForResponse(
        (response) =>
          response.ok() &&
          new URL(response.url()).pathname ===
            `/api/education/parent/children/${second.id}/${section}`,
      ),
      page.locator(`.edu-parent-nav a[href^="/parent/${section}"]`).click(),
    ]);
    await page.locator(`.edu-parent-nav a[aria-current="page"][href^="/parent/${section}"]`).waitFor();
  }
  await page.setViewportSize({ width: 320, height: 740 });
  assert.ok(await page.locator('.edu-parent-nav').evaluate((nav) => {
    const viewport = nav.getBoundingClientRect();
    return nav.scrollWidth > nav.clientWidth && [...nav.querySelectorAll('a')].every((link) => {
      const target = link.getBoundingClientRect();
      return target.height >= 44 && target.width >= 44 && target.top >= viewport.top &&
        target.bottom <= viewport.bottom;
    });
  }), 'Parent tabs should stay in one independently scrollable row at 320px');
  await page.setViewportSize({ width: 393, height: 852 });
  assert.equal(await page.locator('a[href^="/student/"], a[href^="/lessons/"]').count(), 0);
  const secondHome = `**/api/education/parent/children/${second.id}/home`;
  const disconnect = (route) => route.abort('failed');
  await page.route(secondHome, disconnect);
  await page
    .getByRole('navigation', { name: 'Кабинет родителя' })
    .getByRole('link', { name: 'Главная', exact: true })
    .click();
  const retry = page.getByRole('alert').getByRole('button', { name: 'Повторить' });
  await retry.waitFor();
  await page.unroute(secondHome, disconnect);
  await retry.click();
  await page.getByText(second.title, { exact: true }).waitFor();

  const firstHome = `**/api/education/parent/children/${first.id}/home`;
  const firstRead = Promise.withResolvers();
  const releaseRead = Promise.withResolvers();
  const firstReadFinished = Promise.withResolvers();
  await page.route(
    firstHome,
    async (route) => {
      try {
        const response = await route.fetch({ timeout: 10000 });
        firstRead.resolve();
        await releaseRead.promise;
        await route.fulfill({ response }).catch(() => {});
      } catch (error) {
        firstRead.reject(error);
      } finally {
        firstReadFinished.resolve();
      }
    },
    { times: 1 },
  );
  try {
    await Promise.all([
      page.waitForRequest(firstHome),
      selectDropdown(page, 'education-parent-child', `${organization}:${first.id}`),
      firstRead.promise,
    ]);
    await selectDropdown(page, 'education-parent-child', `${organization}:${second.id}`);
    await page.getByText(second.title, { exact: true }).waitFor();
  } finally {
    releaseRead.resolve();
  }
  await firstReadFinished.promise;
  assert.equal(await page.getByText(first.title, { exact: true }).count(), 0);
  await api(`/parent/children/${other.id}/home`, { status: [403, 404] });
  await navigateClientSide(page, `/parent/home?child=${other.id}&organization=${organization}`);
  const unavailable = page.getByText(/Доступ к выбранному ребёнку отсутствует/);
  await unavailable.waitFor();
  assert.equal(await page.getByText(other.title, { exact: true }).count(), 0);

  await navigateClientSide(page, `/parent/home?child=${first.id}&organization=${organization}`);
  await page.getByText(first.title, { exact: true }).waitFor();
  await navigateClientSide(teacher.page, `/students/${first.id}`);
  const family = teacher.page.locator('.edu-family');
  await family.getByRole('button', { name: 'Отозвать связь', exact: true }).click();
  await family.getByRole('group', { name: 'Отозвать связь' }).getByRole('button').first().click();
  await family.getByText('Представитель · Отозвана', { exact: true }).waitFor();
  if (!(await unavailable.isVisible())) {
    const refresh = page.locator('.edu-parent-section header').getByRole('button', { name: 'Обновить' });
    await Promise.race([unavailable.waitFor(), refresh.click()]);
  }
  await unavailable.waitFor();
  assert.equal(await page.getByText(first.title, { exact: true }).count(), 0);
  await api(`/parent/children/${first.id}/home`, { status: [403, 404] });
  const secondHomePath = `/parent/children/${second.id}/home`;
  const remaining = await api(secondHomePath);
  assert.equal(remaining.nextLesson?.title, second.title, 'Revocation must preserve the other child’s schedule.');
  const outcomes = [];
  const recordHome = (response) => {
    if (new URL(response.url()).pathname === `/api/education${secondHomePath}`) outcomes.push(response.status());
  };
  page.on('response', recordHome);
  const hasSecondChild = (url) => url.pathname === '/parent/home'
    && url.searchParams.get('child') === second.id
    && url.searchParams.get('organization') === organization;
  try {
    await selectDropdown(page, 'education-parent-child', `${organization}:${second.id}`);
    await page.waitForURL(hasSecondChild);
    await page.getByText(second.title, { exact: true }).waitFor();
  } catch (error) {
    throw new Error(
      `Switch after revocation: URL selected remaining child=${hasSecondChild(new URL(page.url()))}, home HTTP statuses=${outcomes.join(', ') || 'none'}, unavailable message=${await unavailable.isVisible()}.`,
      { cause: error },
    );
  } finally {
    page.off('response', recordHome);
  }
  console.log(
    'Parent invitation, replay rejection, child reads, switching, unrelated-child denial and revocation verified.',
  );
}

async function verifyDevice(teacher, student, next) {
  const { page, api } = teacher;
  await navigateClientSide(page, '/devices');
  await page.locator('#search-filter').waitFor();
  await page.getByRole('button', { name: 'Добавить компьютер', exact: true }).click();
  const form = page.locator('form').filter({ has: page.locator('#education-device-number') });
  await form.locator('#education-device-number').fill('F06-1');
  await form.locator('#education-device-name').fill('Учебный компьютер');
  await form.getByRole('button', { name: 'Сохранить', exact: true }).click();
  const card = page.locator('.edu-seat-card').filter({ hasText: 'Учебный компьютер' });
  await card.getByText('нет на связи', { exact: true }).waitFor();

  const resource = 'device-management';
  const listing = await api('/devices', { resource });
  const device = listing.items.find((item) => item.number === 'F06-1');
  assert.ok(device, 'Created device is missing from the scoped backend list.');
  const deviceDetails = () => api(`/devices/${device.id}`, { resource });
  const dialog = page.getByRole('dialog', { name: 'Подробнее' });
  await card.getByRole('button', { name: 'Подробнее' }).click();
  await dialog.getByRole('button', { name: 'Код регистрации' }).click();
  const code = (await dialog.locator('.edu-invite-pill code').innerText()).trim();

  // Only the Agent protocol is simulated. Operator actions still happen through Vue and Core.
  const agent = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
  let credential;
  async function reportAgent(path, data, credential, expected = 200) {
    const response = await agent.post(`${env.CORE_API_URL}/api/device-agent/${path}`, {
      data,
      ...(credential ? { headers: { Authorization: `Bearer ${credential}` } } : {}),
    });
    assert.equal(response.status(), expected, `Device test adapter failed on ${path}`);
    return (await response.json()).data;
  }
  try {
    const enrolled = await reportAgent('enroll', { code }, '', 201);
    credential = enrolled.credential;
    assert.equal(enrolled.deviceId, device.id);
    const registered = await deviceDetails();
    assert.equal(registered.status, 'OFFLINE');
    assert.ok(!registered.availableActions.includes('assign'));
    await reportAgent(
      'heartbeat',
      {
        state: {
          version: 'frontend06-test-adapter',
          machineReady: true,
          hasUnfinishedDocuments: false,
        },
      },
      enrolled.credential,
    );
    await reportAgent(
      'events',
      { kind: 'boot', payload: { testAdapter: true } },
      enrolled.credential,
    );
  } finally {
    await agent.dispose();
  }

  await dialog.getByRole('button', { name: 'Обновить' }).click();
  await dialog.getByRole('button', { name: 'Назначить ученика' }).click();
  await page.locator('.edu-roster-item').filter({ hasText: 'Мира' }).click();
  await page.getByRole('button', { name: 'Начать подготовку' }).click();
  await card.getByText('зарезервирован', { exact: true }).waitFor();
  const reserved = await deviceDetails();
  assert.equal(reserved.sessions[0].studentId, student.id);
  if (!(await dialog.isVisible())) await card.getByRole('button', { name: 'Подробнее' }).click();
  if (next) {
    const { verifyClassroomLifecycle } = await import('./e2e-education-classroom.mjs');
    await verifyClassroomLifecycle({
      teacher, dialog, card, device, deviceDetails, credential, next, first: reserved.sessions[0],
      coreUrl: env.CORE_API_URL, otherOrganizationId: fixture.otherOrganizationId,
    });
    return;
  }
  await dialog.getByRole('button', { name: 'Отменить назначение' }).click();
  await page
    .getByRole('dialog', { name: 'Отменить назначение' })
    .getByRole('button', { name: 'Отменить назначение' })
    .click();
  await card.getByText('нет на связи', { exact: true }).waitFor();
  assert.equal((await deviceDetails()).sessions[0].status, 'CLOSED');
  if (await dialog.isVisible()) await dialog.getByRole('button', { name: 'Назад' }).click();

  await card.getByRole('button', { name: 'Журнал компьютера' }).click();
  const history = page.getByRole('dialog', { name: 'Журнал компьютера' });
  await history.getByText('Мира', { exact: false }).waitFor();
  await history.getByRole('button', { name: 'События и ошибки' }).click();
  await history.getByText('Агент запущен', { exact: true }).waitFor();
  await history.getByText('Назначение отменено', { exact: true }).waitFor();
  await history.getByRole('button', { name: 'История команд' }).click();
  await history.locator('.edu-list').waitFor();
  mkdirSync('screenshots/education-real', { recursive: true });
  await page.screenshot({
    path: 'screenshots/education-real/device-history.png',
    animations: 'disabled',
  });
  console.log(
    'Device registration, readiness heartbeat, assignment, cancellation and journal verified with a synthetic Agent protocol adapter.',
  );
}

try {
  const teacher = await login(fixture.accounts.teacher, '/today');
  if (role === 'teacher') {
    const { verifyEnrollmentToJournal } = await import('./e2e-education-teacher.mjs');
    await verifyEnrollmentToJournal(teacher, { otherOrganizationId: fixture.otherOrganizationId });
  } else {
    const first = await schedule(
      teacher,
      fixture.accounts.student.personId,
      'Занятие первого ребёнка',
    );
    assert.ok(first.id, 'Education fixture enrollment did not return a student ID.');
    if (role === 'student') await verifyStudent(teacher, first);
    else if (role === 'storage') {
      const { verifyStorageRecovery } = await import('./e2e-education-storage.mjs');
      await verifyStorageRecovery({
        student: await activateStudent(teacher, first),
        organizationId: organization,
        otherOrganizationId: fixture.otherOrganizationId,
        switchOrganizationId: fixture.switchOrganizationId,
        openStudent: () => login(fixture.accounts.student, '/student/documents'),
        setQuota: (bytes) => runFixture('set-storage-quota', organization, first.id, String(bytes)),
      });
    } else if (role === 'parent') await verifyParent(teacher, first);
    else if (role === 'classroom') {
      // Seed both students before the assignment dialog caches the roster.
      const next = await teacher.api('/students', {
        method: 'POST',
        body: { groupId: first.groupId, personId: fixture.candidates[0].id },
        status: 201,
      });
      assert.ok(next?.id, 'Classroom fixture enrollment did not return the second student ID.');
      await verifyDevice(teacher, first, next);
    } else await verifyDevice(teacher, first);
  }
  assert.deepEqual(errors, [], 'Uncaught browser errors');
} finally {
  await browser.close();
}
