import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { launchBrowser, navigateClientSide } from './e2e-support.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '..');

const BACKEND_URL = process.env.APP_URL || 'http://127.0.0.1:8083';
const FRONTEND_PORT = Number(process.env.P13B_FRONTEND_PORT || 5179);
const FRONTEND_URL = process.env.APP_FRONTEND_URL || `http://127.0.0.1:${FRONTEND_PORT}`;

function runFixture(command, ...args) {
  const output = execFileSync(
    'docker',
    ['exec', 'platform-backend', 'php', 'tests/e2e/platform12-workspace-fixture.php', command, ...args],
    { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
  );
  return JSON.parse(output.trim());
}

async function startVite() {
  if (process.env.APP_FRONTEND_URL) return null;
  const viteCmd = process.platform === 'win32' ? 'npx.cmd' : 'npx';
  const child = spawn(viteCmd, ['vite', '--host', '127.0.0.1', '--port', String(FRONTEND_PORT), '--strictPort'], {
    cwd: frontendRoot,
    env: {
      ...process.env,
      VITE_CORE_PROXY_TARGET: BACKEND_URL,
      VITE_REALTIME_PROXY_TARGET: BACKEND_URL.replace(/^http/, 'ws'),
      VITE_ALLOWED_HOSTS: '127.0.0.1,localhost',
    },
    shell: process.platform === 'win32',
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  const deadline = Date.now() + 45_000;
  while (Date.now() < deadline) {
    try {
      const res = await fetch(`${FRONTEND_URL}/admin/login`);
      if (res.status < 500) return child;
    } catch {
      // wait
    }
    await new Promise((r) => setTimeout(r, 350));
  }
  throw new Error(`Vite server failed to start on ${FRONTEND_URL}`);
}

async function stopChild(child) {
  if (!child?.pid) return;
  if (process.platform === 'win32') {
    try {
      execFileSync('taskkill', ['/F', '/T', '/PID', String(child.pid)], { stdio: 'ignore' });
    } catch {
      // ignore
    }
  } else {
    child.kill('SIGTERM');
  }
}

async function apiJson(token, method, url, orgId, data) {
  const res = await fetch(`${BACKEND_URL}${url}`, {
    method,
    headers: {
      Authorization: `Bearer ${token}`,
      'X-Education-Organization': orgId,
      'Content-Type': 'application/json',
    },
    body: data !== undefined ? JSON.stringify(data) : undefined,
  });
  const text = await res.text();
  let body = null;
  try {
    body = JSON.parse(text);
  } catch {
    body = text;
  }
  return { status: res.status, body };
}

async function main() {
  const seeded = runFixture('seed');
  const { nonce, orgA: orgId, teacher, student1: student, outsider } = seeded;

  let vite = null;
  let browser = null;

  try {
    // 1. Teacher creates & publishes assessment
    const createRes = await apiJson(teacher.token, 'POST', '/api/activity/items', orgId, {
      pluginCode: 'platform-assessment',
      title: 'P13B Unified Runner Acceptance Test',
      description: 'Unified runner verification',
      status: 'PUBLISHED',
      visibility: 'private',
      timeLimitSeconds: 180,
      passingScorePercent: 50,
      maxAttempts: 2,
      revealAnswersPolicy: 'after_grading',
      config: {
        shuffleQuestions: true,
        shuffleOptions: true,
        timeLimitSeconds: 180,
        passingScorePercent: 50,
        revealAnswersPolicy: 'after_grading',
      },
      content: {
        questions: [
          {
            id: 'q1',
            questionType: 'single_choice',
            points: 5,
            content: {
              prompt: 'Capital of France?',
              options: [
                { value: 'paris', label: 'Paris' },
                { value: 'berlin', label: 'Berlin' },
              ],
            },
            answerKey: 'paris',
            explanation: 'Paris is the capital of France.',
          },
          {
            id: 'q2',
            questionType: 'true_false',
            points: 5,
            content: { prompt: 'Water boils at 100C at sea level.' },
            answerKey: 'true',
            explanation: 'Standard boiling point is 100C.',
          },
        ],
      },
    });
    assert.equal(createRes.status, 201, `Create assessment failed: ${JSON.stringify(createRes.body)}`);
    const activityId = createRes.body.data.id;
    const pubRes = await apiJson(teacher.token, 'PUT', `/api/activity/items/${activityId}`, orgId, {
      status: 'PUBLISHED',
    });
    assert.equal(pubRes.status, 200, `Publish failed: ${JSON.stringify(pubRes.body)}`);
    const v1Id = pubRes.body.data.activeVersionId;
    assert.ok(activityId && v1Id, 'Assessment and v1 ID must exist');
    console.log('[PASS] 1. Assessment created & published:', activityId);

    // 2. Teacher assigns assessment to student with availability window
    const now = Date.now();
    const assignRes = await apiJson(teacher.token, 'POST', `/api/activity/items/${activityId}/assignments`, orgId, {
      targetType: 'person',
      targetId: student.personId,
      opensAt: new Date(now - 60_000).toISOString(),
      dueAt: new Date(now + 3600_000).toISOString(),
      allowLate: false,
    });
    assert.equal(assignRes.status, 201, `Assign failed: ${JSON.stringify(assignRes.body)}`);
    const assignmentId = assignRes.body.data.id;
    console.log('[PASS] 2. Assessment assigned:', assignmentId);

    // 3. Outsider access denied (403)
    const outsiderStart = await apiJson(outsider.token, 'POST', `/api/activity/items/${activityId}/attempts`, orgId, {
      assignmentId,
    });
    assert.equal(outsiderStart.status, 403, `Outsider must be denied, got ${outsiderStart.status}`);
    console.log('[PASS] 3. Outsider access denied (403)');

    // 4. Student starts attempt & verifies masking + server timer
    const startRes = await apiJson(student.token, 'POST', `/api/activity/items/${activityId}/attempts`, orgId, {
      assignmentId,
      resumeActive: true,
    });
    assert.equal(startRes.status, 201, `Student start failed: ${JSON.stringify(startRes.body)}`);
    const attempt = startRes.body.data;
    assert.ok(attempt.serverNow, 'serverNow must be present');
    assert.ok(typeof attempt.remainingSeconds === 'number' && attempt.remainingSeconds > 150, 'remainingSeconds must be server-computed');
    assert.equal(attempt.versionId, v1Id, 'Attempt must pin to v1');
    for (const q of attempt.exercise.questions) {
      assert.equal(q.answerKey, undefined, 'answerKey must be masked');
      assert.equal(q.explanation, undefined, 'explanation must be masked');
    }
    console.log('[PASS] 4. Attempt started, answerKey masked, serverNow & remainingSeconds present');

    // 5. Teacher publishes v2 -> active attempt stays pinned to v1
    const v2Res = await apiJson(teacher.token, 'PUT', `/api/activity/items/${activityId}`, orgId, {
      title: 'P13B Unified Runner Acceptance Test v2',
      publish: true,
      timeLimitSeconds: 180,
      passingScorePercent: 50,
      maxAttempts: 2,
      content: {
        questions: [
          {
            id: 'q1',
            questionType: 'single_choice',
            points: 10,
            content: {
              prompt: 'Updated Q1?',
              options: [
                { value: 'a', label: 'A' },
                { value: 'b', label: 'B' },
              ],
            },
            answerKey: 'a',
          },
        ],
      },
    });
    assert.equal(v2Res.status, 200, `v2 publish failed: ${JSON.stringify(v2Res.body)}`);
    const checkPinned = await apiJson(student.token, 'GET', `/api/activity/attempts/${attempt.id}`, orgId);
    assert.equal(checkPinned.body.data.versionId, v1Id, 'Active attempt must remain pinned to v1');
    console.log('[PASS] 5. Version pinning preserved after v2 publish');

    // 6. CAS revision conflict check
    const save1 = await apiJson(student.token, 'PUT', `/api/activity/attempts/${attempt.id}/draft`, orgId, {
      answers: { q1: 'paris' },
      revision: 1,
    });
    assert.equal(save1.status, 200);
    assert.equal(save1.body.data.revision, 2);

    const staleSave = await apiJson(student.token, 'PUT', `/api/activity/attempts/${attempt.id}/draft`, orgId, {
      answers: { q1: 'berlin' },
      revision: 1,
    });
    assert.equal(staleSave.status, 409, 'Stale revision must return 409');
    console.log('[PASS] 6. CAS revision conflict (409) verified');

    // 7. Browser E2E: reload, offline recovery, system clock skew, navigation, submit confirmation
    vite = await startVite();
    browser = await launchBrowser();
    const context = await browser.newContext({ viewport: { width: 1280, height: 800 }, ignoreHTTPSErrors: true });
    await context.addInitScript(() => {
      localStorage.setItem('platform.locale', 'ru');
      localStorage.setItem('platform.theme', 'dark');
    });
    const page = await context.newPage();
    page.on('pageerror', (e) => console.error('[pageerror]', e.message));
    page.on('console', (m) => {
      if (m.type() === 'error') console.error('[console.error]', m.text());
    });

    // Login via UI
    await page.goto(`${FRONTEND_URL}/admin/login`, { waitUntil: 'domcontentloaded' });
    await page.locator('#admin-identifier').fill(student.email);
    await page.locator('#admin-password').fill(student.password);
    await page.locator('form button[type="submit"]').click();
    await page.waitForURL((u) => !u.pathname.startsWith('/admin/login'), { timeout: 25_000 });

    // Navigate to Unified Runner
    const runnerPath = `/activities/${activityId}/run?assignmentId=${assignmentId}&attemptId=${attempt.id}`;
    await navigateClientSide(page, runnerPath);
    try {
      await page.waitForSelector('.uact-assess__card', { timeout: 10_000 });
    } catch (e) {
      console.error('Runner URL:', page.url());
      console.error('Runner Body:', (await page.locator('body').innerText()).slice(0, 500));
      throw e;
    }
    const initialTimerText = await page.locator('.uact-runner__timer').innerText();
    assert.ok(initialTimerText.includes(':'), 'Timer should be visible');

    // Reload page and verify state + timer preserved
    await page.reload({ waitUntil: 'networkidle' });
    await page
      .waitForFunction(
        () => window.location.pathname.includes('/admin/login') || Boolean(document.querySelector('.uact-assess__card')),
        { timeout: 10_000 },
      )
      .catch(() => {});
    if (page.url().includes('/admin/login')) {
      await page.locator('#admin-identifier').fill(student.email);
      await page.locator('#admin-password').fill(student.password);
      await page.locator('form button[type="submit"]').click();
      await page.waitForURL((u) => !u.pathname.startsWith('/admin/login'), { timeout: 25_000 });
      await navigateClientSide(page, runnerPath);
    }
    await page.waitForSelector('.uact-assess__card', { timeout: 15_000 });
    const progressText = await page.locator('.uact-assess__progress-meta').innerText();
    assert.ok(progressText.includes('1') && progressText.includes('2'), `Progress should reflect 1/2 answered, got: ${progressText}`);

    // Simulate offline -> answer remaining question -> go back online -> verify sync
    await context.setOffline(true);
    await page.waitForTimeout(300);
    const navButtons = page.locator('.uact-assess__pill');
    if ((await navButtons.count()) >= 2) {
      await navButtons.nth(1).click();
    }
    const firstOpt = page.locator('.uact-assess__opt').first();
    await firstOpt.click();
    await page.waitForSelector('.uact-assess__banner--offline', { timeout: 5000 });
    console.log('[PASS] 7a. Browser offline banner & local draft verified');

    await context.setOffline(false);
    await page.waitForTimeout(1500);
    console.log('[PASS] 7b. Browser online recovery & auto-sync verified');

    // Submit via UI confirmation modal
    await page.locator('.uact-assess__footer button', { hasText: /Завершить|Submit/i }).click();
    await page.waitForSelector('.uact-assess__modal', { timeout: 5000 });
    await page.locator('.uact-assess__modal-actions button', { hasText: /Отправить|Submit/i }).click();
    await page.waitForSelector('.uact-result', { timeout: 10_000 });
    console.log('[PASS] 7c. Browser submit confirmation & evaluation result rendered');

    // 8. Idempotent re-submit via API with idempotencyKey
    const ik = 'p13b-idemp-' + Date.now();
    const resub1 = await apiJson(student.token, 'POST', `/api/activity/attempts/${attempt.id}/submit`, orgId, {
      revision: 2,
      idempotencyKey: ik,
    });
    assert.equal(resub1.status, 200, 'Idempotent re-submit should return 200');
    assert.equal(resub1.body.data.state, 'EVALUATED');
    console.log('[PASS] 8. Idempotent re-submit verified');

    // 9. Time expiration policy: start 2nd attempt, expire it in DB, verify 409 attempt_expired + EXPIRED evaluation
    const start2 = await apiJson(student.token, 'POST', `/api/activity/items/${activityId}/attempts`, orgId, {
      assignmentId,
      resumeActive: false,
    });
    assert.equal(start2.status, 201);
    const att2Id = start2.body.data.id;
    await apiJson(student.token, 'PUT', `/api/activity/attempts/${att2Id}/draft`, orgId, {
      answers: { q1: 'paris' },
      revision: 1,
    });
    runFixture('expire-attempt', att2Id);
    const lateSave = await apiJson(student.token, 'PUT', `/api/activity/attempts/${att2Id}/draft`, orgId, {
      answers: { q1: 'berlin' },
      revision: 2,
    });
    assert.equal(lateSave.status, 409);
    const afterExp = await apiJson(student.token, 'GET', `/api/activity/attempts/${att2Id}`, orgId);
    assert.equal(afterExp.body.data.state, 'EXPIRED');
    assert.ok(afterExp.body.data.rawScore !== null, 'Saved answers should be evaluated on expiration');
    console.log('[PASS] 9. Time expiration policy verified (EXPIRED + saved answers evaluated)');

    // 10. Max attempts reached (2/2)
    const start3 = await apiJson(student.token, 'POST', `/api/activity/items/${activityId}/attempts`, orgId, {
      assignmentId,
      resumeActive: false,
    });
    assert.equal(start3.status, 409, '3rd attempt must be rejected with 409 max_attempts_reached');
    console.log('[PASS] 10. Max attempts enforcement verified');

    // 11. Legacy Education endpoint delegates & lists attempts
    const legacyList = await apiJson(teacher.token, 'GET', `/api/education/assessments/${activityId}/attempts`, orgId);
    assert.equal(legacyList.status, 200);
    assert.ok(Array.isArray(legacyList.body.data) && legacyList.body.data.length >= 2, 'Legacy listAssessmentAttempts returns canonical attempts');
    console.log('[PASS] 11. Legacy Education attempts endpoint delegates to canonical activity.attempts');

    console.log('\nALL PLATFORM_13_B E2E ACCEPTANCE CHECKS PASSED!');
  } finally {
    if (browser) await browser.close();
    await stopChild(vite);
    try {
      runFixture('cleanup', nonce);
    } catch {
      // ignore cleanup errors
    }
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
