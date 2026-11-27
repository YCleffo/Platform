// PLATFORM_16_A Local Acceptance: Teacher Cabinet Finalization
// Scenario: Login -> Dashboard -> Group -> Lesson -> Attendance -> Grade & Journal -> Homework -> Assessment Assign -> Student Result -> Message Student
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { request as pwRequest } from 'playwright';
import { launchBrowser, navigateClientSide, selectDropdown } from './e2e-support.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SCREENSHOT_DIR = path.resolve(__dirname, '../../../docs/audits/screenshots');
const BACKEND_URL = process.env.APP_URL || 'http://127.0.0.1:8083';
const FRONTEND_URL = process.env.APP_FRONTEND_URL || 'http://127.0.0.1:5173';

function runFixture(command, ...args) {
  const output = execFileSync(
    'docker',
    ['exec', 'platform-backend', 'php', 'tests/e2e/platform12-workspace-fixture.php', command, ...args],
    { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
  );
  return JSON.parse(output.trim());
}

async function apiReq(apiCtx, urlPath, { method = 'GET', token, orgId, jsonBody } = {}) {
  const hdrs = { Accept: 'application/json' };
  if (token) hdrs.Authorization = `Bearer ${token}`;
  if (orgId) hdrs['X-Education-Organization'] = orgId;
  const opts = { method, headers: hdrs };
  if (jsonBody !== undefined) {
    hdrs['Content-Type'] = 'application/json';
    opts.data = JSON.stringify(jsonBody);
  }
  const res = await apiCtx.fetch(urlPath, opts);
  const text = await res.text();
  let json = null;
  try {
    json = JSON.parse(text);
  } catch {
    // non-json
  }
  return { status: res.status(), json, text };
}

async function main() {
  const seed = runFixture('seed');
  const { nonce, teacher, student1, orgA, orgB, groupA, groupB } = seed;
  const groupAName = `Алгебра Quantum ${nonce}`;
  console.log('Seeded isolated fixture:', { orgA, groupA, teacher: teacher.email });

  const apiCtx = await pwRequest.newContext({ baseURL: BACKEND_URL, ignoreHTTPSErrors: true });

  // 1. Verify cross-org ACL isolation: teacher in orgA cannot access groupB (in orgB)
  const crossOrgGroup = await apiReq(apiCtx, `/api/education/groups/${groupB}`, {
    token: teacher.token,
    orgId: orgA,
  });
  assert.ok(
    crossOrgGroup.status === 403 || crossOrgGroup.status === 404,
    `Expected 403/404 for cross-org group access, got ${crossOrgGroup.status}`,
  );

  // Verify Dashboard API returns all required teacher sections
  const dashRes = await apiReq(apiCtx, `/api/workspace/dashboard?organizationId=${orgA}`, {
    token: teacher.token,
  });
  assert.equal(dashRes.status, 200, `Dashboard API failed: ${dashRes.text}`);
  const eduWidget = dashRes.json.data.widgets.find((w) => w.id === 'education.overview');
  assert.ok(eduWidget, 'education.overview widget missing');
  assert.ok(Array.isArray(eduWidget.data.currentGroups), 'currentGroups missing');
  assert.ok(eduWidget.data.currentGroups.some((g) => g.id === groupA), 'groupA missing from currentGroups');
  assert.ok(Array.isArray(eduWidget.data.pendingReviews), 'pendingReviews missing');
  assert.ok(Array.isArray(eduWidget.data.pendingManualGrading), 'pendingManualGrading missing');
  assert.ok(
    dashRes.json.data.quickActions.every((qa) => !qa.route?.startsWith('/education/schedule')),
    'Broken /education/schedule route still present in quickActions',
  );

  // Create a published assessment via API so we can assign it in the UI
  const createAsm = await apiReq(apiCtx, '/api/education/assessments', {
    method: 'POST',
    token: teacher.token,
    orgId: orgA,
    jsonBody: {
      title: 'P16A Teacher Quiz',
      description: 'Isolated acceptance quiz',
      passingScore: 60,
      questions: [
        {
          id: 'q1',
          type: 'single_choice',
          prompt: '2 + 2 = ?',
          points: 10,
          options: [
            { id: 'o1', label: '4' },
            { id: 'o2', label: '5' },
          ],
          answerKey: 'o1',
        },
      ],
    },
  });
  assert.equal(createAsm.status, 201, `Create assessment failed: ${createAsm.text}`);
  const assessmentId = createAsm.json.data.id;
  const pubAsm = await apiReq(apiCtx, `/api/education/assessments/${assessmentId}/publish`, {
    method: 'POST',
    token: teacher.token,
    orgId: orgA,
    jsonBody: {},
  });
  assert.equal(pubAsm.status, 200, `Publish assessment failed: ${pubAsm.text}`);

  // 2. Launch browser & login as teacher
  const browser = await launchBrowser();
  const context = await browser.newContext({
    viewport: { width: 1366, height: 840 },
    ignoreHTTPSErrors: true,
  });
  context.setDefaultTimeout(25_000);
  await context.addInitScript(() => {
    localStorage.setItem('platform.locale', 'ru');
    localStorage.setItem('platform.theme', 'light');
  });
  const page = await context.newPage();

  const consoleErrors = [];
  const serverErrors = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') consoleErrors.push(msg.text());
  });
  page.on('pageerror', (err) => {
    consoleErrors.push(`pageerror: ${err.message}`);
  });
  page.on('response', (res) => {
    if (res.status() >= 500) serverErrors.push(`${res.status()} ${res.url()}`);
  });

  try {

  // Step 1: Login -> /today
  await page.goto(`${FRONTEND_URL}/admin/login`, { waitUntil: 'domcontentloaded' });
  await page.locator('#admin-identifier').fill(teacher.email);
  await page.locator('#admin-password').fill(teacher.password);
  await page.locator('form button[type="submit"]').click();
  await page.waitForURL((u) => !u.pathname.startsWith('/admin/login'), { timeout: 25_000 });
  await navigateClientSide(page, '/today');
  await page.locator('[id="education-organization"]').waitFor({ state: 'visible', timeout: 15_000 }).catch(() => {});
  if (await page.locator('[id="education-organization"]').count()) {
    await selectDropdown(page, 'education-organization', orgA);
    await page.waitForTimeout(800);
  }
  await page.waitForSelector('.edu-today-columns', { timeout: 20_000 });
  await page.waitForTimeout(400);
  const todayText = await page.locator('.edu-today').innerText();
  assert.ok(todayText.includes(groupAName), 'Dashboard does not show current group');
  await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'p16a-teacher-today.png'), fullPage: true });
  console.log('✓ Step 1: Today Dashboard verified');

  // Step 2: Group
  await navigateClientSide(page, `/groups/${groupA}`);
  await page.locator('h1', { hasText: groupAName }).waitFor({ timeout: 15_000 });
  await page.waitForTimeout(400);
  const groupText = await page.locator('main, #education-main').first().innerText();
  assert.ok(groupText.includes(groupAName), 'Group page missing group name');
  await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'p16a-teacher-group.png'), fullPage: true });
  console.log('✓ Step 2: Group page verified');

  // Step 3: Create Lesson (started in the past so attendance & completion work immediately)
  const now = new Date();
  const startIso = new Date(now.getTime() - 3600_000).toISOString();
  const endIso = new Date(now.getTime() - 600_000).toISOString();
  const createLesson = await apiReq(apiCtx, '/api/education/lessons', {
    method: 'POST',
    token: teacher.token,
    orgId: orgA,
    jsonBody: {
      title: 'P16A Acceptance Lesson',
      groupId: groupA,
      startsAt: startIso,
      endsAt: endIso,
      location: 'Room 101',
    },
  });
  assert.equal(createLesson.status, 201, `Create lesson failed: ${createLesson.text}`);
  const lessonId = createLesson.json.data.id;

  // Step 4: Attendance on /lessons/:id/participants
  await navigateClientSide(page, `/lessons/${lessonId}/participants`);
  await page.waitForSelector(`[id="education-attendance-${student1.studentId}"]`, { timeout: 15_000 });
  await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'p16a-teacher-attendance.png'), fullPage: true });
  // Mark present via API or dropdown
  const markAtt = await apiReq(apiCtx, `/api/education/lessons/${lessonId}/participants/${student1.studentId}`, {
    method: 'PUT',
    token: teacher.token,
    orgId: orgA,
    jsonBody: { status: 'present' },
  });
  assert.equal(markAtt.status, 200, `Mark attendance failed: ${markAtt.text}`);
  console.log('✓ Step 3-4: Lesson & Attendance verified');

  // Step 5: Complete lesson via UI, save grade & comment, verify Journal
  await page.getByRole('button', { name: /Завершить занятие/i }).click();
  await page.getByRole('dialog').getByRole('button', { name: /Завершить занятие/i }).click();
  await page.locator('[data-lesson-status]', { hasText: /Завершено|Проведено|Completed/i }).waitFor({ timeout: 15_000 });

  await navigateClientSide(page, `/lessons/${lessonId}/results`);
  await page.waitForSelector('#education-report-topic', { timeout: 15_000 });
  await page.locator('#education-report-topic').fill('Linear Equations');
  await page.locator('form textarea').first().fill('Completed practice tasks.');
  await page.locator('form button[type="submit"]').click();
  await page.waitForTimeout(500);

  const saveGrade = await apiReq(apiCtx, `/api/education/lessons/${lessonId}/results/${student1.studentId}`, {
    method: 'PUT',
    token: teacher.token,
    orgId: orgA,
    jsonBody: { status: 'mastered', comment: 'Great work in class!' },
  });
  assert.equal(saveGrade.status, 200, `Save grade failed: ${saveGrade.text}`);

  await navigateClientSide(page, `/groups/${groupA}/journal`);
  await page.waitForSelector('.edu-journal__entry, .edu-journal__matrix', { timeout: 15_000 });
  await page.waitForTimeout(600);
  await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'p16a-teacher-journal.png'), fullPage: true });
  console.log('✓ Step 5: Grade & Journal verified');

  // Step 6: Homework create & view
  const createHw = await apiReq(apiCtx, '/api/education/homework', {
    method: 'POST',
    token: teacher.token,
    orgId: orgA,
    jsonBody: {
      title: 'P16A Homework Task',
      description: 'Solve 5 equations',
      instructions: 'Upload your solution or write in text.',
      type: 'text',
      groupId: groupA,
      studentIds: [student1.studentId],
      lessonId,
      allowLate: true,
    },
  });
  assert.equal(createHw.status, 201, `Create homework failed: ${createHw.text}`);
  const hwId = createHw.json.data.assignment.id;
  await navigateClientSide(page, `/homework/${hwId}`);
  await page.locator('h1', { hasText: 'P16A Homework Task' }).waitFor({ timeout: 15_000 });
  await page.waitForTimeout(400);
  await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'p16a-teacher-homework.png'), fullPage: true });
  console.log('✓ Step 6: Homework verified');

  // Step 7: Assign Assessment via Activity Engine
  await navigateClientSide(page, '/assessments');
  await page.waitForSelector('h1', { timeout: 15_000 });
  await page.waitForTimeout(600);
  const assignRes = await apiReq(apiCtx, `/api/activity/items/${assessmentId}/assignments`, {
    method: 'POST',
    token: teacher.token,
    orgId: orgA,
    jsonBody: { targetType: 'group', targetId: groupA },
  });
  assert.ok(assignRes.status === 200 || assignRes.status === 201, `Assign assessment failed: ${assignRes.text}`);
  console.log('✓ Step 7: Assessment assignment verified');

  // Step 8: View Student Card & Results
  await navigateClientSide(page, `/students/${student1.studentId}`);
  await page.locator('main, #education-main').first().getByText('Great work in class!').first().waitFor({ timeout: 15_000 });
  await page.waitForTimeout(500);
  const studentText = await page.locator('main, #education-main').first().innerText();
  assert.ok(studentText.includes('Great work in class!'), 'Student card missing teacher comment');
  await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'p16a-teacher-student.png'), fullPage: true });
  console.log('✓ Step 8: Student card & results verified');

  // Step 9: Message Student
  await navigateClientSide(page, '/messages');
  await page.waitForSelector('.edu-messages-page', { timeout: 15_000 });
  await page.waitForTimeout(800);
  await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'p16a-teacher-messages.png'), fullPage: true });
  console.log('✓ Step 9: Messages verified');

  assert.equal(serverErrors.length, 0, `Server 5xx errors: ${serverErrors.join('; ')}`);
  console.log(`Console errors count: ${consoleErrors.length}`);
  if (consoleErrors.length > 0) {
    console.log('Console errors:', consoleErrors);
  }
  } catch (e) {
    console.error('Captured consoleErrors:', consoleErrors);
    console.error('Captured serverErrors:', serverErrors);
    console.error('Current body:', (await page.locator('body').innerText().catch(() => '')).slice(0, 500));
    throw e;
  } finally {
    await browser.close();
    await apiCtx.dispose();
  }
  console.log('ALL PLATFORM_16_A ACCEPTANCE CHECKS PASSED!');
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
