// Real HTTP, PostgreSQL and private files on a proven disposable Core runtime. No API routes are mocked.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { request as playwrightRequest } from 'playwright';
import AxeBuilder from '@axe-core/playwright';
import { evaluateCoreMutationGate } from './core-e2e-guard.mjs';
import { launchBrowser, selectDropdown, stableScreenshot } from './e2e-support.mjs';
import { captureAuthenticated, observeAuthenticated } from './frontend09-authenticated-evidence.mjs';

const env = process.env;
if (process.argv.includes('--check-guard')) {
  const gate = await evaluateCoreMutationGate(env);
  assert.equal(gate.allowed, false);
  console.log('Education mutations refuse without a proven disposable Core runtime.');
  process.exit(0);
}
const proof = await playwrightRequest.newContext({ ignoreHTTPSErrors: true });
try {
  const gate = await evaluateCoreMutationGate(env, async (url) => {
    const r = await proof.get(url, { timeout: 5000 });
    return { status: r.status(), json: () => r.json() };
  });
  assert.equal(gate.allowed, true, gate.reasons.join('; '));
} finally {
  await proof.dispose();
}
function fixture(command) {
  assert.ok(env.CORE_TEST_CONTAINER);
  try {
    return JSON.parse(
      execFileSync(
        'docker',
        ['exec', env.CORE_TEST_CONTAINER, 'php', 'tests/e2e/education-fixture.php', command],
        { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
      ),
    );
  } catch {
    throw new Error('Disposable Education fixture failed; private output suppressed.');
  }
}
const seeded = fixture('seed');
const browser = await launchBrowser();
const contexts = [];
const errors = [];
const states = [];
const requests = [];
function report(message) {
  states.push(message);
  console.log(message);
}
const artifact = 'screenshots/education-real';
mkdirSync(artifact, { recursive: true });
let main;
async function session(actor, width = 393) {
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    viewport: { width, height: 852 },
  });
  contexts.push(context);
  context.setDefaultTimeout(30000);
  context.setDefaultNavigationTimeout(45000);
  await context.addInitScript(() => {
    localStorage.setItem('platform.locale', 'ru');
    localStorage.setItem('platform.theme', 'light');
  });
  const page = await context.newPage();
  let access;
  let refresh;
  let clientHeaders = {};
  page.on('pageerror', (error) => errors.push(error.message));
  page.on('request', (request) => {
    requests.push(request.url());
    if (new URL(request.url()).pathname === '/api/auth/login')
      clientHeaders = Object.fromEntries(
        Object.entries(request.headers()).filter(([key]) => key.startsWith('x-client-')),
      );
  });
  page.on('response', async (response) => {
    const path = new URL(response.url()).pathname;
    if (
      response.status() >= 400 &&
      (path.startsWith('/api/education') || path === '/api/auth/login')
    )
      console.log(
        `Education API ${response.status()}: ${path.replace(/[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}/g, ':id')}`,
      );
    if (/\/api\/auth\/(login|refresh|mfa\/verify)$/.test(path) && response.ok()) {
      const value = await response.json().catch(() => null);
      if (value?.data?.accessToken) {
        access = value.data.accessToken;
        refresh = value.data.refreshToken;
      }
    }
  });
  await page.goto(`${env.APP_URL}/education/login?redirect=/today`, { waitUntil: 'domcontentloaded' });
  const emailInput = page.locator('#education-email');
  await emailInput.waitFor({ state: 'visible', timeout: 30000 });
  await emailInput.fill(actor.email);
  await page.locator('#education-password').fill(actor.password);
  await page.locator('form button[type=submit]').click();
  await page.waitForURL((url) => url.pathname === '/today', { timeout: 30000 });
  async function api(
    path,
    { method = 'GET', body, organization = actor.organizationId, status = 200 } = {},
  ) {
    assert.ok(access, 'A real Core access token was not observed.');
    const result = await page.evaluate(
      async ({ path, method, body, organization, access, headers }) => {
        const response = await fetch('/api/education' + path, {
          method,
          credentials: 'omit',
          headers: {
            ...headers,
            Authorization: `Bearer ${access}`,
            'X-Education-Organization': organization,
            ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
          },
          body: body === undefined ? undefined : JSON.stringify(body),
          signal: AbortSignal.timeout(15000),
        });
        return {
          status: response.status,
          cache: response.headers.get('Cache-Control'),
          json: await response.json(),
        };
      },
      { path, method, body, organization, access, headers: clientHeaders },
    );
    assert.equal(result.status, status, `${path}: unexpected HTTP status`);
    assert.equal(result.cache, 'no-store', `${path}: personal response was cacheable`);
    return result.json.data;
  }
  async function storage() {
    const value = await page.evaluate(() =>
      JSON.stringify({ local: { ...localStorage }, session: { ...sessionStorage } }),
    );
    for (const secret of [access, refresh, actor.password].filter(Boolean))
      assert.ok(!value.includes(secret), 'An authentication secret reached browser storage.');
  }
  return { page, api, storage };
}
function wall(instant, zone) {
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('en-CA', {
      timeZone: zone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
    })
      .formatToParts(new Date(instant))
      .map((item) => [item.type, item.value]),
  );
  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}
function localNoon(date, zone) {
  const utcNoon = Date.parse(`${date}T12:00:00Z`);
  const wallAtUtcNoon = Date.parse(`${wall(new Date(utcNoon), zone)}:00Z`);
  return new Date(utcNoon + (utcNoon - wallAtUtcNoon));
}
async function eventually(action, message) {
  for (let attempt = 0; attempt < 30; attempt++) {
    if (await action()) return;
    await new Promise((resolve) => setTimeout(resolve, 200));
  }
  throw new Error(message);
}
function workspaceLink(page, path) {
  return page.locator(`.edu-sidebar__nav:visible a[href="${path}"], .edu-navigation:visible a[href="${path}"]`);
}
async function today(page) {
  await workspaceLink(page, '/today').click();
  await page.locator('.edu-quick').waitFor();
}
async function openCalendarLesson(page, date, id) {
  await workspaceLink(page, '/calendar').click();
  await page.locator('#education-calendar-date').fill(date);
  await page.locator(`a[href="/lessons/${id}"]`).click();
  await page.waitForURL((url) => url.pathname === `/lessons/${id}`);
}
async function inspect(page, label, expectedSection = '/today') {
  await page.evaluate(() => document.fonts.ready);
  assert.equal(
    await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1),
    false,
    label + ': horizontal overflow',
  );
  const desktop = page.viewportSize().width >= 768;
  const navigation = page.locator(desktop ? '.edu-sidebar__nav' : '.edu-navigation');
  const duplicate = page.locator(desktop ? '.edu-navigation' : '.edu-sidebar__nav');
  assert.equal(await navigation.isVisible(), true, label + ': expected navigation');
  assert.equal(await duplicate.isVisible(), false, label + ': duplicate navigation');
  const active = navigation.locator('[aria-current="page"]');
  await navigation.locator(`[aria-current="page"][href="${expectedSection}"]`).waitFor();
  assert.equal(await active.count(), 1, label + ': active navigation');
  assert.equal(await active.getAttribute('href'), expectedSection);
  if (!desktop) {
    assert.equal(await navigation.locator('a').count(), 5);
    const { height } = await navigation.boundingBox();
    assert.ok(Math.abs(height - 72) < 1, label + ': navigation height ' + height);
  }
  const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
  assert.deepEqual(
    axe.violations.map((v) => ({ id: v.id, targets: v.nodes.map((n) => n.target) })),
    [],
    label + ': axe',
  );
  await stableScreenshot(page, `${artifact}/${label}.png`);
  const screen = label.startsWith('lesson-edit') ? 'lesson-edit'
    : { '/today': 'today', '/people': 'group', '/calendar': 'lesson' }[expectedSection];
  if (screen) await captureAuthenticated(page, { suite: 'education-teacher', screen });
}
try {
  main = await session(seeded.accounts.teacher);
  const { page, api } = main;
  await observeAuthenticated(page);
  await page.getByText('На этот день ничего не запланировано', { exact: true }).waitFor();
  assert.equal(await page.locator('.edu-quick__action').count(), 6);
  assert.equal(await page.locator('.edu-attention').count(), 0);
  await api('/today/header', { organization: seeded.otherOrganizationId, status: 403 });
  report('real empty day, no fabricated next lesson/attention; organization scope denied');

  await workspaceLink(page, '/people').click();
  for (const [source, destination] of [
    ['/people', '/students/new'],
    ['/people?tab=groups', '/groups/new'],
  ]) {
    if (source.includes('?')) await page.locator('.edu-toolbar a[href="/people?tab=groups"]').click();
    await page.waitForURL((url) => url.pathname + url.search === source);
    await page.locator(`.edu-page-title a[href="${destination}"]`).click();
    await page.waitForURL((url) => url.pathname === destination);
    await page.locator(`.edu-page-title a[href="${source}"]`).click();
    await page.waitForURL((url) => url.pathname + url.search === source);
    await page.locator('.edu-navigation a[href="/people"][aria-current="page"]').waitFor();
  }
  report('Navigation: People list branches reach group and student forms; form header returns to the matching list and query');
  await today(page);

  await page.locator('[data-action="createGroup"]').click();
  await page.waitForURL((url) => url.pathname === '/groups/new' && !url.search);
  await page.locator('#education-create-title').fill('Группа ВС 11:00');
  await page.locator('form button[type=submit]').click();
  await page.waitForURL((url) => url.pathname !== '/groups/new' && (url.pathname === '/people' || /^\/groups\/[0-9a-f-]+$/.test(url.pathname)));
  let group;
  await eventually(async () => {
    group = (await api('/groups')).items.find((item) => item.name === 'Группа ВС 11:00');
    return Boolean(group);
  }, 'Group was not found in /groups list');
  assert.ok(group);
  // Prepare the larger roster before the actual enrollment through the browser.
  for (const candidate of seeded.candidates.slice(1))
    await api('/students', {
      method: 'POST',
      body: { groupId: group.id, personId: candidate.id },
      status: 201,
    });
  await page.getByRole('link', { name: 'Добавить ученика', exact: true }).click();
  await page.waitForURL((url) => url.pathname.includes('/students/new'));
  const existingTab = page.locator('button', { hasText: /Выбрать существующего|Choose existing/ });
  await existingTab.waitFor({ state: 'visible' });
  await existingTab.click();
  await page.waitForFunction(() => {
    const btn = Array.from(document.querySelectorAll('button')).find((b) =>
      /Выбрать существующего|Choose existing/.test(b.textContent || ''),
    );
    return btn && btn.getAttribute('aria-pressed') === 'true';
  });
  await selectDropdown(page, 'education-create-person', seeded.candidates[0].id);
  await page.locator('form button[type=submit]').click();
  await page.waitForURL((url) => url.pathname === '/people' || url.pathname.startsWith('/groups/'));
  assert.equal((await api('/groups')).items.find((item) => item.id === group.id).studentCount, 12);
  const roster = (await api('/students')).items;
  await workspaceLink(page, '/people').click();
  await page.getByRole('link', { name: 'Группы', exact: true }).click();
  await page.getByRole('link', { name: 'Открыть группу', exact: true }).click();
  await page.waitForURL((url) => url.pathname === `/groups/${group.id}`);
  await page.locator('[data-student-id]').first().waitFor();
  assert.equal(await page.locator('[data-student-id]').count(), 12);
  assert.equal((await api(`/groups/${group.id}/students`)).items.length, 12);
  await page.locator('#education-group-name').fill('Группа ВС 12:00');
  await page.locator('#education-group-name').press('Enter');
  await eventually(
    async () =>
      (await api('/groups')).items.find((item) => item.id === group.id)?.name === 'Группа ВС 12:00',
    'group rename was not persisted',
  );
  await inspect(page, 'group-roster-ru-light', '/people');
  report(
    'group roster view and rename persist real scoped records; 12 Core roster members remain unique',
  );

  const teacherChoice = page.locator(`#teacher-${seeded.accounts.colleague.id}`);
  const teacherForm = page.locator('form').filter({ has: teacherChoice });
  const saveTeachers = () => teacherForm.getByRole('button', { name: 'Сохранить', exact: true }).click();
  const hasColleague = async () => (await api(`/groups/${group.id}/teachers`)).items.some(
    (item) => item.accountId === seeded.accounts.colleague.id && item.assigned,
  );
  await teacherChoice.check();
  await saveTeachers();
  await eventually(hasColleague, 'Teacher delegation did not persist');
  const delegated = await session(seeded.accounts.colleague);
  assert.equal((await delegated.api(`/groups/${group.id}`)).id, group.id);
  await teacherChoice.uncheck();
  await saveTeachers();
  await eventually(async () => !(await hasColleague()), 'Teacher delegation was not revoked');
  await delegated.api(`/groups/${group.id}`, { status: 404 });
  report('group owner delegates and revokes access through the teacher panel; colleague scope follows the saved assignment');

  await page.getByRole('link', { name: 'Создать программу', exact: true }).click();
  await page.getByRole('button', { name: 'Создать программу', exact: true }).click();
  await page.locator('#program-name').fill('Программа занятия');
  await page.locator('form').filter({ has: page.locator('#program-name') })
    .getByRole('button', { name: 'Сохранить', exact: true }).click();
  await page.waitForURL((url) => /^\/programs\/[a-f0-9-]+$/.test(url.pathname));
  const programId = new URL(page.url()).pathname.split('/').at(-1);
  await page.locator('#new-module-name').fill('Практические занятия');
  await page.getByRole('button', { name: 'Добавить модуль', exact: true }).click();
  const newTopic = page.locator('input[id^="new-lesson-"]');
  await newTopic.fill('Первый урок программы');
  await page.getByRole('button', { name: 'Добавить урок', exact: true }).click();
  await page.getByText('1. Первый урок программы', { exact: true }).waitFor();
  await newTopic.fill('Второй урок программы');
  await page.getByRole('button', { name: 'Добавить урок', exact: true }).click();
  await page.getByText('2. Второй урок программы', { exact: true }).waitFor();
  const program = await api(`/programs/${programId}`);
  const [firstTopic, secondTopic] = program.modules[0].lessons;
  assert.equal(firstTopic.title, 'Первый урок программы');
  assert.equal(secondTopic.title, 'Второй урок программы');
  await page.goBack();
  await page.waitForURL((url) => url.pathname === '/programs');
  await page.goBack();
  await page.waitForURL((url) => url.pathname === `/groups/${group.id}`);
  await selectDropdown(page, 'group-program', programId);
  await page.getByRole('button', { name: 'Назначить программу', exact: true }).click();
  await eventually(
    async () => (await api(`/groups/${group.id}/programs`)).items.some(item => item.programId === programId),
    'Group program assignment did not persist',
  );

  await today(page);
  await page.locator('[data-action="createLesson"]').click();
  const header = await api('/today/header');
  const start = new Date(Date.parse(header.now) + 80 * 60000);
  const end = new Date(start.getTime() + 150 * 60000);
  await page.locator('#education-create-title').fill('Python + Ai помощник');
  await selectDropdown(page, 'education-create-group', group.id);
  await selectDropdown(page, 'education-curriculum-lesson', firstTopic.id);
  await page.locator('#education-starts-at').fill(wall(start, header.timezone));
  await page.locator('#education-starts-at').press('Tab');
  await page.locator('#education-ends-at').fill(wall(end, header.timezone));
  await page.locator('#education-ends-at').press('Tab');
  await page.locator('#education-location').fill('Каб. 1');
  await page.locator('form button[type=submit]').click();
  await page.waitForURL((url) => /^\/lessons\/[a-f0-9-]+$/.test(url.pathname));
  const lessonId = page.url().split('/').at(-1);
  const created = await api(`/lessons/${lessonId}`);
  assert.equal(created.studentCount, 12);
  assert.equal(created.curriculumLessonId, firstTopic.id);
  await page.getByRole('link', { name: 'Материалы', exact: true }).click();
  await page.getByRole('link', { name: 'Загрузить материалы', exact: true }).click();
  const bytes = Buffer.from(
    'A real Education material uploaded through the browser.\n' +
      'Education material line.\n'.repeat(140000),
    'utf8',
  );
  await page
    .locator('#education-material-file')
    .setInputFiles({ name: 'lesson-material.txt', mimeType: 'text/plain', buffer: bytes });
  await page.locator('form button[type=submit]').click();
  await page.getByText('lesson-material.txt', { exact: true }).waitFor();
  const [download] = await Promise.all([
    page.waitForEvent('download'),
    page.getByRole('button', { name: 'Скачать', exact: true }).click(),
  ]);
  await download.saveAs(`${artifact}/lesson-material.txt`);
  assert.deepEqual(readFileSync(`${artifact}/lesson-material.txt`), bytes);
  report(
    'lesson form, real participant snapshot, multipart upload and completed browser download with matching bytes',
  );

  await page.getByRole('link', { name: 'Открыть занятие', exact: true }).click();
  await page.getByRole('link', { name: 'Редактировать занятие', exact: true }).click();
  await inspect(page, 'lesson-edit-ru-light', '/calendar');
  const rescheduledStart = new Date(Date.parse(header.now));
  await page.locator('#education-edit-starts-at').fill(wall(rescheduledStart, header.timezone));
  await page.locator('#education-edit-starts-at').press('Tab');
  await page
    .locator('#education-edit-ends-at')
    .fill(wall(new Date(rescheduledStart.getTime() + 30 * 60000), header.timezone));
  await page.locator('#education-edit-ends-at').press('Tab');
  await page.locator('#education-edit-location').fill('Каб. 2');
  await selectDropdown(page, 'education-edit-curriculum-lesson', secondTopic.id);
  await page.locator('form button[type=submit]').click();
  await page.waitForURL((url) => new RegExp(`^/lessons/${lessonId}$`).test(url.pathname));
  const moved = await api(`/lessons/${lessonId}`);
  assert.equal(moved.location, 'Каб. 2');
  assert.equal(moved.curriculumLessonId, secondTopic.id);
  assert.ok(Date.parse(moved.startsAt) <= Date.parse(header.now));
  await openCalendarLesson(page, header.date, lessonId);
  await page.getByRole('link', { name: 'Список', exact: true }).click();
  await selectDropdown(page, `education-attendance-${roster[0].id}`, 'present');
  await eventually(
    async () =>
      (await api(`/lessons/${lessonId}/participants`)).items.find(
        (item) => item.id === roster[0].id,
      )?.status === 'present',
    'attendance was not persisted',
  );
  await page.getByRole('link', { name: 'Открыть занятие', exact: true }).click();
  await page.getByRole('button', { name: 'Завершить занятие', exact: true }).click();
  await page
    .getByRole('dialog')
    .getByRole('button', { name: 'Завершить занятие', exact: true })
    .click();
  await page.getByText('Завершено', { exact: true }).waitFor();
  const completed = await api(`/lessons/${lessonId}`);
  assert.equal(completed.status, 'completed');
  assert.ok(completed.completedAt);
  assert.equal(completed.curriculumLessonId, secondTopic.id);
  report('Program, module, two topics, group assignment and scheduled lesson re-link persisted through completion.');
  const history = await api(`/lessons/${lessonId}/participants`);
  assert.equal(history.items.find((item) => item.id === roster[0].id)?.status, 'present');
  assert.equal((await api(`/lessons/${lessonId}/materials`)).items[0].name, 'lesson-material.txt');
  await openCalendarLesson(page, header.date, lessonId);
  await page.getByText('Завершено', { exact: true }).waitFor();
  await page.getByRole('link', { name: 'Материалы', exact: true }).click();
  await page.getByText('lesson-material.txt', { exact: true }).waitFor();
  await inspect(page, 'lesson-history-ru-light', '/calendar');
  assert.equal((await api(`/lessons/${lessonId}`)).title, 'Python + Ai помощник');
  report(
    'lesson rescheduled through the edit form, updated calendar opened, attendance persisted, completion kept materials and survived reload',
  );

  await today(page);
  await page.locator('[data-action="currentLesson"]').click();
  await page.getByText('Сейчас нет занятия', { exact: true }).waitFor();
  await today(page);
  await page.locator('[data-action="uploadMaterials"]').click();
  await page.locator('#education-material-file').waitFor();
  await workspaceLink(page, '/calendar').click();
  await page.getByRole('link', { name: 'Добавить событие', exact: true }).click();
  await page.locator('#education-create-title').fill('Созвон с родителями');
  // Keep timeline fixtures on the selected civil date, including runs near midnight.
  const meetingStart = localNoon(header.date, header.timezone);
  const meetingEnd = new Date(meetingStart.getTime() + 30 * 60000);
  await page.locator('#education-starts-at').fill(wall(meetingStart, header.timezone));
  await page.locator('#education-starts-at').press('Tab');
  await page.locator('#education-ends-at').fill(wall(meetingEnd, header.timezone));
  await page.locator('#education-ends-at').press('Tab');
  await page.locator('form button[type=submit]').click();
  await page.waitForURL((url) => url.pathname === '/calendar');
  await api('/events', {
    method: 'POST',
    status: 201,
    body: {
      title: 'Илья Гилёв',
      kind: 'birthday',
      startsAt: new Date(meetingStart.getTime() + 10 * 60000).toISOString(),
      endsAt: new Date(meetingEnd.getTime() + 10 * 60000).toISOString(),
      location: 'День рождения',
    },
  });
  const secondStart = new Date(meetingStart.getTime() - 20 * 60000);
  const secondLesson = await api('/lessons', {
    method: 'POST',
    status: 201,
    body: {
      title: 'Python + Ai помощник',
      groupId: group.id,
      startsAt: secondStart.toISOString(),
      endsAt: new Date(secondStart.getTime() + 10 * 60000).toISOString(),
      location: 'Каб. 1',
    },
  });
  const yesterdayLessons = [];
  const yesterday = wall(new Date(meetingStart.getTime() - 86400000), header.timezone).slice(0, 10);
  const yesterdayNoon = localNoon(yesterday, header.timezone).getTime();
  for (let index = 0; index < 2; index++) {
    const at = new Date(yesterdayNoon + index * 3600000);
    const lesson = await api('/lessons', {
      method: 'POST',
      status: 201,
      body: {
        groupId: group.id,
        title: 'Вчерашнее занятие ' + (index + 1),
        startsAt: at.toISOString(),
        endsAt: new Date(at.getTime() + 30 * 60000).toISOString(),
        location: 'Каб. 1',
      },
    });
    yesterdayLessons.push(lesson.id);
    for (const student of roster.slice(0, 3))
      await api(`/lessons/${lesson.id}/participants/${student.id}`, {
        method: 'PUT',
        body: { status: 'absent' },
      });
  }
  const timeline = await api(`/timeline?date=${header.date}`);
  assert.equal(timeline.items.length, 4);
  assert.deepEqual(
    timeline.items.map((item) => item.startsAt),
    timeline.items.map((item) => item.startsAt).sort(),
  );
  assert.equal((await api('/attention')).items.length, 2);
  await today(page);
  await page.locator('.edu-attention__item').first().waitFor();
  report('calendar form and real timeline sort; attention derives from persisted absence records');

  await api(`/lessons/${secondLesson.id}/cancel`, { method: 'POST' });
  for (const id of yesterdayLessons) await api(`/lessons/${id}/complete`, { method: 'POST' });
  await workspaceLink(page, '/people').click();
  await page.getByRole('link', { name: 'Группы', exact: true }).click();
  await page.getByRole('link', { name: 'Открыть группу', exact: true }).click();
  await page.waitForURL((url) => url.pathname === `/groups/${group.id}`);
  await page.getByRole('link', { name: 'Открыть журнал', exact: true }).click();
  await page.locator('.edu-journal__entry').filter({ hasText: 'Python + Ai помощник' }).first().waitFor();
  await page.getByRole('link', { name: 'Группа', exact: true }).click();
  report('completed lesson is visible through the group journal');
  await page.getByRole('button', { name: 'Архивировать', exact: true }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Архивировать', exact: true }).click();
  await page.getByText('Группа в архиве. История занятий сохранена.', { exact: true }).waitFor();
  const archivedGroup = (await api('/groups')).items.find((item) => item.id === group.id);
  assert.ok(archivedGroup.archivedAt);
  assert.equal((await api(`/groups/${group.id}/students`)).items.length, 12);
  assert.equal((await api(`/lessons/${lessonId}`)).status, 'completed');
  assert.equal(
    (await api(`/lessons/${lessonId}/participants`)).items.find((item) => item.id === roster[0].id)
      ?.status,
    'present',
  );
  const restarted = await session(seeded.accounts.teacher);
  await openCalendarLesson(restarted.page, header.date, lessonId);
  await restarted.page.getByText('Завершено', { exact: true }).waitFor();
  assert.equal((await restarted.api(`/lessons/${lessonId}`)).status, 'completed');
  assert.equal(
    (await restarted.api(`/lessons/${lessonId}/materials`)).items[0].name,
    'lesson-material.txt',
  );
  report(
    'archived group keeps roster, completed lesson, attendance and materials intact after a fresh login',
  );

  await today(page);
  await page.locator('[data-action="writeMessage"]').click();
  await selectDropdown(page, 'education-create-recipient', seeded.accounts.colleague.personId);
  await page.locator('#education-create-title').fill('После занятия');
  await page.locator('#education-message-body').fill('Встречаемся после занятия.');
  await page.locator('form button[type=submit]').click();
  await page.waitForURL((url) => /^\/messages\/[a-f0-9-]+$/.test(url.pathname));
  const threadId = page.url().split('/').at(-1);
  await page.getByText('Встречаемся после занятия.', { exact: true }).waitFor();
  const colleague = await session(seeded.accounts.colleague);
  await colleague.page.locator('.edu-greeting').waitFor();
  assert.equal((await colleague.api('/today/header')).unreadCount, 1);
  await workspaceLink(colleague.page, '/messages').click();
  await colleague.page.locator(`.edu-list a[href="/messages/${threadId}"]`).click();
  await colleague.page.getByText('Встречаемся после занятия.', { exact: true }).waitFor();
  await colleague.page.locator('#education-reply').fill('Да, спасибо.');
  await colleague.page.locator('form button[type=submit]').click();
  await colleague.page.getByText('Да, спасибо.', { exact: true }).waitFor();
  const outsider = await session(seeded.accounts.outsider);
  await outsider.page.locator('.edu-greeting').waitFor();
  await outsider.api(`/threads/${threadId}/messages`, {
    organization: seeded.organizationId,
    status: 403,
  });
  await colleague.api(`/lessons/${lessonId}`, { status: 404 });
  await colleague.api(`/groups/${group.id}`, { status: 404 });
  await colleague.api(`/groups/${group.id}/students`, { status: 404 });
  await colleague.api(`/groups/${group.id}/archive`, { method: 'POST', status: 404 });
  await colleague.api(`/groups/${group.id}`, {
    method: 'PUT',
    body: { name: 'Hijacked' },
    status: 404,
  });
  const memberOnly = await session(seeded.accounts.memberOnly);
  await memberOnly.page.getByText('Нет доступных организаций', { exact: true }).waitFor();
  await memberOnly.api('/today/header', { status: 403 });
  report(
    'two real Core users exchange messages; own inbox badge, organization/member isolation and no implicit teacher role',
  );

  await workspaceLink(page, '/more').click();
  const avatarData = readFileSync('design/мобайл.svg', 'utf8').match(
    /data:image\/png;base64,([A-Za-z0-9+/=\s]+)/,
  )?.[1];
  assert.ok(avatarData, 'Canonical profile image is missing.');
  await page.locator('#education-avatar-file').setInputFiles({
    name: 'profile.png',
    mimeType: 'image/png',
    buffer: Buffer.from(avatarData, 'base64'),
  });
  await page.locator('#education-display-name').fill('Юрий');
  await page.locator('form').first().locator('button[type=submit]').click();
  await page.locator('.edu-avatar img').waitFor();
  assert.ok((await api('/today/header')).avatarFileId, 'Profile avatar was not persisted.');
  report('profile alias and actual private avatar upload persist through the real API');
  await today(page);
  for (const language of ['ru-RU', 'en-US']) {
    await workspaceLink(page, '/more').click();
    await selectDropdown(page, 'education-language', language);
    await page.locator('form').nth(1).locator('button[type=submit]').click();
    await page
      .getByRole('status')
      .filter({ hasText: language === 'ru-RU' ? 'Сохранено' : 'Saved' })
      .waitFor();
    for (const theme of ['light', 'dark']) {
      const label =
        language === 'ru-RU'
          ? theme === 'light'
            ? 'Светлая'
            : 'Тёмная'
          : theme === 'light'
            ? 'Light'
            : 'Dark';
      await page.getByRole('button', { name: label, exact: true }).click();
      await today(page);
      for (const width of [393, 1440]) {
        await page.setViewportSize({ width, height: width === 393 ? 852 : 1000 });
        await inspect(page, `${language}-${theme}-${width}`);
      }
      if (language === 'en-US' && theme === 'dark') {
        await page.setViewportSize({ width: 393, height: 852 });
        await workspaceLink(page, '/people').click();
        await page.getByRole('link', { name: 'Groups', exact: true }).click();
        await page.getByRole('link', { name: 'Open group', exact: true }).click();
        await page.locator('[data-student-id]').first().waitFor();
        await inspect(page, 'en-dark-group-393', '/people');
        await page.setViewportSize({ width: 1440, height: 1000 });
        await inspect(page, 'en-dark-group-1440', '/people');
        await openCalendarLesson(page, header.date, lessonId);
        await page.getByText('Completed', { exact: true }).waitFor();
        await page.setViewportSize({ width: 393, height: 852 });
        await inspect(page, 'en-dark-lesson-393', '/calendar');
        await page.setViewportSize({ width: 1440, height: 1000 });
        await inspect(page, 'en-dark-lesson-1440', '/calendar');
        await today(page);
      }
      await workspaceLink(page, '/more').click();
    }
  }
  await main.storage();
  await colleague.storage();
  assert.equal(
    requests.some((url) => /fonts\.(googleapis|gstatic)\.com/.test(url)),
    false,
  );
  fixture('disable');
  await api('/today/header', { status: 404 });
  fixture('enable');
  assert.deepEqual(errors, []);
  writeFileSync(
    `${artifact}/results.json`,
    JSON.stringify(
      {
        passed: true,
        realBackend: true,
        states,
        materialSha256: createHash('sha256').update(bytes).digest('hex'),
        baselineViewport: '393x852',
      },
      null,
      2,
    ),
  );
  console.log(JSON.stringify({ passed: true, realBackend: true, states }));
} catch (error) {
  for (const [index, context] of contexts.entries()) {
    const page = context.pages()[0];
    if (page && !page.isClosed())
      await stableScreenshot(page, `${artifact}/failure-${index}.png`).catch(() => undefined);
  }
  console.error('Education E2E failed; private fixture and auth output suppressed.');
  throw error;
} finally {
  fixture('enable');
  for (const context of contexts) await context.close();
  await browser.close();
}
