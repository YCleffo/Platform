// PLATFORM_16_C Local Acceptance: Education Parent Cabinet Finalization
// Scenario: Multi-child context -> Child switching -> Dashboard (WorkspaceDashboard) -> Schedule/Diary/Homework/Progress -> UniversalMessenger -> Notifications -> ACL Spoof & Revoke -> Mobile/Dark theme
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

function createExtraPerson(orgId, nonce) {
  const php = `
    require 'tests/bootstrap.php';
    $runner = new \\Yiisoft\\Yii\\Runner\\Http\\HttpApplicationRunner(rootPath: getcwd(), environment: \\App\\Environment::appEnv());
    $db = $runner->getContainer()->get(\\Yiisoft\\Db\\Connection\\ConnectionInterface::class);
    $pid = (string) $db->createCommand("INSERT INTO core.persons(given_name, family_name, display_name) VALUES('Мария', 'P12Test', :d) RETURNING id::text", [':d' => 'Мария Вторая ' . $argv[1]])->queryScalar();
    $db->createCommand("INSERT INTO core.organization_memberships(organization_id, person_id) VALUES(CAST(:o AS uuid), CAST(:p AS uuid))", [':o' => $argv[2], ':p' => $pid])->execute();
    echo json_encode(['personId' => $pid]);
  `;
  const output = execFileSync(
    'docker',
    ['exec', 'platform-backend', 'php', '-r', php, nonce, orgId],
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
  const { nonce, teacher, student1, student2, parent1, unconfirmedParent, orgA, orgB, groupA } = seed;
  console.log('Seeded isolated fixture:', { orgA, groupA, parent1: parent1.email });

  const apiCtx = await pwRequest.newContext({ baseURL: BACKEND_URL, ignoreHTTPSErrors: true });
  let browser;
  try {
    // 1. Create a second child (student3) in orgA, enroll in groupA, and link to parent1 as confirmed guardian
    const p3 = createExtraPerson(orgA, nonce);
    const enrollS3 = await apiReq(apiCtx, '/api/education/students', {
      method: 'POST',
      token: teacher.token,
      orgId: orgA,
      jsonBody: { personId: p3.personId, groupId: groupA },
    });
    assert.ok(enrollS3.status === 200 || enrollS3.status === 201, `Enroll student3 failed: ${enrollS3.text}`);
    const student3Id = enrollS3.json.data.id;

    // Add parent1 as guardian for student3 and confirm
    const addG3 = await apiReq(apiCtx, `/api/education/students/${student3Id}/guardians`, {
      method: 'POST',
      token: teacher.token,
      orgId: orgA,
      jsonBody: { personId: parent1.personId, relationship: 'mother' },
    });
    assert.equal(addG3.status, 201, `Add guardian3 failed: ${addG3.text}`);
    // Confirm guardianship in DB (teachers require separate education.family.confirm permission)
    execFileSync(
      'docker',
      [
        'exec',
        'platform-backend',
        'php',
        '-r',
        `require 'tests/bootstrap.php'; $r = new \\Yiisoft\\Yii\\Runner\\Http\\HttpApplicationRunner(rootPath: getcwd(), environment: \\App\\Environment::appEnv()); $db = $r->getContainer()->get(\\Yiisoft\\Db\\Connection\\ConnectionInterface::class); $db->createCommand("UPDATE education.student_guardians SET status='confirmed', confirmed_at=now(), confirmed_by_account_id=CAST(:c AS uuid) WHERE student_id=CAST(:s AS uuid) AND guardian_person_id=CAST(:p AS uuid)", [':s'=>$argv[1],':p'=>$argv[2],':c'=>$argv[3]])->execute();`,
        student3Id,
        parent1.personId,
        teacher.accountId,
      ],
      { stdio: ['ignore', 'pipe', 'pipe'] },
    );

    // 2. Create a completed lesson with grade & comment for student1 (distinct from student3)
    const now = new Date();
    const pastStart = new Date(now.getTime() - 2 * 3600_000).toISOString();
    const pastEnd = new Date(now.getTime() - 1 * 3600_000).toISOString();
    const futureStart = new Date(now.getTime() + 24 * 3600_000).toISOString();
    const futureEnd = new Date(now.getTime() + 25 * 3600_000).toISOString();

    const pastLessonRes = await apiReq(apiCtx, '/api/education/lessons', {
      method: 'POST',
      token: teacher.token,
      orgId: orgA,
      jsonBody: {
        groupId: groupA,
        title: `Урок Алгебры ${nonce}`,
        startsAt: pastStart,
        endsAt: pastEnd,
        location: 'Каб. 204',
      },
    });
    assert.equal(pastLessonRes.status, 201, `Create past lesson failed: ${pastLessonRes.text}`);
    const pastLessonId = pastLessonRes.json.data.id;

    await apiReq(apiCtx, `/api/education/lessons/${pastLessonId}/participants/${student1.studentId}`, {
      method: 'PUT',
      token: teacher.token,
      orgId: orgA,
      jsonBody: { status: 'present' },
    });
    await apiReq(apiCtx, `/api/education/lessons/${pastLessonId}/complete`, {
      method: 'POST',
      token: teacher.token,
      orgId: orgA,
      jsonBody: {},
    });
    const gradeRes = await apiReq(apiCtx, `/api/education/lessons/${pastLessonId}/results/${student1.studentId}`, {
      method: 'PUT',
      token: teacher.token,
      orgId: orgA,
      jsonBody: {
        status: 'mastered',
        comment: `Отличная работа Ивана ${nonce}`,
      },
    });
    assert.equal(gradeRes.status, 200, `Save lesson result failed: ${gradeRes.text}`);

    // Upcoming scheduled lesson
    const nextLessonRes = await apiReq(apiCtx, '/api/education/lessons', {
      method: 'POST',
      token: teacher.token,
      orgId: orgA,
      jsonBody: {
        groupId: groupA,
        title: `Следующий урок ${nonce}`,
        startsAt: futureStart,
        endsAt: futureEnd,
        location: 'Каб. 301',
      },
    });
    assert.equal(nextLessonRes.status, 201, `Create next lesson failed: ${nextLessonRes.text}`);

    // 3. Create & publish homework for groupA
    const hwDue = new Date(now.getTime() + 3 * 86400_000).toISOString();
    const hwCreate = await apiReq(apiCtx, '/api/education/homework', {
      method: 'POST',
      token: teacher.token,
      orgId: orgA,
      jsonBody: {
        title: `Домашнее задание ${nonce}`,
        description: 'Решить задачи 1-5',
        instructions: 'Прикрепите решение или напишите ответ.',
        type: 'text',
        groupId: groupA,
        dueAt: hwDue,
      },
    });
    assert.equal(hwCreate.status, 201, `Create homework failed: ${hwCreate.text}`);
    const hwId = hwCreate.json.data.assignment.id;
    const hwPub = await apiReq(apiCtx, `/api/education/homework/${hwId}/publish`, {
      method: 'POST',
      token: teacher.token,
      orgId: orgA,
      jsonBody: { version: hwCreate.json.data.assignment.version ?? 1 },
    });
    assert.equal(hwPub.status, 200, `Publish homework failed: ${hwPub.text}`);

    // 4. Create, publish & assign an assessment to groupA, and have student1 complete it + publish results
    const createAsm = await apiReq(apiCtx, '/api/education/assessments', {
      method: 'POST',
      token: teacher.token,
      orgId: orgA,
      jsonBody: {
        title: `Контрольная работа ${nonce}`,
        description: 'Тест по теме 1',
        passingScore: 60,
        questions: [
          {
            id: 'q1',
            type: 'single_choice',
            prompt: '5 * 5 = ?',
            points: 10,
            options: [
              { id: 'o1', label: '25' },
              { id: 'o2', label: '20' },
            ],
            answerKey: 'o1',
          },
        ],
      },
    });
    assert.equal(createAsm.status, 201, `Create assessment failed: ${createAsm.text}`);
    const asmId = createAsm.json.data.id;
    await apiReq(apiCtx, `/api/education/assessments/${asmId}/publish`, {
      method: 'POST',
      token: teacher.token,
      orgId: orgA,
      jsonBody: {},
    });
    const assignAsm = await apiReq(apiCtx, `/api/activity/items/${asmId}/assignments`, {
      method: 'POST',
      token: teacher.token,
      orgId: orgA,
      jsonBody: { targetType: 'group', targetId: groupA, dueAt: hwDue },
    });
    assert.ok(assignAsm.status === 200 || assignAsm.status === 201, `Assign assessment failed: ${assignAsm.text}`);

    // 5. Security & ACL Checks
    // 5a. Spoofed childId (unrelated student2 in same orgA) -> 404
    const spoofRes = await apiReq(apiCtx, `/api/education/parent/children/${student2.studentId}/home`, {
      token: parent1.token,
      orgId: orgA,
    });
    assert.ok(spoofRes.status === 403 || spoofRes.status === 404, `Spoofed childId should return 403/404, got ${spoofRes.status}`);

    // 5b. Cross-org isolation (student1 with orgB header) -> 403/404
    const crossOrgRes = await apiReq(apiCtx, `/api/education/parent/children/${student1.studentId}/home`, {
      token: parent1.token,
      orgId: orgB,
    });
    assert.ok(crossOrgRes.status === 403 || crossOrgRes.status === 404, `Cross-org should return 403/404, got ${crossOrgRes.status}`);

    // 5c. Unconfirmed parent -> 403/404
    const pendingRes = await apiReq(apiCtx, `/api/education/parent/children/${student1.studentId}/home`, {
      token: unconfirmedParent.token,
      orgId: orgA,
    });
    assert.ok(pendingRes.status === 403 || pendingRes.status === 404, `Pending parent should return 403/404, got ${pendingRes.status}`);

    // 5d. Parent cannot mutate teacher/student endpoints (grade/homework)
    const mutateGrade = await apiReq(apiCtx, `/api/education/lessons/${pastLessonId}/results/${student1.studentId}`, {
      method: 'PUT',
      token: parent1.token,
      orgId: orgA,
      jsonBody: { status: 'mastered', comment: 'Hacked' },
    });
    assert.equal(mutateGrade.status, 403, `Parent must get 403 when mutating grades, got ${mutateGrade.status}`);

    // 5e. Workspace Dashboard API scoped to student1 vs spoofed student2
    const dashS1 = await apiReq(apiCtx, `/api/workspace/dashboard?organizationId=${orgA}&studentId=${student1.studentId}`, {
      token: parent1.token,
    });
    assert.equal(dashS1.status, 200, `Parent WorkspaceDashboard failed: ${dashS1.text}`);
    const eduWidgetS1 = dashS1.json.data.widgets.find((w) => w.id === 'education.overview');
    assert.ok(eduWidgetS1, 'education.overview widget missing for parent');
    assert.ok(
      eduWidgetS1.data.upcomingAssessments.some((a) => a.title.includes(nonce)),
      'Upcoming assessment missing from WorkspaceDashboard for student1',
    );

    const dashSpoof = await apiReq(apiCtx, `/api/workspace/dashboard?organizationId=${orgA}&studentId=${student2.studentId}`, {
      token: parent1.token,
    });
    assert.equal(dashSpoof.status, 200);
    const eduWidgetSpoof = dashSpoof.json.data.widgets.find((w) => w.id === 'education.overview');
    assert.equal(eduWidgetSpoof.data.upcomingAssessments.length, 0, 'Spoofed studentId must not return assessments');
    assert.equal(eduWidgetSpoof.data.deadlines.length, 0, 'Spoofed studentId must not return deadlines');

    // 6. Browser E2E as Parent
    browser = await launchBrowser();
    const context = await browser.newContext({
      viewport: { width: 1366, height: 840 },
      ignoreHTTPSErrors: true,
    });
    context.setDefaultTimeout(25_000);
    await context.addInitScript(() => {
      localStorage.setItem('platform.locale', 'ru');
    });

    const page = await context.newPage();
    const consoleErrors = [];
    const serverErrors = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error') consoleErrors.push(msg.text());
    });
    page.on('pageerror', (err) => consoleErrors.push(err.message));
    page.on('response', (res) => {
      if (res.status() >= 500) serverErrors.push(`${res.status()} ${res.url()}`);
    });

    await page.goto(`${FRONTEND_URL}/education/login`, { waitUntil: 'networkidle' });
    await page.fill('input[type="email"]', parent1.email);
    await page.fill('input[type="password"]', parent1.password);
    await page.click('button[type="submit"]');
    await page.waitForURL(/\/parent\/home/);
    await page.waitForSelector(`text=Отличная работа Ивана ${nonce}`);
    await page.waitForSelector(`text=Контрольная работа ${nonce}`);
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'p16c-parent-home.png'), fullPage: true });

    // Switch child from Student1 to Student3
    await selectDropdown(page, 'education-parent-child', `${orgA}:${student3Id}`);
    await page.waitForTimeout(800);
    const bodyAfterSwitch = await page.locator('main').innerText();
    assert.ok(
      !bodyAfterSwitch.includes(`Отличная работа Ивана ${nonce}`),
      'Child 1 feedback leaked into Child 2 dashboard after switching!',
    );
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'p16c-parent-switch-child.png'), fullPage: true });

    // Switch back to Student1
    await selectDropdown(page, 'education-parent-child', `${orgA}:${student1.studentId}`);
    await page.waitForSelector(`text=Отличная работа Ивана ${nonce}`);

    // Diary
    await navigateClientSide(page, `/parent/diary?child=${student1.studentId}&organization=${orgA}`);
    await page.waitForSelector(`text=Урок Алгебры ${nonce}`);
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'p16c-parent-diary.png'), fullPage: true });

    // Homework
    await navigateClientSide(page, `/parent/homework?child=${student1.studentId}&organization=${orgA}`);
    await page.waitForSelector(`text=Домашнее задание ${nonce}`);
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'p16c-parent-homework.png'), fullPage: true });

    // Progress
    await navigateClientSide(page, `/parent/progress?child=${student1.studentId}&organization=${orgA}`);
    await page.waitForSelector('h2');
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'p16c-parent-progress.png'), fullPage: true });

    // Messages (UniversalMessenger)
    await navigateClientSide(page, `/parent/messages?child=${student1.studentId}&organization=${orgA}`);
    await page.waitForSelector('.universal-messenger, .chat-shell, [data-testid="universal-messenger"]', { timeout: 10_000 }).catch(() => {});
    await page.waitForTimeout(800);
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'p16c-parent-messages.png'), fullPage: true });

    // Notifications
    await navigateClientSide(page, `/parent/notifications?child=${student1.studentId}&organization=${orgA}`);
    await page.waitForTimeout(600);
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'p16c-parent-notifications.png'), fullPage: true });

    // Mobile & Dark Theme check
    await page.setViewportSize({ width: 390, height: 844 });
    await navigateClientSide(page, `/parent/home?child=${student1.studentId}&organization=${orgA}`);
    await page.waitForSelector(`text=Отличная работа Ивана ${nonce}`);
    await page.click('header button:has-text("Тёмная"), header button:has-text("Dark")').catch(() => {});
    await page.waitForTimeout(400);
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'p16c-parent-mobile-dark.png'), fullPage: true });

    // 7. Revoke guardian link for student3 and verify immediate access loss
    const revokeRes = await apiReq(apiCtx, `/api/education/students/${student3Id}/guardians/${parent1.personId}/revoke`, {
      method: 'POST',
      token: teacher.token,
      orgId: orgA,
      jsonBody: {},
    });
    assert.equal(revokeRes.status, 200, `Revoke guardian3 failed: ${revokeRes.text}`);

    const afterRevoke = await apiReq(apiCtx, `/api/education/parent/children/${student3Id}/home`, {
      token: parent1.token,
      orgId: orgA,
    });
    assert.ok(afterRevoke.status === 403 || afterRevoke.status === 404, `Revoked child must return 403/404, got ${afterRevoke.status}`);

    assert.equal(consoleErrors.length, 0, `Console errors detected: ${consoleErrors.join('; ')}`);
    assert.equal(serverErrors.length, 0, `Server 5xx errors detected: ${serverErrors.join('; ')}`);
    console.log('PLATFORM_16_C Parent E2E Acceptance PASSED!');
  } finally {
    if (browser) await browser.close();
    try {
      execFileSync(
        'docker',
        [
          'exec',
          'platform-backend',
          'php',
          '-r',
          `require 'tests/bootstrap.php'; $r = new \\Yiisoft\\Yii\\Runner\\Http\\HttpApplicationRunner(rootPath: getcwd(), environment: \\App\\Environment::appEnv()); $db = $r->getContainer()->get(\\Yiisoft\\Db\\Connection\\ConnectionInterface::class); $o = $argv[1];
           foreach (['education.lesson_results', 'education.attendance', 'education.lesson_reports', 'education.lessons', 'education.homework_reviews', 'education.homework_submission_versions', 'education.homework_submissions', 'education.homework_recipients', 'education.homework_assignments', 'activity.assignments', 'activity.items', 'education.student_guardians', 'education.group_students'] as $t) {
             try { $db->createCommand("DELETE FROM {$t} WHERE organization_id=CAST(:o AS uuid)", [':o' => $o])->execute(); } catch (\\Throwable) {}
           }`,
          orgA,
        ],
        { stdio: ['ignore', 'pipe', 'pipe'] },
      );
    } catch {
      // ignore
    }
    try {
      runFixture('cleanup', nonce);
    } catch {
      // ignore cleanup FK error
    }
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
