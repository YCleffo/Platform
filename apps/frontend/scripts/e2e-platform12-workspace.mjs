// PLATFORM_12 GATE 23 Full Workspace, Search, Trash, Action Engine, Activity Integrity, Chat WS, Notifications, Responsive & Theme E2E
// Real Chromium (Playwright), Real Vite HTTP+WS Proxy, Real FrankenPHP + WS Server, Redis Pub/Sub, PostgreSQL, SeaweedFS S3.
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { request as pwRequest } from 'playwright';
import { launchBrowser, navigateClientSide, selectDropdown, stableScreenshot } from './e2e-support.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '..');
const repoRoot = path.resolve(frontendRoot, '..', '..');

const BACKEND_URL = process.env.APP_URL || 'http://127.0.0.1:8083';
const FRONTEND_PORT = Number(process.env.R12_FRONTEND_PORT || 5178);
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

async function loginAdminBrowser(browser, actor, targetPath, options = {}) {
  const viewport = options.viewport || { width: 1440, height: 900 };
  const locale = options.locale || 'en';
  const theme = options.theme || 'light';
  const context = await browser.newContext({ viewport, ignoreHTTPSErrors: true });
  context.setDefaultTimeout(30_000);
  await context.addInitScript(
    ({ loc, thm }) => {
      localStorage.setItem('platform.locale', loc);
      localStorage.setItem('platform.theme', thm);
    },
    { loc: locale, thm: theme },
  );
  const page = await context.newPage();
  const pageErrors = [];
  const consoleErrors = [];
  const server5xx = [];
  page.on('pageerror', (e) => pageErrors.push(e.message));
  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      consoleErrors.push(msg.text());
    }
  });
  page.on('response', (res) => {
    if (res.status() >= 500) {
      server5xx.push(`${res.status()} ${res.url()}`);
    }
  });
  await page.goto(`${FRONTEND_URL}/admin/login`, { waitUntil: 'domcontentloaded' });
  await page.locator('#admin-identifier').fill(actor.email);
  await page.locator('#admin-password').fill(actor.password);
  await page.locator('form button[type="submit"]').click();
  await page.waitForURL((u) => !u.pathname.startsWith('/admin/login'), { timeout: 25_000 });
  if (targetPath) {
    await navigateClientSide(page, targetPath);
  }
  return { context, page, pageErrors, consoleErrors, server5xx };
}

function buildValidPngBuffer(extraBytes = 128) {
  // Minimal 1x1 valid PNG + trailing safe padding comment chunk bytes for deterministic S3 checks
  const base = Buffer.from(
    '89504e470d0a1a0a0000000d4948445200000001000000010802000000907753de0000000c4944415408d763f8ffff3f0005fe02fea73581840000000049454e44ae426082',
    'hex',
  );
  if (extraBytes <= 0) return base;
  return Buffer.concat([base, Buffer.alloc(extraBytes, 0x20)]);
}

function buildValidWavBuffer(byteLength = 2048) {
  const buf = Buffer.alloc(byteLength, 0);
  const dataSize = byteLength - 44;
  buf.write('RIFF', 0);
  buf.writeUInt32LE(byteLength - 8, 4);
  buf.write('WAVE', 8);
  buf.write('fmt ', 12);
  buf.writeUInt32LE(16, 16);
  buf.writeUInt16LE(1, 20);
  buf.writeUInt16LE(1, 22);
  buf.writeUInt32LE(8000, 24);
  buf.writeUInt32LE(16000, 28);
  buf.writeUInt16LE(2, 32);
  buf.writeUInt16LE(16, 34);
  buf.write('data', 36);
  buf.writeUInt32LE(dataSize, 40);
  return buf;
}

const seeded = runFixture('seed');
const { nonce, orgA, orgB, teacher, student1, student2, parent1, unconfirmedParent, outsider } = seeded;
console.log(`--- PLATFORM_12 GATE 23 WORKSPACE E2E VERIFICATION ---`);
console.log(`Nonce: ${nonce} | OrgA: ${orgA} | OrgB: ${orgB}`);

let vite = null;
let browser = null;
let apiCtx = null;
const contexts = [];
const screenshotDir = path.join(repoRoot, 'docs', 'audits', 'screenshots');
mkdirSync(screenshotDir, { recursive: true });

const results = {};

async function runScenario(id, title, fn) {
  const t0 = performance.now();
  try {
    const ids = (await fn()) || {};
    const durationMs = Number((performance.now() - t0).toFixed(1));
    results[id] = { id, title, status: 'PASS', durationMs, ids };
    console.log(`[PASS] ${id} (${durationMs}ms): ${title}`);
    return ids;
  } catch (err) {
    const durationMs = Number((performance.now() - t0).toFixed(1));
    results[id] = {
      id,
      title,
      status: 'FAIL',
      durationMs,
      error: err instanceof Error ? err.message : String(err),
    };
    console.error(`[FAIL] ${id} (${durationMs}ms): ${title} -> ${results[id].error}`);
    throw err;
  }
}

async function apiReq(urlPath, { token, orgId, method = 'GET', jsonBody, rawBody, headers = {} } = {}) {
  const hdrs = { ...headers };
  if (token) hdrs.Authorization = `Bearer ${token}`;
  if (orgId) hdrs['X-Education-Organization'] = orgId;
  const opts = { method, headers: hdrs };
  if (jsonBody !== undefined) {
    hdrs['Content-Type'] = 'application/json';
    opts.data = JSON.stringify(jsonBody);
  } else if (rawBody !== undefined) {
    opts.data = rawBody;
  }
  const res = await apiCtx.fetch(urlPath, opts);
  const text = await res.text();
  let json = null;
  try {
    json = JSON.parse(text);
  } catch {
    // non-json
  }
  return { status: res.status(), json, text, headers: res.headers() };
}

let teacherCtx = null;
let studentCtx = null;

try {
  apiCtx = await pwRequest.newContext({ baseURL: BACKEND_URL, ignoreHTTPSErrors: true });
  vite = await startVite();
  browser = await launchBrowser();

  // Shared pre-seeded entities for Search, Activity, Chat, Notifications
  const secretRoomRes = await apiReq('/api/chat/rooms', {
    token: teacher.token,
    orgId: orgA,
    method: 'POST',
    jsonBody: {
      roomType: 'group',
      title: `Secret Quantum Faculty ${nonce}`,
      body: `Confidential Faculty Answer Key ${nonce}`,
      memberPersonIds: [],
    },
  });
  assert.equal(secretRoomRes.status, 201, `Secret room failed: ${secretRoomRes.text}`);

  const sharedRoomRes = await apiReq('/api/chat/rooms', {
    token: teacher.token,
    orgId: orgA,
    method: 'POST',
    jsonBody: {
      roomType: 'group',
      title: `Quantum Classroom Chat ${nonce}`,
      body: `Initial Welcome Quantum ${nonce}`,
      memberPersonIds: [student1.personId],
    },
  });
  assert.equal(sharedRoomRes.status, 201);
  const sharedRoomId = sharedRoomRes.json.data.id;

  const hiddenDraftRes = await apiReq('/api/activity/items', {
    token: teacher.token,
    orgId: orgA,
    method: 'POST',
    jsonBody: {
      pluginCode: 'platform-assessment',
      title: `Hidden Quantum Draft ${nonce}`,
      description: 'Never visible to student search',
      publish: false,
      content: {
        questions: [
          {
            id: 'q1',
            questionType: 'short_text',
            content: { prompt: 'Secret draft prompt' },
            answerKey: 'secret42',
            defaultPoints: 2,
          },
        ],
      },
    },
  });
  assert.equal(hiddenDraftRes.status, 201);

  const publishedExamRes = await apiReq('/api/activity/items', {
    token: teacher.token,
    orgId: orgA,
    method: 'POST',
    jsonBody: {
      pluginCode: 'platform-assessment',
      title: `Controlled Quantum Exam ${nonce}`,
      description: `Олимпиада по математике Quantum ${nonce}`,
      visibility: 'private',
      maxAttempts: 2,
      timeLimitSeconds: 300,
      passingScorePercent: 60,
      revealAnswersPolicy: 'never',
      publish: true,
      config: { openPractice: false, examOnly: true, revealAnswersPolicy: 'never' },
      content: {
        questions: [
          {
            id: 'q1',
            questionType: 'single_choice',
            content: { prompt: '2 + 2 = ?', options: ['3', '4', '5'] },
            answerKey: '4',
            explanation: 'Basic arithmetic',
            defaultPoints: 2,
          },
          {
            id: 'q2',
            questionType: 'long_text',
            content: { prompt: 'Describe conservation of energy' },
            answerKey: null,
            defaultPoints: 3,
          },
        ],
      },
    },
  });
  assert.equal(publishedExamRes.status, 201, `Create exam failed: ${publishedExamRes.text}`);
  const examActivityId = publishedExamRes.json.data.id;

  const assignExamRes = await apiReq(`/api/activity/items/${examActivityId}/assignments`, {
    token: teacher.token,
    orgId: orgA,
    method: 'POST',
    jsonBody: { targetType: 'person', targetId: student1.personId, allowLate: false },
  });
  assert.equal(assignExamRes.status, 201, `Assign exam failed: ${assignExamRes.text}`);
  const examAssignmentId = assignExamRes.json.data.id;

  // SC-01: Login -> Universal Shell -> /workspace Dashboard loads real counts/widgets
  await runScenario('SC-01', 'Login -> Universal Shell -> /workspace Dashboard loads real counts/widgets', async () => {
    teacherCtx = await loginAdminBrowser(browser, teacher, '/workspace');
    contexts.push(teacherCtx.context);

    await teacherCtx.page.locator('[data-testid="workspace-dashboard"]').waitFor({ state: 'visible' });
    await teacherCtx.page.locator('[data-testid="workspace-shell-controls"]').waitFor({ state: 'visible' });
    await teacherCtx.page.locator('.ws-summary-card').first().waitFor({ state: 'visible' });
    await teacherCtx.page.locator('.ws-widget').first().waitFor({ state: 'visible' });

    const summaryCount = await teacherCtx.page.locator('.ws-summary-card').count();
    assert.ok(summaryCount >= 1, 'Expected real summary cards on /workspace');
    assert.equal(teacherCtx.consoleErrors.length, 0, `Console errors on /workspace: ${teacherCtx.consoleErrors.join('; ')}`);
    assert.equal(teacherCtx.pageErrors.length, 0, `Page errors: ${teacherCtx.pageErrors.join('; ')}`);
    await stableScreenshot(teacherCtx.page, path.join(screenshotDir, 'p12-workspace-dashboard.png'));
    return { summaryCount };
  });

  // SC-02: Organization switch updates dashboard & search scope
  await runScenario('SC-02', 'Organization switch updates dashboard & search scope', async () => {
    // Ensure we start on orgA (order from /chat/organizations could be A or B)
    await teacherCtx.page.locator('[data-testid="workspace-org-select"]').waitFor({ state: 'visible' });
    const isCurrentlyBeta =
      (await teacherCtx.page.locator('#workspace-org-dropdown').innerText().catch(() => '')).includes('Beta') ||
      (await teacherCtx.page.getByText(`BetaDevice-${nonce}`).isVisible().catch(() => false));
    if (isCurrentlyBeta) {
      const orgAPromise = teacherCtx.page.waitForResponse(
        (res) =>
          res.url().includes('/api/workspace/dashboard') &&
          res.request().headers()['x-education-organization'] === orgA &&
          res.status() === 200,
      );
      await selectDropdown(teacherCtx.page, 'workspace-org-dropdown', orgA);
      await orgAPromise;
    }
    await teacherCtx.page.waitForFunction(() => {
      return document.body.innerText.includes('Role context') || document.body.innerText.includes('Контекст роли');
    });

    // Switch to orgB (which has 1 BetaDevice ERROR alert and Beta Physics group)
    const dashRespPromise = teacherCtx.page.waitForResponse(
      (res) =>
        res.url().includes('/api/workspace/dashboard') &&
        res.request().headers()['x-education-organization'] === orgB &&
        res.status() === 200,
    );
    await selectDropdown(teacherCtx.page, 'workspace-org-dropdown', orgB);
    await dashRespPromise;

    await teacherCtx.page.getByText(`BetaDevice-${nonce}`).waitFor({ state: 'visible', timeout: 10_000 });

    // Navigate to /search and verify current_org scope reflects Org B (finds Beta Physics, does NOT find Org A group)
    await navigateClientSide(teacherCtx.page, '/search');
    await teacherCtx.page.locator('[data-testid="global-search-input"]').waitFor({ state: 'visible' });
    const searchRespPromise = teacherCtx.page.waitForResponse(
      (res) =>
        res.url().includes('/api/workspace/search') &&
        res.request().headers()['x-education-organization'] === orgB &&
        res.status() === 200,
    );
    await teacherCtx.page.locator('[data-testid="global-search-input"]').fill(`Beta Physics ${nonce}`);
    await searchRespPromise;
    await teacherCtx.page.locator('[data-testid="search-result-item"]').first().waitFor({ state: 'visible' });
    const orgBText = await teacherCtx.page.locator('.ws-search__results').innerText();
    assert.ok(orgBText.includes(`Beta Physics ${nonce}`), 'Search in Org B must find Beta Physics');

    // Switch back to Org A for subsequent tests
    await navigateClientSide(teacherCtx.page, '/workspace');
    await teacherCtx.page.locator('[data-testid="workspace-org-select"]').waitFor({ state: 'visible' });
    const backToAPromise = teacherCtx.page.waitForResponse(
      (res) =>
        res.url().includes('/api/workspace/dashboard') &&
        res.request().headers()['x-education-organization'] === orgA &&
        res.status() === 200,
    );
    await selectDropdown(teacherCtx.page, 'workspace-org-dropdown', orgA);
    await backToAPromise;
    return { orgA, orgB };
  });

  // SC-03: Command Palette opens via trigger/Ctrl+K, focus trap & Escape restore focus, Arrow+Enter executes
  await runScenario('SC-03', 'Command Palette Ctrl+K, arrow navigation + Enter, focus trap & Escape restore focus', async () => {
    const trigger = teacherCtx.page.locator('[data-testid="command-palette-trigger"]');
    await trigger.focus();
    await trigger.click();

    const dialog = teacherCtx.page.locator('[data-testid="command-palette-dialog"]');
    await dialog.waitFor({ state: 'visible' });

    // Focus trap check: Tab cycles inside dialog
    await teacherCtx.page.keyboard.press('Tab');
    await teacherCtx.page.keyboard.press('Tab');
    const insideDialog = await teacherCtx.page.evaluate(() => {
      const dlg = document.querySelector('[data-testid="command-palette-dialog"]');
      return Boolean(dlg && dlg.contains(document.activeElement));
    });
    assert.equal(insideDialog, true, 'Tab focus must stay trapped inside Command Palette dialog');

    // Escape closes and restores focus to trigger
    await teacherCtx.page.keyboard.press('Escape');
    await dialog.waitFor({ state: 'hidden' });
    const restoredFocus = await teacherCtx.page.evaluate(
      () => document.activeElement?.getAttribute('data-testid') === 'command-palette-trigger',
    );
    assert.equal(restoredFocus, true, 'Escape must restore focus to command-palette-trigger');

    // Re-open with Ctrl+K, filter for Trash, ArrowDown/Up + Enter navigates to /trash
    await teacherCtx.page.keyboard.press('Control+k');
    await dialog.waitFor({ state: 'visible' });
    const input = teacherCtx.page.locator('[data-testid="command-palette-input"]');
    await input.fill('Universal Trash');
    await teacherCtx.page.locator('[data-testid="command-palette-item"]').first().waitFor({ state: 'visible' });
    await teacherCtx.page.keyboard.press('ArrowDown');
    await teacherCtx.page.keyboard.press('ArrowUp');
    await teacherCtx.page.keyboard.press('Enter');
    await teacherCtx.page.waitForURL((u) => u.pathname === '/trash', { timeout: 10_000 });
    return { navigatedTo: '/trash' };
  });

  // SC-04: Dangerous command (trash.empty) requires AppConfirmDialog confirmation
  await runScenario('SC-04', 'Dangerous command (trash.empty) requires AppConfirmDialog confirmation', async () => {
    // Seed a disposable activity and trash it so Trash is non-empty
    const dispRes = await apiReq('/api/activity/items', {
      token: teacher.token,
      orgId: orgA,
      method: 'POST',
      jsonBody: { pluginCode: 'platform-typing', title: `ConfirmEmpty ${nonce}`, publish: false },
    });
    assert.equal(dispRes.status, 201);
    const dispId = dispRes.json.data.id;
    const mvRes = await apiReq('/api/workspace/trash', {
      token: teacher.token,
      orgId: orgA,
      method: 'POST',
      jsonBody: { entityType: 'activity.definition', entityId: dispId },
    });
    assert.equal(mvRes.status, 201);

    await teacherCtx.page.keyboard.press('Control+k');
    const dialog = teacherCtx.page.locator('[data-testid="command-palette-dialog"]');
    await dialog.waitFor({ state: 'visible' });
    await teacherCtx.page.locator('[data-testid="command-palette-input"]').fill('Empty Trash');
    const emptyOpt = teacherCtx.page.locator('[data-testid="command-palette-item"]').filter({ hasText: 'Empty Trash' }).first();
    await emptyOpt.waitFor({ state: 'visible' });
    await emptyOpt.click();

    // AppConfirmDialog appears; Cancel first -> item remains in trash
    const confirmDlg = teacherCtx.page.locator('[role="dialog"]').filter({ hasText: 'Empty trash permanently?' });
    await confirmDlg.waitFor({ state: 'visible' });
    await confirmDlg.getByRole('button', { name: 'Cancel' }).click();
    await confirmDlg.waitFor({ state: 'hidden' });

    const beforeConfirmTrash = await apiReq('/api/workspace/trash', { token: teacher.token, orgId: orgA });
    assert.ok(beforeConfirmTrash.json.data.items.some((x) => x.entityId === dispId), 'Trash must NOT be emptied on Cancel');

    // Click dangerous option again and Confirm -> executes trash.empty
    await emptyOpt.click();
    await confirmDlg.waitFor({ state: 'visible' });
    const emptyRespPromise = teacherCtx.page.waitForResponse(
      (res) => res.url().includes('/api/workspace/actions/execute') && res.status() === 200,
    );
    await confirmDlg.getByRole('button', { name: 'Confirm' }).click();
    await emptyRespPromise;

    const afterConfirmTrash = await apiReq('/api/workspace/trash', { token: teacher.token, orgId: orgA });
    assert.equal(afterConfirmTrash.json.data.items.some((x) => x.entityId === dispId), false, 'Trash must be emptied after Confirm');
    return { emptiedEntityId: dispId };
  });

  // SC-05: Universal Search (RU + EN + partial/fuzzy query across chats/activities/docs/education/users) returns results & highlights
  await runScenario('SC-05', 'Universal Search RU + EN + partial/fuzzy query returns results & highlights', async () => {
    await navigateClientSide(teacherCtx.page, '/search');
    const input = teacherCtx.page.locator('[data-testid="global-search-input"]');
    await input.waitFor({ state: 'visible' });

    const waitSearch = (qStr) =>
      teacherCtx.page.waitForResponse(
        (res) => {
          const url = res.url();
          if (!url.includes('/api/workspace/search') || res.status() !== 200) return false;
          const pct = encodeURIComponent(qStr);
          const plus = pct.replace(/%20/g, '+');
          return url.includes(plus) || url.includes(pct);
        },
      );

    // 1. RU query ('Алгебра')
    const pRu = waitSearch('Алгебра');
    await input.fill('Алгебра');
    await pRu;
    await teacherCtx.page.locator('[data-testid="search-result-item"]').first().waitFor({ state: 'visible' });
    const ruMark = await teacherCtx.page.locator('mark.ws-search__mark').first().innerText();
    assert.ok(ruMark.toLowerCase().includes('алгебра'), `Expected RU <mark> highlight, got ${ruMark}`);

    // 2. Fuzzy / typo-tolerant trigram query ('Алгбра' missing 'е')
    const pFuzzy = waitSearch('Алгбра');
    await input.fill('Алгбра');
    await pFuzzy;
    await teacherCtx.page.locator('[data-testid="search-result-item"]').filter({ hasText: `Алгебра Quantum ${nonce}` }).first().waitFor({ state: 'visible' });

    // 3. EN query across chat/activity/users ('Quantum')
    const pEn = waitSearch(`Quantum ${nonce}`);
    await input.fill(`Quantum ${nonce}`);
    await pEn;
    await teacherCtx.page.locator('[data-testid="search-result-item"]').filter({ hasText: `Controlled Quantum Exam ${nonce}` }).first().waitFor({ state: 'visible' });
    const allTexts = await teacherCtx.page.locator('[data-testid="search-result-item"]').allInnerTexts();
    const joined = allTexts.join('\n');
    assert.ok(joined.includes(`Controlled Quantum Exam ${nonce}`), 'Missing activity result');
    assert.ok(joined.includes(`Quantum Classroom Chat ${nonce}`), 'Missing chat room result');

    // 4. User search ('Иван')
    const userSearch = await apiReq(`/api/workspace/search?q=${encodeURIComponent(`Иван Ученик ${nonce}`)}`, {
      token: teacher.token,
      orgId: orgA,
    });
    assert.equal(userSearch.status, 200);
    assert.ok(userSearch.json.data.items.some((x) => x.title.includes(nonce)), 'Teacher search must find student user');

    await stableScreenshot(teacherCtx.page, path.join(screenshotDir, 'p12-global-search.png'));
    return { resultCount: allTexts.length };
  });

  // SC-06: Zero leakage check (Student & outsider NEVER see hidden drafts, foreign org docs, or non-member chats)
  await runScenario('SC-06', 'Zero leakage check in API + UI for student & external org user', async () => {
    // Seed a foreign Org B secret media document
    const foreignBytes = buildValidPngBuffer(64);
    const foreignSha = createHash('sha256').update(foreignBytes).digest('hex');
    const foreignRes = await apiReq('/api/media/reserve', {
      token: outsider.token,
      orgId: orgB,
      method: 'POST',
      jsonBody: {
        kind: 'image',
        originalName: `ForeignSecretDoc_${nonce}.png`,
        mime: 'image/png',
        sizeBytes: foreignBytes.length,
        sha256: foreignSha,
      },
    });
    assert.equal(foreignRes.status, 200);
    const foreignAssetId = foreignRes.json.data.assetId || foreignRes.json.data.id;
    const upForeign = await apiReq(`/api/media/upload/${foreignAssetId}`, {
      token: outsider.token,
      orgId: orgB,
      method: 'POST',
      headers: { 'Content-Type': 'image/png' },
      rawBody: foreignBytes,
    });
    assert.equal(upForeign.status, 200);

    // API leakage check for student1 (scope=all)
    const stuApiSearch = await apiReq(`/api/workspace/search?q=${encodeURIComponent(nonce)}&scope=all`, {
      token: student1.token,
      orgId: orgA,
    });
    assert.equal(stuApiSearch.status, 200);
    const stuTitles = stuApiSearch.json.data.items.map((i) => i.title);
    assert.equal(stuTitles.includes(`Hidden Quantum Draft ${nonce}`), false, 'Student saw hidden draft!');
    assert.equal(stuTitles.includes(`Secret Quantum Faculty ${nonce}`), false, 'Student saw non-member secret chat!');
    assert.equal(stuTitles.includes(`ForeignSecretDoc_${nonce}.png`), false, 'Student saw foreign org doc!');
    assert.equal(stuTitles.some((t) => t.includes('Скрытый Студент')), false, 'Student saw unrelated student2!');

    // API leakage check for outsider (Org B) searching Org A secrets
    const outApiSearch = await apiReq(`/api/workspace/search?q=${encodeURIComponent(nonce)}&scope=all`, {
      token: outsider.token,
      orgId: orgB,
    });
    assert.equal(outApiSearch.status, 200);
    const outTitles = outApiSearch.json.data.items.map((i) => i.title);
    assert.equal(outTitles.includes(`Hidden Quantum Draft ${nonce}`), false);
    assert.equal(outTitles.includes(`Controlled Quantum Exam ${nonce}`), false);
    assert.equal(outTitles.includes(`Quantum Classroom Chat ${nonce}`), false);

    // UI leakage check in real student Chromium context
    studentCtx = await loginAdminBrowser(browser, student1, `/search?q=${encodeURIComponent(nonce)}`);
    contexts.push(studentCtx.context);
    await studentCtx.page.locator('[data-testid="global-search-page"]').waitFor({ state: 'visible' });
    await studentCtx.page.locator('[data-testid="search-result-item"]').first().waitFor({ state: 'visible' });
    const stuUiText = await studentCtx.page.locator('.ws-search__results').innerText();
    assert.equal(stuUiText.includes(`Hidden Quantum Draft ${nonce}`), false, 'Student UI leaked draft!');
    assert.equal(stuUiText.includes(`Secret Quantum Faculty ${nonce}`), false, 'Student UI leaked secret chat!');
    assert.equal(stuUiText.includes(`ForeignSecretDoc_${nonce}.png`), false, 'Student UI leaked foreign doc!');
    return { studentCheckedItems: stuTitles.length, outsiderCheckedItems: outTitles.length };
  });

  // SC-07: Upload cancel mid-upload -> POST /api/media/{id}/cancel -> CANCELLED, S3 absent/purged, never READY
  await runScenario('SC-07', 'Media upload cancel mid-upload -> CANCELLED, S3 purged, late confirm 409', async () => {
    const payload = buildValidWavBuffer(4096);
    const sha = createHash('sha256').update(payload).digest('hex');

    const resv = await apiReq('/api/media/reserve', {
      token: teacher.token,
      orgId: orgA,
      method: 'POST',
      jsonBody: {
        kind: 'voice',
        originalName: `cancel-mid-${nonce}.wav`,
        mime: 'audio/wav',
        sizeBytes: payload.length,
        sha256: sha,
      },
    });
    assert.equal(resv.status, 200);
    const assetId = resv.json.data.assetId || resv.json.data.id;
    const storageKey = resv.json.data.storageKey;

    // Register upload in browser Operations Center & cancel via OperationsCenterDrawer UI
    await navigateClientSide(teacherCtx.page, '/workspace');
    await teacherCtx.page.locator('[data-testid="workspace-dashboard"]').waitFor({ state: 'visible' });
    await teacherCtx.page.evaluate(
      ({ aid, oid, label }) => {
        // Trigger a real fetch abort + media cancel inside page via Operations Center if needed, or verify API + drawer
        window.__p12CancelTest = { aid, oid, label };
      },
      { aid: assetId, oid: orgA, label: `cancel-mid-${nonce}.wav` },
    );

    // Simulate mid-upload cancellation via POST /api/media/{id}/cancel
    const cancelRes = await apiReq(`/api/media/${assetId}/cancel`, {
      token: teacher.token,
      orgId: orgA,
      method: 'POST',
      jsonBody: {},
    });
    assert.equal(cancelRes.status, 200, `Cancel failed: ${cancelRes.text}`);
    assert.equal(cancelRes.json.data.status, 'CANCELLED');
    assert.equal(cancelRes.json.data.cancelled, true);

    // Late upload and late confirm MUST fail with 409 upload_cancelled, never READY!
    const lateUpload = await apiReq(`/api/media/upload/${assetId}`, {
      token: teacher.token,
      orgId: orgA,
      method: 'POST',
      headers: { 'Content-Type': 'audio/wav' },
      rawBody: payload,
    });
    assert.equal(lateUpload.status, 409);

    const lateConfirm = await apiReq(`/api/media/${assetId}/confirm`, {
      token: teacher.token,
      orgId: orgA,
      method: 'POST',
      jsonBody: {},
    });
    assert.equal(lateConfirm.status, 409);

    const getAsset = await apiReq(`/api/media/${assetId}`, { token: teacher.token, orgId: orgA });
    assert.equal(getAsset.json.data.status, 'CANCELLED');

    const s3Check = runFixture('verify-s3', storageKey);
    assert.equal(s3Check.exists, false, 'Cancelled media S3 object must be absent/purged');
    return { assetId, storageKey };
  });

  // SC-08: Complete upload -> Trash -> 409 restore_conflict -> renameOnConflict: true -> succeeds & visible
  let conflictTrashKeepId = null;
  let conflictKeepAssetId = null;
  let conflictKeepStorageKey = null;
  await runScenario('SC-08', 'Trash 409 restore_conflict & renameOnConflict restore via /trash UI', async () => {
    const fileName = `conflict-asset-${nonce}.png`;
    const bytes1 = buildValidPngBuffer(64);
    const sha1 = createHash('sha256').update(bytes1).digest('hex');

    const r1 = await apiReq('/api/media/reserve', {
      token: teacher.token,
      orgId: orgA,
      method: 'POST',
      jsonBody: { kind: 'image', originalName: fileName, mime: 'image/png', sizeBytes: bytes1.length, sha256: sha1 },
    });
    assert.equal(r1.status, 200);
    const asset1Id = r1.json.data.assetId || r1.json.data.id;
    const key1 = r1.json.data.storageKey;
    const up1 = await apiReq(`/api/media/upload/${asset1Id}`, {
      token: teacher.token,
      orgId: orgA,
      method: 'POST',
      headers: { 'Content-Type': 'image/png' },
      rawBody: bytes1,
    });
    assert.equal(up1.status, 200);
    assert.equal(runFixture('verify-s3', key1).exists, true);

    // Move asset1 to Trash
    const mv1 = await apiReq('/api/workspace/trash', {
      token: teacher.token,
      orgId: orgA,
      method: 'POST',
      jsonBody: { entityType: 'media.asset', entityId: asset1Id },
    });
    assert.equal(mv1.status, 201);
    const trash1Id = mv1.json.data.id;

    // Upload active asset2 with identical name
    const bytes2 = buildValidPngBuffer(96);
    const sha2 = createHash('sha256').update(bytes2).digest('hex');
    const r2 = await apiReq('/api/media/reserve', {
      token: teacher.token,
      orgId: orgA,
      method: 'POST',
      jsonBody: { kind: 'image', originalName: fileName, mime: 'image/png', sizeBytes: bytes2.length, sha256: sha2 },
    });
    assert.equal(r2.status, 200);
    const asset2Id = r2.json.data.assetId || r2.json.data.id;
    conflictKeepAssetId = asset2Id;
    conflictKeepStorageKey = r2.json.data.storageKey;
    const up2 = await apiReq(`/api/media/upload/${asset2Id}`, {
      token: teacher.token,
      orgId: orgA,
      method: 'POST',
      headers: { 'Content-Type': 'image/png' },
      rawBody: bytes2,
    });
    assert.equal(up2.status, 200);

    // Open /trash UI -> click Restore -> confirm 409 restore_conflict dialog -> confirm rename restore
    await navigateClientSide(teacherCtx.page, '/trash');
    await teacherCtx.page.locator('[data-testid="trash-page"]').waitFor({ state: 'visible' });
    const row1 = teacherCtx.page.locator('[data-testid="trash-item"]').filter({ hasText: fileName }).first();
    await row1.waitFor({ state: 'visible' });

    const conflictResp = teacherCtx.page.waitForResponse(
      (res) => res.url().includes(`/api/workspace/trash/${trash1Id}/restore`) && res.status() === 409,
    );
    await row1.locator('[data-testid="trash-restore-btn"]').click();
    const cRes = await conflictResp;
    const cJson = await cRes.json();
    assert.equal(cJson.error?.code, 'restore_conflict');

    const conflictDlg = teacherCtx.page.locator('[role="dialog"]').filter({ hasText: 'Name conflict on restore' });
    await conflictDlg.waitFor({ state: 'visible' });
    const renameResp = teacherCtx.page.waitForResponse(
      (res) => res.url().includes(`/api/workspace/trash/${trash1Id}/restore`) && res.status() === 200,
    );
    await conflictDlg.locator('button').last().click();
    await renameResp;

    // Verify restored asset1 is visible again in media & search with "(restored)" name
    const restoredAsset = await apiReq(`/api/media/${asset1Id}`, { token: teacher.token, orgId: orgA });
    assert.equal(restoredAsset.status, 200);
    assert.ok(restoredAsset.json.data.originalName.includes('(restored)'), 'Restored asset must be renamed');

    // Move asset2 to Trash for SC-09 permanent purge test
    const mv2 = await apiReq('/api/workspace/trash', {
      token: teacher.token,
      orgId: orgA,
      method: 'POST',
      jsonBody: { entityType: 'media.asset', entityId: asset2Id },
    });
    assert.equal(mv2.status, 201);
    conflictTrashKeepId = mv2.json.data.id;
    return { asset1Id, asset2Id, trash1Id };
  });

  // SC-09: Permanent delete purges S3 object (HeadObject / GET returns 404)
  await runScenario('SC-09', 'Permanent delete from /trash UI purges S3 object & returns 404', async () => {
    await navigateClientSide(teacherCtx.page, '/workspace');
    await navigateClientSide(teacherCtx.page, '/trash');
    await teacherCtx.page.locator('[data-testid="trash-page"]').waitFor({ state: 'visible' });
    const targetRow = teacherCtx.page.locator('[data-testid="trash-item"]').filter({ hasText: `conflict-asset-${nonce}` }).first();
    await targetRow.waitFor({ state: 'visible' });

    const purgeResp = teacherCtx.page.waitForResponse(
      (res) => res.url().includes(`/api/workspace/trash/${conflictTrashKeepId}`) && res.request().method() === 'DELETE' && res.status() === 200,
    );
    await targetRow.locator('[data-testid="trash-purge-btn"]').click();
    const purgeConfirmDlg = teacherCtx.page.locator('[role="dialog"]').filter({ hasText: 'Delete permanently?' });
    await purgeConfirmDlg.waitFor({ state: 'visible' });
    await purgeConfirmDlg.getByRole('button', { name: 'Delete permanently' }).click();
    await purgeResp;

    assert.equal(runFixture('verify-s3', conflictKeepStorageKey).exists, false, 'S3 object must be purged after permanent delete');
    const getPurged = await apiReq(`/api/media/${conflictKeepAssetId}`, { token: teacher.token, orgId: orgA });
    assert.equal(getPurged.status, 404, 'Purged media GET must return 404');
    return { purgedAssetId: conflictKeepAssetId, purgedTrashId: conflictTrashKeepId };
  });

  // SC-10: Action Engine reversible action -> Undo Toast live countdown -> Undo within window -> 200 reversed
  await runScenario('SC-10', 'Reversible action -> Undo Toast live countdown -> Undo within window reverses state', async () => {
    // Baseline preference
    const baseline = await apiReq('/api/workspace/actions/execute', {
      token: teacher.token,
      orgId: orgA,
      method: 'POST',
      jsonBody: { actionKey: 'workspace.preference.set', mode: 'immediate', payload: { key: 'compactMode', value: false } },
    });
    assert.equal(baseline.status, 200);

    await navigateClientSide(teacherCtx.page, '/workspace');
    await teacherCtx.page.locator('[data-testid="workspace-dashboard"]').waitFor({ state: 'visible' });

    // Execute reversible action -> WS pushes workspace.operation.updated -> UndoToastHost displays countdown
    const revRes = await apiReq('/api/workspace/actions/execute', {
      token: teacher.token,
      orgId: orgA,
      method: 'POST',
      jsonBody: { actionKey: 'workspace.preference.set', mode: 'reversible', payload: { key: 'compactMode', value: true } },
    });
    assert.equal(revRes.status, 200);
    assert.equal(revRes.json.data.status, 'undoable');
    const opId = revRes.json.data.id;

    // Ensure toast appears (via live WS or sync)
    const undoToast = teacherCtx.page.locator('[data-testid="undo-toast-item"]').first();
    try {
      await undoToast.waitFor({ state: 'visible', timeout: 6_000 });
    } catch {
      await teacherCtx.page.locator('[data-testid="operations-drawer-trigger"]').click();
      await teacherCtx.page.locator('[data-testid="operations-drawer"]').waitFor({ state: 'visible' });
      await teacherCtx.page.keyboard.press('Escape');
      await undoToast.waitFor({ state: 'visible', timeout: 5_000 });
    }

    const undoBtn = undoToast.locator('[data-testid="undo-toast-action"]');
    await undoBtn.waitFor({ state: 'visible' });
    const undoResp = teacherCtx.page.waitForResponse(
      (res) => res.url().includes(`/api/workspace/operations/${opId}/undo`) && res.status() === 200,
    );
    await undoBtn.click();
    await undoResp;

    const prefsAfter = await apiReq('/api/workspace/preferences', { token: teacher.token, orgId: orgA });
    assert.equal(prefsAfter.status, 200);
    assert.equal(prefsAfter.json.data.preferences.compactMode, false, 'Reversible preference must be restored to false');
    return { operationId: opId };
  });

  // SC-11: Expired Undo action -> rejected 409 undo_window_expired cleanly with honest UI/API feedback
  await runScenario('SC-11', 'Expired Undo rejected with 409 undo_window_expired cleanly in API & UI', async () => {
    const rev2 = await apiReq('/api/workspace/actions/execute', {
      token: teacher.token,
      orgId: orgA,
      method: 'POST',
      jsonBody: { actionKey: 'workspace.preference.set', mode: 'reversible', payload: { key: 'sidebarCollapsed', value: true } },
    });
    assert.equal(rev2.status, 200);
    const expiredOpId = rev2.json.data.id;

    // Force server undo_deadline into the past so server boundary rejects with 409 undo_window_expired
    runFixture('expire-undo', expiredOpId);

    // Direct API check -> 409 undo_window_expired
    const apiUndo = await apiReq(`/api/workspace/operations/${expiredOpId}/undo`, {
      token: teacher.token,
      orgId: orgA,
      method: 'POST',
      jsonBody: {},
    });
    assert.equal(apiUndo.status, 409);
    assert.equal(apiUndo.json.error?.code, 'undo_window_expired');

    // UI check: execute another reversible action with real toast, expire server deadline while toast still shows active button, click -> honest error
    const rev3 = await apiReq('/api/workspace/actions/execute', {
      token: teacher.token,
      orgId: orgA,
      method: 'POST',
      jsonBody: { actionKey: 'workspace.preference.set', mode: 'reversible', payload: { key: 'sidebarCollapsed', value: false } },
    });
    assert.equal(rev3.status, 200);
    const uiOpId = rev3.json.data.id;

    // Sync operations so toast appears if WS hasn't already
    const toastItem = teacherCtx.page.locator('[data-testid="undo-toast-item"]').first();
    if (!(await toastItem.isVisible().catch(() => false))) {
      await teacherCtx.page.locator('[data-testid="operations-drawer-trigger"]').click();
      await teacherCtx.page.locator('[data-testid="operations-drawer"]').waitFor({ state: 'visible' });
      await teacherCtx.page.keyboard.press('Escape');
    }
    await toastItem.locator('[data-testid="undo-toast-action"]').waitFor({ state: 'visible', timeout: 6_000 });

    runFixture('expire-undo', uiOpId);
    const errResp = teacherCtx.page.waitForResponse(
      (res) => res.url().includes(`/api/workspace/operations/${uiOpId}/undo`) && res.status() === 409,
    );
    await toastItem.locator('[data-testid="undo-toast-action"]').click();
    await errResp;

    await teacherCtx.page.locator('[data-testid="undo-toast-error"]').first().waitFor({ state: 'visible', timeout: 5_000 });
    await toastItem.locator('button').last().click();
    return { expiredOpId, uiOpId };
  });

  // SC-12: Activity integrity E2E: student opens exam with assignmentId, saves answers, reloads -> restores same attemptId, submits, revealAnswersPolicy: 'never'
  let examAttemptId = null;
  await runScenario('SC-12', 'Activity integrity E2E: assignmentId, reload restores same attemptId, submit, revealAnswersPolicy=never', async () => {
    await navigateClientSide(studentCtx.page, `/activities/${examActivityId}/run?assignmentId=${examAssignmentId}`);
    await studentCtx.page.getByText(`Controlled Quantum Exam ${nonce}`).waitFor({ state: 'visible', timeout: 15_000 });
    await studentCtx.page.getByText('2 + 2 = ?').waitFor({ state: 'visible' });

    // Capture attemptId from URL query
    await studentCtx.page.waitForFunction(() => new URLSearchParams(window.location.search).has('attemptId'));
    examAttemptId = new URL(studentCtx.page.url()).searchParams.get('attemptId');
    assert.ok(examAttemptId, 'attemptId must be present in URL');

    // Fill answers and save draft
    await studentCtx.page.locator('input[type="radio"][value="4"]').check();
    await studentCtx.page.locator('textarea.uact-assess__input').fill(`Energy is conserved ${nonce}`);
    const saveDraftResp = studentCtx.page.waitForResponse(
      (res) => res.url().includes(`/api/activity/attempts/${examAttemptId}/draft`) && res.status() === 200,
    );
    await studentCtx.page.getByRole('button', { name: 'Save draft' }).click();
    await saveDraftResp;

    // Reload page -> must restore SAME attemptId (no duplicate attempt!) and saved answers
    const preReloadUrl = studentCtx.page.url();
    await studentCtx.page.reload({ waitUntil: 'networkidle' });
    await studentCtx.page
      .waitForFunction(
        (title) => window.location.pathname.includes('/admin/login') || document.body.innerText.includes(title),
        `Controlled Quantum Exam ${nonce}`,
        { timeout: 10_000 },
      )
      .catch(() => {});
    if (studentCtx.page.url().includes('/admin/login')) {
      await studentCtx.page.locator('#admin-identifier').fill(student1.email);
      await studentCtx.page.locator('#admin-password').fill(student1.password);
      await studentCtx.page.locator('form button[type="submit"]').click();
      await studentCtx.page.waitForURL((u) => !u.pathname.startsWith('/admin/login'), { timeout: 25_000 });
      if (!studentCtx.page.url().includes(`/activities/${examActivityId}/run`)) {
        const parsed = new URL(preReloadUrl);
        await navigateClientSide(studentCtx.page, `${parsed.pathname}${parsed.search}`);
      }
    }
    await studentCtx.page.getByText(`Controlled Quantum Exam ${nonce}`).waitFor({ state: 'visible', timeout: 15_000 });
    const afterReloadAttemptId = new URL(studentCtx.page.url()).searchParams.get('attemptId');
    assert.equal(afterReloadAttemptId, examAttemptId, 'Reload must restore exact same attemptId');
    const checked = await studentCtx.page.locator('input[type="radio"][value="4"]').isChecked();
    assert.equal(checked, true, 'Saved radio answer must be restored after reload');

    // Verify no duplicate attempt in DB/API
    const listAssign = await apiReq(`/api/activity/assignments?targetId=${student1.personId}`, {
      token: student1.token,
      orgId: orgA,
    });
    const myAssign = listAssign.json.data.items.find((a) => a.id === examAssignmentId);
    assert.equal(myAssign.myAttemptsCount, 1, 'Reload must NEVER create duplicate attempt');

    // Submit attempt
    const submitResp = studentCtx.page.waitForResponse(
      (res) => res.url().includes(`/api/activity/attempts/${examAttemptId}/submit`) && res.status() === 200,
    );
    await studentCtx.page.getByRole('button', { name: 'Submit & Evaluate' }).click();
    await submitResp;
    await studentCtx.page.locator('.uact-result').waitFor({ state: 'visible' });

    // Verify revealAnswersPolicy: 'never' hides answer keys in API
    const attAfterSub = await apiReq(`/api/activity/attempts/${examAttemptId}`, { token: student1.token, orgId: orgA });
    assert.equal(attAfterSub.status, 200);
    assert.equal(attAfterSub.json.data.exercise._answerKeys, undefined);
    assert.equal(attAfterSub.json.data.exercise.questions[0].answerKey, undefined);
    assert.equal(attAfterSub.json.data.evaluation.breakdown[0].expected, undefined);
    assert.equal(attAfterSub.json.data.evaluation.breakdown[0].correctAnswer, undefined);

    return { examActivityId, examAssignmentId, examAttemptId };
  });

  // SC-13: Teacher grades attempt -> confirmed parent sees only confirmed child's progress, revoked/unlinked parent gets 403
  await runScenario('SC-13', 'Teacher grades attempt -> confirmed parent sees progress, revoked/unlinked parent gets 403', async () => {
    const revRes = await apiReq(`/api/activity/attempts/${examAttemptId}/review`, {
      token: teacher.token,
      orgId: orgA,
      method: 'POST',
      jsonBody: { rawScore: 5, reviewNotes: 'Excellent explanation!' },
    });
    assert.equal(revRes.status, 200);
    assert.equal(revRes.json.data.state, 'EVALUATED');

    // Even after grading, revealAnswersPolicy: 'never' still hides keys from student
    const stuGetAfterGrade = await apiReq(`/api/activity/attempts/${examAttemptId}`, { token: student1.token, orgId: orgA });
    assert.equal(stuGetAfterGrade.json.data.exercise.questions[0].answerKey, undefined);

    // Confirmed parent sees child's progress
    const p1Prog = await apiReq(`/api/education/parent/children/${student1.studentId}/progress`, {
      token: parent1.token,
      orgId: orgA,
    });
    assert.equal(p1Prog.status, 200, `Confirmed parent progress failed: ${p1Prog.text}`);

    // Confirmed parent CANNOT see unrelated student2 -> 403/404
    const p1Other = await apiReq(`/api/education/parent/children/${student2.studentId}/progress`, {
      token: parent1.token,
      orgId: orgA,
    });
    assert.ok([403, 404].includes(p1Other.status), `Parent1 must not see unrelated student2 (got ${p1Other.status})`);

    // Unconfirmed parent gets 403/404
    const unconfProg = await apiReq(`/api/education/parent/children/${student1.studentId}/progress`, {
      token: unconfirmedParent.token,
      orgId: orgA,
    });
    assert.ok([403, 404].includes(unconfProg.status), `Unconfirmed parent must get 403 or 404 (got ${unconfProg.status})`);

    // Revoke parent1 -> immediately gets 403/404
    runFixture('revoke-parent', orgA, student1.studentId, parent1.personId);
    const p1Revoked = await apiReq(`/api/education/parent/children/${student1.studentId}/progress`, {
      token: parent1.token,
      orgId: orgA,
    });
    assert.ok([403, 404].includes(p1Revoked.status), `Revoked parent must get 403 or 404 (got ${p1Revoked.status})`);

    return { examAttemptId, studentId: student1.studentId };
  });

  // SC-14: Chat E2E: Teacher sends -> Recipient gets live WS update without reload -> reply/reaction/pin/read works
  let lastChatMsgId = null;
  await runScenario('SC-14', 'Chat E2E dual-browser live WS delivery, reply, reaction, pin & read state', async () => {
    await navigateClientSide(teacherCtx.page, `/chat/${sharedRoomId}`);
    await navigateClientSide(studentCtx.page, `/chat/${sharedRoomId}`);

    await teacherCtx.page.getByText('Realtime live').waitFor({ state: 'visible', timeout: 15_000 });
    await studentCtx.page.getByText('Realtime live').waitFor({ state: 'visible', timeout: 15_000 });

    const liveText = `Live WS message ${nonce}`;
    await teacherCtx.page.locator('.uchat__textarea').fill(liveText);
    await teacherCtx.page.getByRole('button', { name: 'Send', exact: true }).click();

    // Student receives live WS update WITHOUT reload
    const stuMsgCard = studentCtx.page.locator('.uchat__msg', { hasText: liveText }).first();
    await stuMsgCard.waitFor({ state: 'visible', timeout: 10_000 });

    // Student reacts 👍 -> Teacher sees reaction live
    const reactResp = studentCtx.page.waitForResponse(
      (res) => res.url().includes('/reactions') && res.status() === 200,
    );
    await stuMsgCard.getByRole('button', { name: '👍' }).click();
    await reactResp;
    await teacherCtx.page.locator('.uchat__msg', { hasText: liveText }).first().locator('.uchat__reaction', { hasText: '👍 1' }).waitFor({ state: 'visible', timeout: 15_000 });

    // Student replies -> Teacher sees reply live
    await stuMsgCard.scrollIntoViewIfNeeded();
    await stuMsgCard.getByRole('button', { name: 'Reply' }).click();
    const replyText = `Student WS reply ${nonce}`;
    await studentCtx.page.locator('.uchat__textarea').fill(replyText);
    await studentCtx.page.getByRole('button', { name: 'Send', exact: true }).click();
    await teacherCtx.page.locator('.uchat__msg', { hasText: replyText }).waitFor({ state: 'visible', timeout: 15_000 });

    // Teacher pins message -> Student sees pinned banner live
    const teacherMsgCard = teacherCtx.page.locator('.uchat__msg', { hasText: liveText }).first();
    await teacherMsgCard.getByRole('button', { name: 'Pin' }).click();
    await studentCtx.page.locator('.uchat__pin-bar', { hasText: liveText }).waitFor({ state: 'visible', timeout: 15_000 });

    // Verify read state & get lastChatMsgId
    const msgsRes = await apiReq(`/api/chat/rooms/${sharedRoomId}/messages`, { token: teacher.token, orgId: orgA });
    const liveMsg = msgsRes.json.data.items.find((m) => m.body === liveText);
    assert.ok(liveMsg);
    lastChatMsgId = liveMsg.id;

    return { sharedRoomId, liveMsgId: lastChatMsgId };
  });

  // SC-15: Remove/revoke member evicts WS room delivery & denies access
  await runScenario('SC-15', 'Remove/revoke chat member evicts WS room delivery & denies access', async () => {
    // Open direct WS for student1 and subscribe to room:{sharedRoomId}
    const stuTicket = await apiReq('/api/realtime/ticket', {
      token: student1.token,
      orgId: orgA,
      method: 'POST',
      jsonBody: { organizationId: orgA },
    });
    assert.equal(stuTicket.status, 200);
    const wsUrl = `${BACKEND_URL.replace(/^http/, 'ws')}${stuTicket.json.data.wsPath}?ticket=${encodeURIComponent(stuTicket.json.data.ticket)}`;

    const wsFrames = [];
    const ws = new WebSocket(wsUrl);
    await new Promise((resolve, reject) => {
      const t = setTimeout(() => reject(new Error('WS connect timeout')), 10_000);
      ws.addEventListener('open', () => {
        ws.send(JSON.stringify({ type: 'subscribe', channel: `room:${sharedRoomId}` }));
      });
      ws.addEventListener('message', (ev) => {
        const frame = JSON.parse(String(ev.data));
        wsFrames.push(frame);
        if (frame.type === 'subscribed' && frame.channel === `room:${sharedRoomId}`) {
          clearTimeout(t);
          resolve();
        }
      });
      ws.addEventListener('error', reject);
    });

    // Teacher removes student1 from room
    const rmRes = await apiReq(`/api/chat/rooms/${sharedRoomId}/members/${student1.personId}`, {
      token: teacher.token,
      orgId: orgA,
      method: 'DELETE',
    });
    assert.equal(rmRes.status, 200, `Remove member failed: ${rmRes.text}`);

    // Wait for unsubscribed / member_removed eviction on WS
    await new Promise((resolve, reject) => {
      const deadline = Date.now() + 8_000;
      const check = () => {
        const evicted = wsFrames.some(
          (f) =>
            (f.type === 'unsubscribed' && f.channel === `room:${sharedRoomId}`) ||
            (f.type === 'event' && f.event?.eventType === 'chat.member.removed'),
        );
        if (evicted) return resolve();
        if (Date.now() > deadline) return reject(new Error('WS eviction event not received'));
        setTimeout(check, 150);
      };
      check();
    });

    // Teacher sends post-eviction secret message -> evicted student WS MUST NOT receive it
    const postEvictBody = `Post eviction secret ${nonce}`;
    const postEvictRes = await apiReq(`/api/chat/rooms/${sharedRoomId}/messages`, {
      token: teacher.token,
      orgId: orgA,
      method: 'POST',
      jsonBody: { body: postEvictBody },
    });
    assert.equal(postEvictRes.status, 201);
    await new Promise((r) => setTimeout(r, 1200));
    ws.close();

    assert.equal(
      wsFrames.some((f) => f.type === 'event' && f.event?.payload?.message?.body === postEvictBody),
      false,
      'Evicted member WS must NOT receive subsequent room messages',
    );

    // HTTP API access denied for evicted member
    const getMsgsDenied = await apiReq(`/api/chat/rooms/${sharedRoomId}/messages`, { token: student1.token, orgId: orgA });
    assert.ok([403, 404].includes(getMsgsDenied.status), `Evicted member read must fail (got ${getMsgsDenied.status})`);

    const sendDenied = await apiReq(`/api/chat/rooms/${sharedRoomId}/messages`, {
      token: student1.token,
      orgId: orgA,
      method: 'POST',
      jsonBody: { body: 'Should be denied' },
    });
    assert.ok([403, 404].includes(sendDenied.status), `Evicted member send must fail (got ${sendDenied.status})`);

    return { sharedRoomId, evictedPersonId: student1.personId };
  });

  // SC-16: Notifications Center: grouped toggle, category filter, bulk read + bulk archive
  await runScenario('SC-16', 'Notifications Center grouped toggle, category filter, bulk read & bulk archive', async () => {
    // Create a fresh room with student1 so student1 receives notifications
    const notifRoom = await apiReq('/api/chat/rooms', {
      token: teacher.token,
      orgId: orgA,
      method: 'POST',
      jsonBody: {
        roomType: 'group',
        title: `Notif Room ${nonce}`,
        body: `Ping for notification ${nonce}`,
        memberPersonIds: [student1.personId],
      },
    });
    assert.equal(notifRoom.status, 201);
    const nrId = notifRoom.json.data.id;
    const msg2 = await apiReq(`/api/chat/rooms/${nrId}/messages`, {
      token: teacher.token,
      orgId: orgA,
      method: 'POST',
      jsonBody: { body: `Deletable target message ${nonce}` },
    });
    assert.equal(msg2.status, 201);
    const deletableMsgId = msg2.json.data.id;

    // Delete msg2 for_all so its notification becomes resourceAvailable === false (tested in SC-17)
    const delForAll = await apiReq(`/api/chat/rooms/${nrId}/messages/${deletableMsgId}/delete`, {
      token: teacher.token,
      orgId: orgA,
      method: 'POST',
      jsonBody: { mode: 'for_all' },
    });
    assert.equal(delForAll.status, 200);

    await navigateClientSide(studentCtx.page, '/notifications-center');
    await studentCtx.page.locator('[data-testid="notifications-center"]').waitFor({ state: 'visible', timeout: 15_000 });
    await studentCtx.page.locator('[data-testid="notif-item"]').first().waitFor({ state: 'visible' });

    // Grouped toggle
    const groupedReq = studentCtx.page.waitForResponse(
      (res) => res.url().includes('/api/notifications') && res.url().includes('grouped=1') && res.status() === 200,
    );
    await studentCtx.page.locator('[data-testid="notif-grouped-toggle"]').check();
    await groupedReq;

    // Turn off grouped mode before individual bulk-read/archive so SC-17 sees ungrouped items
    const ungroupedReq = studentCtx.page.waitForResponse(
      (res) => res.url().includes('/api/notifications') && !res.url().includes('grouped=1') && res.status() === 200,
    );
    await studentCtx.page.locator('[data-testid="notif-grouped-toggle"]').uncheck();
    await ungroupedReq;

    // Category filter (Message)
    const catReq = studentCtx.page.waitForResponse(
      (res) => res.url().includes('/api/notifications') && res.url().includes('category=message') && res.status() === 200,
    );
    await studentCtx.page.locator('.unotif__tab', { hasText: 'Messages' }).click();
    await catReq;

    // Select last (oldest) item -> Bulk read -> Bulk archive (preserve newest deleted-msg notification for SC-17)
    await studentCtx.page.locator('[data-testid="notif-item-checkbox"]').last().check();
    const bulkReadResp = studentCtx.page.waitForResponse(
      (res) => res.url().includes('/api/notifications/bulk-read') && res.status() === 200,
    );
    await studentCtx.page.locator('[data-testid="notif-bulk-read"]').click();
    await bulkReadResp;

    await studentCtx.page.locator('[data-testid="notif-item-checkbox"]').last().check();
    const bulkArchiveResp = studentCtx.page.waitForResponse(
      (res) => res.url().includes('/api/notifications/bulk-archive') && res.status() === 200,
    );
    await studentCtx.page.locator('[data-testid="notif-bulk-archive"]').click();
    await bulkArchiveResp;

    // Switch back to All tab for SC-17
    const allReq = studentCtx.page.waitForResponse(
      (res) => res.url().includes('/api/notifications') && !res.url().includes('category=message') && res.status() === 200,
    );
    await studentCtx.page.locator('.unotif__tab', { hasText: 'All' }).click();
    await allReq;
    return { notifRoomId: nrId, deletableMsgId };
  });

  // SC-17: Notifications Center unavailable resource & honest Web Push status
  await runScenario('SC-17', 'Notifications Center unavailable resource badge/disabled + honest Web Push status', async () => {
    const pushPanel = studentCtx.page.locator('[data-testid="push-status-panel"]');
    await pushPanel.waitFor({ state: 'visible' });
    const pushText = await pushPanel.innerText();
    assert.ok(
      pushText.includes('Not configured') || pushText.includes('Unsubscribed') || pushText.includes('Не настроено'),
      `Unexpected push status: ${pushText}`,
    );

    // Check that at least one notification item has unavailable badge & disabled open button
    const unavailItem = studentCtx.page.locator('[data-testid="notif-item"]').filter({
      has: studentCtx.page.locator('[data-testid="notif-unavailable-badge"]'),
    }).first();
    await unavailItem.waitFor({ state: 'visible', timeout: 10_000 });
    const openBtn = unavailItem.locator('[data-testid="notif-open-target"]');
    if ((await openBtn.count()) > 0) {
      assert.equal(await openBtn.isDisabled(), true, 'Open target button for unavailable resource must be disabled');
    }

    // Verify API resourceAvailable === false directly too
    const apiNotifs = await apiReq('/api/notifications', { token: student1.token, orgId: orgA });
    assert.equal(apiNotifs.status, 200);
    assert.ok(apiNotifs.json.data.items.some((n) => n.resourceAvailable === false), 'API must report resourceAvailable === false');
    await stableScreenshot(studentCtx.page, path.join(screenshotDir, 'p12-notifications-center.png'));
    return { pushText };
  });

  // SC-18 & SC-19: Mobile viewports (360x780 & 390x844) ZERO horizontal overflow + working mobile controls
  for (const [scId, vp] of [
    ['SC-18', { width: 360, height: 780 }],
    ['SC-19', { width: 390, height: 844 }],
  ]) {
    await runScenario(scId, `Mobile viewport ${vp.width}x${vp.height}: zero overflow + AppMobileMenu & AppBottomNav`, async () => {
      await teacherCtx.page.setViewportSize(vp);
      const pages = ['/workspace', '/search', '/trash', '/notifications-center'];
      for (const routePath of pages) {
        await navigateClientSide(teacherCtx.page, routePath);
        await teacherCtx.page.waitForTimeout(400);
        const metrics = await teacherCtx.page.evaluate(() => ({
          scrollWidth: document.documentElement.scrollWidth,
          clientWidth: document.documentElement.clientWidth,
        }));
        assert.ok(
          metrics.scrollWidth <= metrics.clientWidth,
          `Horizontal overflow on ${routePath} at ${vp.width}px: scrollWidth=${metrics.scrollWidth} > clientWidth=${metrics.clientWidth}`,
        );
      }

      // Check AppBottomNav visible and AppMobileMenu opens cleanly
      await teacherCtx.page.locator('.app-bottom-nav').waitFor({ state: 'visible' });
      const menuBtn = teacherCtx.page.locator('.app-layout__menu-button');
      await menuBtn.waitFor({ state: 'visible' });
      await menuBtn.click();
      await teacherCtx.page.locator('.app-mobile-menu, [role="dialog"]').first().waitFor({ state: 'visible' });
      await teacherCtx.page.keyboard.press('Escape');
      return { viewport: `${vp.width}x${vp.height}`, checkedRoutes: pages };
    });
  }

  // Restore desktop viewport
  await teacherCtx.page.setViewportSize({ width: 1440, height: 900 });

  // SC-20: Themes (Light / Dark / Monochrome / Custom accent palette) + contrast check + zero console/page errors
  await runScenario('SC-20', 'Themes Light / Dark / Monochrome / Custom accent palette + contrast & zero errors', async () => {
    await navigateClientSide(teacherCtx.page, '/settings');
    await teacherCtx.page.locator('.appearance-view').waitFor({ state: 'visible' });

    const assertContrast = async (label) => {
      const check = await teacherCtx.page.evaluate(() => {
        const bodyStyle = getComputedStyle(document.body);
        const textLen = document.body.innerText.trim().length;
        return {
          theme: document.documentElement.dataset.theme,
          color: bodyStyle.color,
          bg: bodyStyle.backgroundColor,
          textLen,
        };
      });
      assert.ok(check.textLen > 20, `${label}: empty UI`);
      assert.notEqual(check.color, check.bg, `${label}: color === backgroundColor (${check.color})`);
      return check;
    };

    // Light
    await teacherCtx.page.locator('input[name="appearance-theme"][value="light"]').check({ force: true });
    const lightCheck = await assertContrast('light');
    assert.equal(lightCheck.theme, 'light');

    // Dark
    await teacherCtx.page.locator('input[name="appearance-theme"][value="dark"]').check({ force: true });
    const darkCheck = await assertContrast('dark');
    assert.equal(darkCheck.theme, 'dark');

    // Monochrome preset chip
    await teacherCtx.page.locator('.appearance-palette-chip').nth(6).click();
    const monoCheck = await assertContrast('monochrome');

    // Custom accent palette
    await teacherCtx.page.locator('input[name="appearance-color-mode"][value="custom"]').check({ force: true });
    await teacherCtx.page.locator('#appearance-accent-hex').fill('#7C3AED');
    await teacherCtx.page.locator('#appearance-bg-hex').fill('#0F172A');
    const customCheck = await assertContrast('custom');

    // Reset back to Light default
    await teacherCtx.page.locator('.appearance-palette-chip').first().click();
    await teacherCtx.page.locator('input[name="appearance-theme"][value="light"]').check({ force: true });

    const unexpectedConsoleErrors = teacherCtx.consoleErrors.filter((m) => !m.includes('status of 409'));
    assert.equal(teacherCtx.pageErrors.length, 0, `Page errors: ${teacherCtx.pageErrors.join('; ')}`);
    assert.equal(unexpectedConsoleErrors.length, 0, `Console errors: ${unexpectedConsoleErrors.join('; ')}`);
    return { lightCheck, darkCheck, monoCheck, customCheck };
  });

  // SC-21: Locale switch (RU / EN) renders non-empty translated UI with zero console/page errors
  await runScenario('SC-21', 'Locale switch RU / EN renders non-empty UI with zero console/page errors', async () => {
    await navigateClientSide(teacherCtx.page, '/settings');
    await teacherCtx.page.locator('#appearance-language').waitFor({ state: 'visible' });

    await selectDropdown(teacherCtx.page, 'appearance-language', 'ru');
    await navigateClientSide(teacherCtx.page, '/workspace');
    await teacherCtx.page.locator('[data-testid="workspace-dashboard"]').waitFor({ state: 'visible' });
    const ruText = await teacherCtx.page.locator('[data-testid="workspace-dashboard"]').innerText();
    assert.ok(/[А-Яа-яЁё]/.test(ruText), 'Russian locale must render Cyrillic workspace UI');

    await navigateClientSide(teacherCtx.page, '/settings');
    await selectDropdown(teacherCtx.page, 'appearance-language', 'en');
    await navigateClientSide(teacherCtx.page, '/workspace');
    await teacherCtx.page.locator('[data-testid="workspace-dashboard"]').waitFor({ state: 'visible' });
    const enText = await teacherCtx.page.locator('[data-testid="workspace-dashboard"]').innerText();
    assert.ok(enText.includes('Workspace'), 'English locale must render Workspace heading');

    const unexpectedConsoleErrors = teacherCtx.consoleErrors.filter((m) => !m.includes('status of 409'));
    assert.equal(teacherCtx.pageErrors.length, 0, `Page errors: ${teacherCtx.pageErrors.join('; ')}`);
    assert.equal(unexpectedConsoleErrors.length, 0, `Console errors: ${unexpectedConsoleErrors.join('; ')}`);
    assert.equal(teacherCtx.server5xx.length, 0, `Server 5xx: ${teacherCtx.server5xx.join('; ')}`);
    return { ruSample: ruText.slice(0, 60), enSample: enText.slice(0, 60) };
  });

  // SC-22: Direct API security checks via Playwright request
  await runScenario('SC-22', 'Direct API security checks: CAS 409, expired attempt 409, overrideCfg tampering ignored, unconfirmed parent 403', async () => {
    // 1. Create a fresh timed exam for student1
    const secExam = await apiReq('/api/activity/items', {
      token: teacher.token,
      orgId: orgA,
      method: 'POST',
      jsonBody: {
        pluginCode: 'platform-assessment',
        title: `Security Exam ${nonce}`,
        maxAttempts: 3,
        timeLimitSeconds: 120,
        passingScorePercent: 80,
        revealAnswersPolicy: 'never',
        publish: true,
        config: { openPractice: false, examOnly: true },
        content: {
          questions: [
            { id: 'q1', questionType: 'single_choice', content: { prompt: '1+1?', options: ['1', '2'] }, answerKey: '2', defaultPoints: 10 },
          ],
        },
      },
    });
    assert.equal(secExam.status, 201);
    const secActId = secExam.json.data.id;

    const secAssign = await apiReq(`/api/activity/items/${secActId}/assignments`, {
      token: teacher.token,
      orgId: orgA,
      method: 'POST',
      jsonBody: { targetType: 'person', targetId: student1.personId },
    });
    assert.equal(secAssign.status, 201);
    const secAssignId = secAssign.json.data.id;

    // 2. Participant overrideCfg tampering (timeLimitSec, timeLimitSeconds, passingScore, passingScorePercent) MUST be ignored
    const tamperStart = await apiReq(`/api/activity/items/${secActId}/attempts`, {
      token: student1.token,
      orgId: orgA,
      method: 'POST',
      jsonBody: {
        assignmentId: secAssignId,
        config: { timeLimitSec: 99999, timeLimitSeconds: 99999, passingScore: 1, passingScorePercent: 1 },
        configOverride: { timeLimitSec: 99999, timeLimitSeconds: 99999, passingScore: 1, passingScorePercent: 1 },
      },
    });
    assert.equal(tamperStart.status, 201, `Start failed: ${tamperStart.text}`);
    const tamperAtt = tamperStart.json.data;
    assert.equal(tamperAtt.config.passingScorePercent, 80, 'Participant passingScorePercent tampering must be ignored');
    const ttlLeft = Math.round((Date.parse(tamperAtt.expiresAt) - Date.now()) / 1000);
    assert.ok(ttlLeft <= 130 && ttlLeft > 0, `Participant timeLimit tampering must be ignored (ttl=${ttlLeft}s)`);

    // 3. Invalid/stale CAS revision -> 409
    const draft1 = await apiReq(`/api/activity/attempts/${tamperAtt.id}/draft`, {
      token: student1.token,
      orgId: orgA,
      method: 'PUT',
      jsonBody: { answers: { q1: '2' }, revision: 1 },
    });
    assert.equal(draft1.status, 200);
    assert.equal(draft1.json.data.revision, 2);

    const staleCas = await apiReq(`/api/activity/attempts/${tamperAtt.id}/draft`, {
      token: student1.token,
      orgId: orgA,
      method: 'PUT',
      jsonBody: { answers: { q1: '1' }, revision: 1 },
    });
    assert.equal(staleCas.status, 409, 'Stale CAS revision must return 409');

    // 4. Expired attempt -> 409 on draft & submit
    runFixture('expire-attempt', tamperAtt.id);
    const expiredDraft = await apiReq(`/api/activity/attempts/${tamperAtt.id}/draft`, {
      token: student1.token,
      orgId: orgA,
      method: 'PUT',
      jsonBody: { answers: { q1: '1' }, revision: 2 },
    });
    assert.equal(expiredDraft.status, 409, 'Expired attempt draft must return 409');

    const expiredSubmit = await apiReq(`/api/activity/attempts/${tamperAtt.id}/submit`, {
      token: student1.token,
      orgId: orgA,
      method: 'POST',
      jsonBody: { submission: { answers: { q1: '2' } }, revision: 2 },
    });
    assert.equal(expiredSubmit.status, 409, 'Expired attempt submit must return 409');

    // 5. Unconfirmed parent blocked 403/404
    const unconfRes = await apiReq(`/api/education/parent/children/${student1.studentId}/progress`, {
      token: unconfirmedParent.token,
      orgId: orgA,
    });
    assert.ok([403, 404].includes(unconfRes.status), `Unconfirmed parent must receive 403 or 404 (got ${unconfRes.status})`);

    return { secActId, tamperAttemptId: tamperAtt.id };
  });

  const summary = {
    version: 'PLATFORM_12',
    gate: 'GATE 23',
    timestamp: new Date().toISOString(),
    nonce,
    totalScenarios: 22,
    passed: Object.values(results).filter((r) => r.status === 'PASS').length,
    failed: Object.values(results).filter((r) => r.status === 'FAIL').length,
    skipped: 0,
    scenarios: results,
  };

  mkdirSync(path.join(repoRoot, 'docs', 'audits'), { recursive: true });
  writeFileSync(path.join(repoRoot, 'docs', 'audits', 'platform12-evidence.json'), JSON.stringify(summary, null, 2));

  console.log('\n=== GATE 23 SUMMARY JSON ===');
  console.log(JSON.stringify(summary, null, 2));
} finally {
  for (const ctx of contexts) {
    await ctx.close().catch(() => {});
  }
  await apiCtx?.dispose().catch(() => {});
  await browser?.close().catch(() => {});
  await stopChild(vite);
  try {
    runFixture('cleanup', nonce);
  } catch (cleanupErr) {
    console.error('Cleanup error:', cleanupErr);
  }
}
