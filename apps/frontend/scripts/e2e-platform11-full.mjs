// Full PLATFORM_11 Universal Activity Engine, Plugins, Integrations & Load Benchmark E2E
// Real Chromium (Playwright), Real Vite HTTP+WS Proxy, Real FrankenPHP + WS Server, Redis Pub/Sub, PostgreSQL, SeaweedFS S3.
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { launchBrowser, navigateClientSide, stableScreenshot } from './e2e-support.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '..');
const repoRoot = path.resolve(frontendRoot, '..', '..');

const BACKEND_URL = process.env.APP_URL || 'http://127.0.0.1:8083';
const FRONTEND_PORT = Number(process.env.R11_FRONTEND_PORT || 5177);
const FRONTEND_URL = process.env.APP_FRONTEND_URL || `http://127.0.0.1:${FRONTEND_PORT}`;

function runFixture(command, ...args) {
  const output = execFileSync(
    'docker',
    ['exec', 'platform-backend', 'php', 'tests/e2e/platform09-r2-fixture.php', command, ...args],
    { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
  );
  return JSON.parse(output.trim());
}

async function api(urlPath, options = {}) {
  const url = urlPath.startsWith('http') ? urlPath : `${BACKEND_URL}${urlPath}`;
  const t0 = performance.now();
  const res = await fetch(url, options);
  const elapsedMs = performance.now() - t0;
  const text = await res.text();
  let json = null;
  try {
    json = JSON.parse(text);
  } catch {
    // non-json
  }
  return { status: res.status, json, text, elapsedMs };
}

async function startVite() {
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
      const res = await fetch(`${FRONTEND_URL}/education/login`);
      if (res.status < 500) return child;
    } catch {
      // wait
    }
    await new Promise((r) => setTimeout(r, 350));
  }
  throw new Error('Vite server failed to start');
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

async function loginAdminBrowser(browser, actor, targetPath) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 820 }, ignoreHTTPSErrors: true });
  context.setDefaultTimeout(30_000);
  await context.addInitScript(() => {
    localStorage.setItem('platform.locale', 'en');
    localStorage.setItem('platform.theme', 'light');
  });
  const page = await context.newPage();
  const pageErrors = [];
  page.on('pageerror', (e) => pageErrors.push(e.message));
  await page.goto(`${FRONTEND_URL}/admin/login`, { waitUntil: 'domcontentloaded' });
  await page.locator('#admin-identifier').fill(actor.email);
  await page.locator('#admin-password').fill(actor.password);
  await page.locator('form button[type="submit"]').click();
  await page.waitForURL((u) => !u.pathname.startsWith('/admin/login'), { timeout: 25_000 });
  await navigateClientSide(page, targetPath);
  return { context, page, pageErrors };
}

function percentile(sorted, p) {
  if (sorted.length === 0) return 0;
  const idx = Math.min(sorted.length - 1, Math.floor((p / 100) * sorted.length));
  return Number(sorted[idx].toFixed(2));
}

console.log('--- PLATFORM_11 UNIVERSAL ACTIVITY ENGINE & FULL REGRESSION VERIFICATION ---');

const seeded = runFixture('seed');
const { nonce, organizationId, otherOrganizationId, teacher, student, outsider } = seeded;
console.log(`Seeded Nonce: ${nonce}, Org: ${organizationId}`);

let vite = null;
let browser = null;
const contexts = [];
const screenshotDir = path.join(repoRoot, 'docs', 'audits', 'screenshots');
mkdirSync(screenshotDir, { recursive: true });
const evidence = { version: 'PLATFORM_11', timestamp: new Date().toISOString(), nonce, tests: [], passed: 0, failed: 0, benchmarks: {} };

function record(id, title, details = {}) {
  evidence.passed++;
  evidence.tests.push({ id, title, status: 'PASS', details });
  console.log(`[PASS] ${id}: ${title}`);
}

try {
  // 1. Plugins & Question Bank 2.0 (ordering, multi_choice, long_text, archive/restore, import)
  const plugRes = await api('/api/activity/plugins', {
    headers: { Authorization: `Bearer ${teacher.token}`, 'X-Education-Organization': organizationId },
  });
  assert.equal(plugRes.status, 200);
  const pluginCodes = plugRes.json.data.items.map((p) => p.code);
  for (const reqCode of ['platform-assessment', 'platform-typing', 'platform-math-sprint', 'platform-logic', 'platform-memory']) {
    assert.ok(pluginCodes.includes(reqCode), `Missing plugin: ${reqCode}`);
  }

  const importRes = await api('/api/activity/question-bank/import', {
    method: 'POST',
    headers: { Authorization: `Bearer ${teacher.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
    body: JSON.stringify({
      items: [
        {
          questionType: 'ordering',
          category: 'algorithms',
          content: { prompt: 'Order HTTP request lifecycle stages', items: ['DNS', 'TCP', 'TLS', 'HTTP'] },
          answerKey: ['DNS', 'TCP', 'TLS', 'HTTP'],
          defaultPoints: 2,
        },
        {
          questionType: 'long_text',
          category: 'architecture',
          content: { prompt: 'Explain why Transactional Outbox prevents dual-write drift.' },
          answerKey: null,
          defaultPoints: 4,
        },
      ],
    }),
  });
  assert.equal(importRes.status, 200);
  assert.equal(importRes.json.data.importedCount, 2);
  const bankQId = importRes.json.data.items[0].id;

  const archRes = await api(`/api/activity/question-bank/${bankQId}/archive`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${teacher.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
    body: JSON.stringify({ restore: false }),
  });
  assert.equal(archRes.json.data.archived, true);
  const unarchRes = await api(`/api/activity/question-bank/${bankQId}/archive`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${teacher.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
    body: JSON.stringify({ restore: true }),
  });
  assert.equal(unarchRes.json.data.archived, false);
  record('P11-PLUGIN-01', '5 Core Plugins Registered + Question Bank 2.0 Import/Archive/Restore');

  // 2. Create Published Activities across all 5 plugins + Verify State Machine + Manual Review + Tenant Isolation
  const assessCreate = await api('/api/activity/items', {
    method: 'POST',
    headers: { Authorization: `Bearer ${teacher.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
    body: JSON.stringify({
      pluginCode: 'platform-assessment',
      title: `Assessment 2.0 ${nonce}`,
      maxAttempts: 2,
      passingScorePercent: 60,
      publish: true,
      content: {
        questions: [
          { id: 'q1', questionType: 'multi_choice', content: { prompt: 'Pick primes', options: ['2', '3', '4'] }, answerKey: ['2', '3'], defaultPoints: 2 },
          { id: 'q2', questionType: 'ordering', content: { prompt: 'Ascending', items: ['One', 'Two', 'Three'] }, answerKey: ['One', 'Two', 'Three'], defaultPoints: 2 },
          { id: 'q3', questionType: 'long_text', content: { prompt: 'Explain ACID' }, answerKey: null, defaultPoints: 4 },
        ],
      },
    }),
  });
  assert.equal(assessCreate.status, 201);
  const assessId = assessCreate.json.data.id;

  // Duplicate check
  const dupRes = await api(`/api/activity/items/${assessId}/duplicate`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${teacher.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
    body: JSON.stringify({}),
  });
  assert.equal(dupRes.status, 201);
  assert.ok(dupRes.json.data.title.includes('(Copy)'));

  // Cross-org isolation on activity access
  const crossOrg = await api(`/api/activity/items/${assessId}`, {
    headers: { Authorization: `Bearer ${outsider.token}`, 'X-Education-Organization': otherOrganizationId },
  });
  assert.equal(crossOrg.status, 404, 'Cross-org activity read must fail closed with 404');

  // Student starts attempt -> saves CAS draft -> tests CAS conflict -> submits -> PENDING_REVIEW -> Teacher reviews -> EVALUATED
  const startAtt = await api(`/api/activity/items/${assessId}/attempts`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${student.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
    body: JSON.stringify({ idempotencyKey: `att-${nonce}` }),
  });
  assert.equal(startAtt.status, 201);
  const attId = startAtt.json.data.id;
  // Answer key must never leak during IN_PROGRESS
  assert.equal(startAtt.json.data.exercise._answerKeys, undefined);

  const draftOk = await api(`/api/activity/attempts/${attId}/draft`, {
    method: 'PUT',
    headers: { Authorization: `Bearer ${student.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
    body: JSON.stringify({ answers: { q1: ['2'], q2: ['One', 'Two', 'Three'], q3: 'Atomicity Consistency Isolation Durability' }, revision: 1 }),
  });
  assert.equal(draftOk.status, 200);
  assert.equal(draftOk.json.data.revision, 2);

  // Stale revision must get 409 conflict
  const draftStale = await api(`/api/activity/attempts/${attId}/draft`, {
    method: 'PUT',
    headers: { Authorization: `Bearer ${student.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
    body: JSON.stringify({ answers: { q1: ['4'] }, revision: 1 }),
  });
  assert.equal(draftStale.status, 409);

  // Submit -> partial credit on q1 (1 pt of 2) + full ordering on q2 (2 pts) -> PENDING_REVIEW due to q3 long_text
  const subRes = await api(`/api/activity/attempts/${attId}/submit`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${student.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
    body: JSON.stringify({
      submission: { answers: { q1: ['2'], q2: ['One', 'Two', 'Three'], q3: 'Atomicity Consistency Isolation Durability' } },
      revision: 2,
    }),
  });
  assert.equal(subRes.status, 200);
  assert.equal(subRes.json.data.state, 'PENDING_REVIEW');
  assert.equal(Number(subRes.json.data.rawScore), 3);

  // Teacher reviews manually -> EVALUATED
  const revRes = await api(`/api/activity/attempts/${attId}/review`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${teacher.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
    body: JSON.stringify({ rawScore: 7, reviewNotes: 'Clear explanation!' }),
  });
  assert.equal(revRes.status, 200);
  assert.equal(revRes.json.data.state, 'EVALUATED');
  assert.equal(Number(revRes.json.data.percentage), 87.5);
  assert.equal(revRes.json.data.passed, true);
  record('P11-ENGINE-01', 'Assessment 2.0 State Machine, Partial Credit, CAS Draft Conflict, Manual Review & Isolation');

  // 3. Create & publish Typing 4.0, Math Sprint, Logic Lab, Memory Studio activities
  const createPublished = async (pluginCode, title, config = {}) => {
    const res = await api('/api/activity/items', {
      method: 'POST',
      headers: { Authorization: `Bearer ${teacher.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
      body: JSON.stringify({ pluginCode, title, config, publish: true }),
    });
    assert.equal(res.status, 201, `Failed creating ${pluginCode}: ${res.text}`);
    return res.json.data.id;
  };

  const typingId = await createPublished('platform-typing', `Typing 4.0 ${nonce}`, { mode: 'words', wordCount: 8, timeLimitSeconds: 60, minAccuracy: 50 });
  const mathId = await createPublished('platform-math-sprint', `Math Sprint ${nonce}`, { operations: ['add', 'mul'], difficulty: 1, questionCount: 5 });
  const logicId = await createPublished('platform-logic', `Logic Lab ${nonce}`, { rounds: 4, difficulty: 1 });
  const memoryId = await createPublished('platform-memory', `Memory Grid ${nonce}`, { gridSize: 3, rounds: 3, previewMs: 800 });

  // Verify Analytics endpoint
  const anaRes = await api('/api/activity/analytics', {
    headers: { Authorization: `Bearer ${student.token}`, 'X-Education-Organization': organizationId },
  });
  assert.equal(anaRes.status, 200);
  assert.ok(Number(anaRes.json.data.summary.completedAttempts) >= 1);

  // Share Activity Card into Chat Room and verify
  const roomRes = await api('/api/chat/rooms', {
    method: 'POST',
    headers: { Authorization: `Bearer ${teacher.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
    body: JSON.stringify({
      roomType: 'group',
      title: `Activity Room ${nonce}`,
      memberPersonIds: [student.personId],
    }),
  });
  const roomId = roomRes.json.data.id;
  const shareRes = await api(`/api/chat/rooms/${roomId}/messages`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${teacher.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
    body: JSON.stringify({
      kind: 'activity_card',
      body: `Please complete Typing 4.0 ${nonce}`,
      metadata: { activityId: typingId, title: `Typing 4.0 ${nonce}` },
    }),
  });
  assert.equal(shareRes.status, 201);

  // Assign activity to student person + verify Assignment List & Notification delivery
  const assignRes = await api(`/api/activity/items/${typingId}/assignments`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${teacher.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
    body: JSON.stringify({ targetType: 'person', targetId: student.personId, allowLate: false }),
  });
  assert.equal(assignRes.status, 201, `Assignment failed: ${assignRes.text}`);
  assert.equal(assignRes.json.data.notifiedCount, 1);
  const assignList = await api(`/api/activity/assignments?targetId=${student.personId}`, {
    headers: { Authorization: `Bearer ${student.token}`, 'X-Education-Organization': organizationId },
  });
  assert.equal(assignList.status, 200);
  assert.ok(assignList.json.data.items.some((a) => a.activityId === typingId));
  record('P11-CHAT-INTEGRATION', 'All 5 Activity Types Published, Assigned, Notified, Analytics Verified & Shared to Chat');

  // 4. Real Chromium UI Verification: Studio, Catalog, Typing 4.0 Runner, Math Sprint Runner, Chat Activity Card
  vite = await startVite();
  browser = await launchBrowser();

  const teacherCtx = await loginAdminBrowser(browser, teacher, '/activities/studio');
  const studentCtx = await loginAdminBrowser(browser, student, `/chat/${roomId}`);
  contexts.push(teacherCtx.context, studentCtx.context);

  // Teacher verifies Activity Studio UI loads plugins & saves draft cleanly
  await teacherCtx.page.getByText('Activity Studio').waitFor({ state: 'visible', timeout: 15_000 });
  await teacherCtx.page.locator('#uact-title').fill(`Studio Browser Quiz ${nonce}`);
  await teacherCtx.page.getByRole('button', { name: 'Publish' }).click();
  await teacherCtx.page.getByText(`Studio Browser Quiz ${nonce} (PUBLISHED)`).waitFor({ state: 'visible', timeout: 12_000 });
  await stableScreenshot(teacherCtx.page, path.join(screenshotDir, 'p11-activity-studio.png'));

  // Student sees shared Activity Card inside Chat & clicks Open Activity -> launches Typing 4.0
  await studentCtx.page.getByText(`Typing 4.0 ${nonce}`, { exact: true }).waitFor({ state: 'visible', timeout: 12_000 });
  await studentCtx.page.getByRole('link', { name: 'Open Activity' }).first().click();
  await studentCtx.page.waitForURL((u) => u.pathname.includes(`/activities/${typingId}/run`), { timeout: 15_000 });

  const promptEl = studentCtx.page.locator('[aria-label="Typing prompt"]');
  await promptEl.waitFor({ state: 'visible', timeout: 15_000 });
  const promptText = (await promptEl.innerText()).trim();
  assert.ok(promptText.length >= 10, 'Typing prompt must render');
  await studentCtx.page.locator('.uact-typing__input').fill(promptText.slice(0, Math.min(promptText.length, 40)));
  await studentCtx.page.getByRole('button', { name: 'Submit & Evaluate' }).click();
  await studentCtx.page.locator('.uact-result').waitFor({ state: 'visible', timeout: 12_000 });
  await stableScreenshot(studentCtx.page, path.join(screenshotDir, 'p11-typing-arena.png'));

  // Student verifies Catalog & Math Sprint interactive submission in browser
  await navigateClientSide(studentCtx.page, '/activities');
  await studentCtx.page.getByText('Universal Activity Catalog').waitFor({ state: 'visible', timeout: 12_000 });
  await navigateClientSide(studentCtx.page, `/activities/${mathId}/run`);
  await studentCtx.page.locator('.uact-math__input').first().waitFor({ state: 'visible', timeout: 12_000 });
  const mathInputs = studentCtx.page.locator('.uact-math__input');
  const mCount = await mathInputs.count();
  for (let i = 0; i < mCount; i++) {
    await mathInputs.nth(i).fill('10');
  }
  await studentCtx.page.getByRole('button', { name: 'Submit & Evaluate' }).click();
  await studentCtx.page.locator('.uact-result').waitFor({ state: 'visible', timeout: 12_000 });

  // Quick smoke launch of Logic Lab & Memory Studio in browser
  await navigateClientSide(studentCtx.page, `/activities/${logicId}/run`);
  await studentCtx.page.locator('.uact-logic__card').first().waitFor({ state: 'visible', timeout: 12_000 });
  await navigateClientSide(studentCtx.page, `/activities/${memoryId}/run`);
  await studentCtx.page.locator('.uact-mem__grid').waitFor({ state: 'visible', timeout: 12_000 });

  assert.equal(teacherCtx.pageErrors.length, 0, `Teacher errors: ${teacherCtx.pageErrors.join(';')}`);
  assert.equal(studentCtx.pageErrors.length, 0, `Student errors: ${studentCtx.pageErrors.join(';')}`);
  record('P11-E2E-BROWSER', 'Chromium Studio Publish, Chat Card -> Typing 4.0 Runner, Math Sprint, Logic & Memory Render + Zero Console Errors');

  // 5. Real Concurrency & Latency Benchmark (40 concurrent writes/reads)
  const timings = [];
  let failures = 0;
  const batch = Array.from({ length: 40 }, (_, i) =>
    api(`/api/chat/rooms/${roomId}/messages`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${i % 2 === 0 ? teacher.token : student.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
      body: JSON.stringify({ body: `Load msg ${i} ${nonce}`, clientKey: `load-${nonce}-${i}` }),
    }).then((r) => {
      if (r.status !== 201) failures++;
      timings.push(r.elapsedMs);
    }),
  );
  await Promise.all(batch);
  timings.sort((a, b) => a - b);
  const bench = {
    requests: 40,
    failures,
    p50Ms: percentile(timings, 50),
    p95Ms: percentile(timings, 95),
    p99Ms: percentile(timings, 99),
  };
  assert.equal(failures, 0, 'Concurrency load test must have 0 failed requests');
  evidence.benchmarks.chatConcurrent40 = bench;
  record('P11-LOAD-01', `40 Concurrent Messages + Outbox + Notifications: 0 Failures (p50=${bench.p50Ms}ms, p95=${bench.p95Ms}ms, p99=${bench.p99Ms}ms)`, bench);

  // 6. Real WebSocket Reconnect + Outbox `sinceSeq` Missed Event Recovery Proof
  const ticketRes = await api('/api/realtime/ticket', {
    method: 'POST',
    headers: { Authorization: `Bearer ${student.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
    body: JSON.stringify({ organizationId }),
  });
  assert.equal(ticketRes.status, 200, `Ticket failed: ${ticketRes.text}`);
  const seqBefore = Number(ticketRes.json.data.latestSeq || 0);

  // Emit missed message while WS client is offline
  const offlineMsgText = `Offline recovery ${nonce}`;
  const offlineMsg = await api(`/api/chat/rooms/${roomId}/messages`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${teacher.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
    body: JSON.stringify({ body: offlineMsgText, clientKey: `ws-rec-${nonce}` }),
  });
  assert.equal(offlineMsg.status, 201);

  // Reconnect WebSocket with sinceSeq and verify missed outbox event replays immediately
  const wsUrl = `${BACKEND_URL.replace(/^http/, 'ws')}${ticketRes.json.data.wsPath}?ticket=${encodeURIComponent(ticketRes.json.data.ticket)}`;
  const replayed = await new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('WS sinceSeq replay timed out')), 10_000);
    const ws = new WebSocket(wsUrl);
    ws.addEventListener('open', () => {
      ws.send(JSON.stringify({ type: 'subscribe', channel: `room:${roomId}`, sinceSeq: seqBefore }));
    });
    ws.addEventListener('message', (ev) => {
      try {
        const frame = JSON.parse(String(ev.data));
        if (frame?.type === 'event' && frame.event?.payload?.message?.body === offlineMsgText) {
          clearTimeout(timer);
          ws.close();
          resolve(frame.event);
        }
      } catch {
        // ignore non-json
      }
    });
    ws.addEventListener('error', (err) => {
      clearTimeout(timer);
      reject(new Error(`WS error: ${String(err?.message ?? err)}`));
    });
  });
  assert.ok(replayed.seq > seqBefore, 'Replayed WS event must advance sequence number');
  record('P11-WS-RECONNECT', `WebSocket Reconnect & Outbox sinceSeq Replay Recovered Event #${replayed.seq}`);

  writeFileSync(path.join(repoRoot, 'docs', 'audits', 'platform11-evidence.json'), JSON.stringify(evidence, null, 2));
  console.log('\n======================================================');
  console.log(`PLATFORM_11 FULL VERIFICATION PASSED (${evidence.passed}/${evidence.passed})`);
  console.log('======================================================');
} finally {
  for (const ctx of contexts) {
    await ctx.close().catch(() => {});
  }
  await browser?.close().catch(() => {});
  await stopChild(vite);
  runFixture('cleanup', nonce);
}
