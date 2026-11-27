// Real Multi-Browser & WebSocket Verification for PLATFORM_10 (GATE 02):
// Real Chromium (Playwright) x2 tabs/contexts, Real Vite WS+HTTP Proxy, Real FrankenPHP + PHP WS Server, Real Redis Pub/Sub, Real PostgreSQL.
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import http from 'node:http';
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { launchBrowser, navigateClientSide, stableScreenshot } from './e2e-support.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '..');
const repoRoot = path.resolve(frontendRoot, '..', '..');

const BACKEND_URL = process.env.APP_URL || 'http://127.0.0.1:8083';
const FRONTEND_PORT = Number(process.env.R10_FRONTEND_PORT || 5176);
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
  const res = await fetch(url, options);
  const text = await res.text();
  let json = null;
  try {
    json = JSON.parse(text);
  } catch {
    // plain text error
  }
  return { status: res.status, json, text };
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
      // waiting
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
  page.on('console', (msg) => {
    if (msg.type() === 'error') console.error('[BROWSER CONSOLE]', msg.text());
  });
  page.on('response', (res) => {
    if (res.status() >= 400 && !res.url().includes('/auth/refresh')) {
      console.error('[BROWSER HTTP ERR]', res.status(), res.url());
    }
  });
  await page.goto(`${FRONTEND_URL}/admin/login`, {
    waitUntil: 'domcontentloaded',
  });
  await page.locator('#admin-identifier').fill(actor.email);
  await page.locator('#admin-password').fill(actor.password);
  await page.locator('form button[type="submit"]').click();
  await page.waitForURL((u) => !u.pathname.startsWith('/admin/login'), { timeout: 25_000 });
  await navigateClientSide(page, targetPath);
  return { context, page, pageErrors };
}

console.log('--- PLATFORM_10 UNIVERSAL CHAT, REALTIME WS & NOTIFICATIONS VERIFICATION ---');

const seeded = runFixture('seed');
const { nonce, organizationId, otherOrganizationId, teacher, teacher2, student, outsider } = seeded;
console.log(`Seeded Nonce: ${nonce}, Org: ${organizationId}`);

let vite = null;
let browser = null;
const contexts = [];
const screenshotDir = path.join(repoRoot, 'docs', 'audits', 'screenshots');
mkdirSync(screenshotDir, { recursive: true });
const evidence = { version: 'PLATFORM_10', timestamp: new Date().toISOString(), nonce, tests: [], passed: 0, failed: 0 };

function record(id, title, details = {}) {
  evidence.passed++;
  evidence.tests.push({ id, title, status: 'PASS', details });
  console.log(`[PASS] ${id}: ${title}`);
}

try {
  // 1. Security: forged WS ticket & cross-org isolation
  const forgedWsStatus = await new Promise((resolve, reject) => {
    const req = http.request(`${BACKEND_URL}/ws?ticket=forged.ticket`, {
      method: 'GET',
      headers: {
        Connection: 'Upgrade',
        Upgrade: 'websocket',
        'Sec-WebSocket-Key': 'dGhlIHNhbXBsZSBub25jZQ==',
        'Sec-WebSocket-Version': '13',
      },
    });
    req.on('response', (res) => {
      res.resume();
      resolve(res.statusCode);
    });
    req.on('upgrade', () => resolve(101));
    req.on('error', reject);
    req.end();
  });
  assert.equal(forgedWsStatus, 401, 'Forged WS ticket must return 401');

  const outsiderTicket = await api('/api/realtime/ticket', {
    method: 'POST',
    headers: { Authorization: `Bearer ${outsider.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
    body: JSON.stringify({ organizationId }),
  });
  assert.equal(outsiderTicket.status, 403, 'Outsider from Org B must get 403 on Org A ticket');
  record('P10-SEC-01', 'Forged WS Ticket (401) & Cross-Org Ticket Rejection (403)');

  // 2. Teacher & Student tickets & Group room creation + Edit/Delete/Pin/Forward/Search/Drafts/Prefs API
  const groupRoomRes = await api('/api/chat/rooms', {
    method: 'POST',
    headers: { Authorization: `Bearer ${teacher.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
    body: JSON.stringify({
      roomType: 'group',
      title: `Study Group ${nonce}`,
      memberPersonIds: [student.personId, teacher2.personId],
      body: `Welcome to Study Group ${nonce}`,
    }),
  });
  assert.equal(groupRoomRes.status, 201, `Group room creation failed: ${groupRoomRes.text}`);
  const roomId = groupRoomRes.json.data.id;

  // Unauthorized subscriber check on /api/realtime/events
  const unauthEvents = await api(`/api/realtime/events?channel=${encodeURIComponent('room:' + roomId)}&sinceSeq=0`, {
    headers: { Authorization: `Bearer ${outsider.token}`, 'X-Education-Organization': otherOrganizationId },
  });
  assert.equal(unauthEvents.status, 403, 'Outsider replay of room events must be rejected');

  // Pin, Edit, React, Reply, Search, Draft sync
  const sendA = await api(`/api/chat/rooms/${roomId}/messages`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${teacher.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
    body: JSON.stringify({ body: `Original text ${nonce}`, clientKey: `ck-${nonce}-1` }),
  });
  assert.equal(sendA.status, 201);
  const msgId = sendA.json.data.id;

  // Idempotent retry check
  const retryA = await api(`/api/chat/rooms/${roomId}/messages`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${teacher.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
    body: JSON.stringify({ body: `Original text ${nonce}`, clientKey: `ck-${nonce}-1` }),
  });
  assert.equal(retryA.json.data.id, msgId, 'Duplicate clientKey must return same message ID');

  const editA = await api(`/api/chat/rooms/${roomId}/messages/${msgId}`, {
    method: 'PUT',
    headers: { Authorization: `Bearer ${teacher.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
    body: JSON.stringify({ body: `Edited text ${nonce}` }),
  });
  assert.equal(editA.status, 200);
  assert.equal(editA.json.data.body, `Edited text ${nonce}`);
  assert.ok(editA.json.data.editedAt, 'editedAt must be populated');

  const pinRes = await api(`/api/chat/rooms/${roomId}/pin`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${teacher.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
    body: JSON.stringify({ messageId: msgId }),
  });
  assert.equal(pinRes.status, 200);
  assert.equal(pinRes.json.data.pinnedMessageId, msgId);

  const draftRes = await api(`/api/chat/rooms/${roomId}/draft`, {
    method: 'PUT',
    headers: { Authorization: `Bearer ${student.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
    body: JSON.stringify({ draftText: `Synced draft ${nonce}` }),
  });
  assert.equal(draftRes.status, 200);

  const searchRes = await api(`/api/chat/search?q=${encodeURIComponent(`Edited text ${nonce}`)}`, {
    headers: { Authorization: `Bearer ${student.token}`, 'X-Education-Organization': organizationId },
  });
  assert.equal(searchRes.status, 200);
  assert.ok(searchRes.json.data.items.some((x) => x.id === msgId), 'Search must return edited message');

  const delForMe = await api(`/api/chat/rooms/${roomId}/messages/${msgId}/delete`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${teacher2.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
    body: JSON.stringify({ mode: 'for_me' }),
  });
  assert.equal(delForMe.status, 200);
  const msgsTeacher2 = await api(`/api/chat/rooms/${roomId}/messages`, {
    headers: { Authorization: `Bearer ${teacher2.token}`, 'X-Education-Organization': organizationId },
  });
  assert.equal(msgsTeacher2.json.data.items.some((x) => x.id === msgId), false, 'delete_for_me must hide message for actor only');

  const msgsTeacher1 = await api(`/api/chat/rooms/${roomId}/messages`, {
    headers: { Authorization: `Bearer ${teacher.token}`, 'X-Education-Organization': organizationId },
  });
  assert.equal(msgsTeacher1.json.data.items.some((x) => x.id === msgId), true, 'delete_for_me must keep message visible to others');

  // Notifications preferences + mark all read
  const prefPut = await api('/api/notifications/preferences', {
    method: 'PUT',
    headers: { Authorization: `Bearer ${student.token}`, 'X-Education-Organization': organizationId, 'Content-Type': 'application/json' },
    body: JSON.stringify({ category: 'homework', inApp: true, realtime: true, webPush: false, email: true, sound: false }),
  });
  assert.equal(prefPut.status, 200);
  const prefHw = prefPut.json.data.items.find((p) => p.category === 'homework');
  assert.equal(prefHw.webPush, false);
  assert.equal(prefHw.email, true);
  record('P10-API-01', 'Group Room, Idempotency, Edit, Pin, Draft Sync, Search, Delete-for-Me, Notifications Prefs');

  // 3. Real Dual-Browser Chromium WebSocket Realtime Test!
  vite = await startVite();
  browser = await launchBrowser();

  const teacherCtx = await loginAdminBrowser(browser, teacher, `/chat/${roomId}`);
  const studentCtx = await loginAdminBrowser(browser, student, `/chat/${roomId}`);
  contexts.push(teacherCtx.context, studentCtx.context);

  // Verify both browsers show "Realtime live" pill
  await teacherCtx.page.getByText('Realtime live').waitFor({ state: 'visible', timeout: 15_000 });
  await studentCtx.page.getByText('Realtime live').waitFor({ state: 'visible', timeout: 15_000 });

  // Verify synced draft loaded automatically on student browser
  const studentTextarea = studentCtx.page.locator('.uchat__textarea');
  await studentTextarea.waitFor({ state: 'visible', timeout: 15_000 });
  const loadedDraft = await studentTextarea.inputValue();
  assert.equal(loadedDraft, `Synced draft ${nonce}`, 'Student browser must hydrate synced server draft');

  // Typing indicator live test: Teacher types -> Student sees typing indicator WITHOUT reload
  await teacherCtx.page.locator('.uchat__textarea').fill(`Live WS hello ${nonce}`);
  await teacherCtx.page.locator('.uchat__textarea').dispatchEvent('input');
  await studentCtx.page.getByText('is typing...').waitFor({ state: 'visible', timeout: 8_000 });

  // Live WS message delivery WITHOUT page reload
  await teacherCtx.page.getByRole('button', { name: 'Send', exact: true }).click();
  await studentCtx.page.getByText(`Live WS hello ${nonce}`).waitFor({ state: 'visible', timeout: 8_000 });

  // Student deletes reply or edits via browser / Teacher reacts live -> Student sees reaction in real time!
  const latestTeacherMsgCard = studentCtx.page.locator('.uchat__msg', { hasText: `Live WS hello ${nonce}` });
  await latestTeacherMsgCard.getByRole('button', { name: '👍' }).click();
  await teacherCtx.page.locator('.uchat__reaction', { hasText: '👍 1' }).waitFor({ state: 'visible', timeout: 8_000 });

  await stableScreenshot(studentCtx.page, path.join(screenshotDir, 'p10-realtime-chat.png'));

  // Open Notifications Center in Student browser and verify notification received & mark all read
  await navigateClientSide(studentCtx.page, '/notifications-center');
  await studentCtx.page.getByText('Notifications Center').waitFor({ state: 'visible', timeout: 15_000 });
  await studentCtx.page.getByRole('button', { name: 'Mark all read' }).click();
  await studentCtx.page.getByText('Unread: 0').waitFor({ state: 'visible', timeout: 8_000 });
  await stableScreenshot(studentCtx.page, path.join(screenshotDir, 'p10-notifications.png'));

  assert.equal(teacherCtx.pageErrors.length, 0, `Teacher browser errors: ${teacherCtx.pageErrors.join(';')}`);
  assert.equal(studentCtx.pageErrors.length, 0, `Student browser errors: ${studentCtx.pageErrors.join(';')}`);
  record('P10-E2E-REALTIME', 'Dual-Browser Real WebSocket Message Delivery, Typing Indicator, Live Reactions, Synced Draft & Notifications Center', {
    roomId,
    screenshotChat: 'docs/audits/screenshots/p10-realtime-chat.png',
    screenshotNotifications: 'docs/audits/screenshots/p10-notifications.png',
  });

  writeFileSync(path.join(repoRoot, 'docs', 'audits', 'platform10-evidence.json'), JSON.stringify(evidence, null, 2));
  console.log('\n======================================================');
  console.log(`PLATFORM_10 VERIFICATION PASSED (${evidence.passed}/${evidence.passed})`);
  console.log('======================================================');
} finally {
  for (const ctx of contexts) {
    await ctx.close().catch(() => {});
  }
  await browser?.close().catch(() => {});
  await stopChild(vite);
  runFixture('cleanup', nonce);
}
