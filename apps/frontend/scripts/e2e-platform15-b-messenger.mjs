// PLATFORM_15_B Local Acceptance: Two-User Realtime Chat, Group Chat, Member Add/Remove,
// Attachments, Voice Message, Edit, Delete, Reconnect & Retry without Duplicates, Historical Migration Check.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { request as pwRequest } from 'playwright';
import { launchBrowser, navigateClientSide, selectDropdown } from './e2e-support.mjs';

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

async function apiReq(apiCtx, urlPath, { method = 'GET', token, orgId, jsonBody, rawBody, headers = {} } = {}) {
  const hdrs = { Accept: 'application/json', ...headers };
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
  return { status: res.status(), json, text };
}

async function loginBrowser(browser, actor, targetPath) {
  const context = await browser.newContext({ viewport: { width: 1366, height: 840 }, ignoreHTTPSErrors: true });
  context.setDefaultTimeout(30_000);
  await context.addInitScript(() => {
    localStorage.setItem('platform.locale', 'en');
    localStorage.setItem('platform.theme', 'light');
  });
  const page = await context.newPage();
  await page.goto(`${FRONTEND_URL}/admin/login`, { waitUntil: 'domcontentloaded' });
  await page.locator('#admin-identifier').fill(actor.email);
  await page.locator('#admin-password').fill(actor.password);
  await page.locator('form button[type="submit"]').click();
  await page.waitForURL((u) => !u.pathname.startsWith('/admin/login'), { timeout: 25_000 });
  if (targetPath) {
    await navigateClientSide(page, targetPath);
  }
  return { context, page };
}

async function uploadMedia(apiCtx, token, orgId, kind, name, mime, bytes) {
  const sha256 = createHash('sha256').update(bytes).digest('hex');
  const reserve = await apiReq(apiCtx, '/api/media/reserve', {
    method: 'POST',
    token,
    orgId,
    jsonBody: { kind, name, mime, sizeBytes: bytes.length, sha256 },
  });
  assert.ok(reserve.status === 200 || reserve.status === 201, `media reserve failed: ${reserve.text}`);
  const id = reserve.json.data.id;
  const uploadUrl = reserve.json.data.uploadUrl || `/api/media/upload/${id}`;
  const putRes = await apiReq(apiCtx, uploadUrl, {
    method: 'POST',
    token,
    orgId,
    headers: { 'Content-Type': mime, 'X-Media-Sha256': sha256 },
    rawBody: bytes,
  });
  assert.equal(putRes.status, 200, `media upload failed: ${putRes.text}`);
  if (kind === 'voice') {
    const confirmRes = await apiReq(apiCtx, `/api/media/${id}/confirm`, {
      method: 'POST',
      token,
      orgId,
      jsonBody: { durationSeconds: 2 },
    });
    assert.equal(confirmRes.status, 200, `media confirm failed: ${confirmRes.text}`);
  }
  return id;
}

async function main() {
  const fixture = runFixture('seed');
  const { nonce, orgA: orgId, teacher, student1, student2 } = fixture;
  assert.ok(orgId, 'Missing orgA in fixture');

  const apiCtx = await pwRequest.newContext({ baseURL: BACKEND_URL, ignoreHTTPSErrors: true });
  const browser = await launchBrowser();

  let teacherCtx = null;
  let studentCtx = null;

  try {
    // Create group chat via API with teacher + student1
    const groupRes = await apiReq(apiCtx, '/api/chat/rooms', {
      method: 'POST',
      token: teacher.token,
      orgId,
      jsonBody: {
        roomType: 'group',
        title: `P15B Group ${Date.now()}`,
        memberPersonIds: [student1.personId],
        body: 'Initial group message',
      },
    });
    assert.equal(groupRes.status, 201, `createRoom failed: ${groupRes.text}`);
    const groupRoom = groupRes.json.data;

    // 1. Two users in separate browser contexts: Teacher (/chat/:id) & Student (/student/messages/:id)
    teacherCtx = await loginBrowser(browser, teacher, `/chat/${groupRoom.id}`);
    const orgBar = teacherCtx.page.locator('.uchat-page__org-bar .app-dropdown__trigger, .uchat-page__org-bar button');
    if ((await orgBar.count()) > 0 && (await teacherCtx.page.getByText(groupRoom.title).count()) === 0) {
      await orgBar.first().click();
      const opt = teacherCtx.page.locator(`[data-value="${orgId}"], [role="option"]`).first();
      if ((await opt.count()) > 0) await opt.click();
    }

    studentCtx = await loginBrowser(browser, student1, `/student/messages/${groupRoom.id}`);

    await teacherCtx.page.locator('.uchat__msg-body').getByText('Initial group message').waitFor({ timeout: 15_000 });
    await studentCtx.page.locator('.uchat__msg-body').getByText('Initial group message').waitFor({ timeout: 15_000 });

    // 2. Real-time message from Teacher -> Student
    const liveMsgText = `Live P15B ${Date.now()}`;
    await teacherCtx.page.locator('.uchat__textarea').fill(liveMsgText);
    await teacherCtx.page.locator('.uchat__textarea').press('Enter');
    await studentCtx.page.locator('.uchat__msg-body').getByText(liveMsgText).waitFor({ timeout: 10_000 });

    // 3. Idempotent retry (clientKey) without duplicates
    const ck = `p15b-idem-${Date.now()}`;
    const m1 = await apiReq(apiCtx, `/api/chat/rooms/${groupRoom.id}/messages`, {
      method: 'POST',
      token: teacher.token,
      orgId,
      jsonBody: { body: 'Idempotent ping', clientKey: ck },
    });
    const m2 = await apiReq(apiCtx, `/api/chat/rooms/${groupRoom.id}/messages`, {
      method: 'POST',
      token: teacher.token,
      orgId,
      jsonBody: { body: 'Idempotent ping', clientKey: ck },
    });
    assert.equal(m1.json.data.id, m2.json.data.id, 'Duplicate message created on retry');

    // 4. Attachment & Voice message via Media Foundation
    const docId = await uploadMedia(
      apiCtx,
      teacher.token,
      orgId,
      'document',
      'notes.txt',
      'text/plain',
      Buffer.from('PLATFORM_15_B attachment'),
    );
    const docMsgRes = await apiReq(apiCtx, `/api/chat/rooms/${groupRoom.id}/messages`, {
      method: 'POST',
      token: teacher.token,
      orgId,
      jsonBody: { kind: 'file', mediaAssetId: docId, body: 'Attached doc' },
    });
    assert.equal(docMsgRes.status, 201, `docMsg failed: ${docMsgRes.text}`);

    const voiceId = await uploadMedia(
      apiCtx,
      teacher.token,
      orgId,
      'voice',
      'voice.webm',
      'audio/webm',
      Buffer.from('OggS\x00\x02dummy-voice-payload'),
    );
    const voiceMsgRes = await apiReq(apiCtx, `/api/chat/rooms/${groupRoom.id}/messages`, {
      method: 'POST',
      token: teacher.token,
      orgId,
      jsonBody: { kind: 'voice', mediaAssetId: voiceId },
    });
    assert.equal(voiceMsgRes.status, 201, `voiceMsg failed: ${voiceMsgRes.text}`);

    await studentCtx.page.locator('.uchat__msg-body').getByText('Attached doc').waitFor({ timeout: 10_000 });

    // 5. Edit & Delete message
    const sentId = m1.json.data.id;
    const editRes = await apiReq(apiCtx, `/api/chat/rooms/${groupRoom.id}/messages/${sentId}`, {
      method: 'PUT',
      token: teacher.token,
      orgId,
      jsonBody: { body: 'Edited ping' },
    });
    assert.equal(editRes.status, 200);
    await studentCtx.page.locator('.uchat__msg-body').getByText('Edited ping').waitFor({ timeout: 10_000 });

    const delRes = await apiReq(apiCtx, `/api/chat/rooms/${groupRoom.id}/messages/${sentId}/delete`, {
      method: 'POST',
      token: teacher.token,
      orgId,
      jsonBody: { mode: 'for_all' },
    });
    assert.equal(delRes.status, 200);

    // 6. Education ACL on addMember (unlinked student2 -> 403), remove student1, re-add student1 -> 200, then remove student1
    const deniedAddRes = await apiReq(apiCtx, `/api/chat/rooms/${groupRoom.id}/members`, {
      method: 'POST',
      token: teacher.token,
      orgId,
      jsonBody: { personId: student2.personId },
    });
    assert.equal(deniedAddRes.status, 403, `Expected 403 chat_context_denied for unlinked student2: ${deniedAddRes.text}`);

    const rem1Res = await apiReq(apiCtx, `/api/chat/rooms/${groupRoom.id}/members/${student1.personId}`, {
      method: 'DELETE',
      token: teacher.token,
      orgId,
    });
    assert.equal(rem1Res.status, 200, `removeMember failed: ${rem1Res.text}`);

    const addRes = await apiReq(apiCtx, `/api/chat/rooms/${groupRoom.id}/members`, {
      method: 'POST',
      token: teacher.token,
      orgId,
      jsonBody: { personId: student1.personId },
    });
    assert.equal(addRes.status, 200, `addMember failed: ${addRes.text}`);

    const remRes = await apiReq(apiCtx, `/api/chat/rooms/${groupRoom.id}/members/${student1.personId}`, {
      method: 'DELETE',
      token: teacher.token,
      orgId,
    });
    assert.equal(remRes.status, 200, `removeMember failed: ${remRes.text}`);

    const forbiddenMsgs = await apiReq(apiCtx, `/api/chat/rooms/${groupRoom.id}/messages`, {
      token: student1.token,
      orgId,
    });
    assert.equal(forbiddenMsgs.status, 404, 'Removed member still had access to room messages');

    // 7. Reconnect check: reload student page / reconnect WS
    await studentCtx.page.reload({ waitUntil: 'domcontentloaded' });
    await studentCtx.page.waitForTimeout(800);
    assert.equal(
      await studentCtx.page.getByText(groupRoom.title).count(),
      0,
      'Removed member still sees revoked room after reconnect',
    );

    // 8. Verify no new writes went to legacy education.messages
    const legacyCount = execFileSync(
      'docker',
      [
        'exec',
        'platform-postgres',
        'psql',
        '-U',
        'platform_core_runtime',
        '-d',
        'platform',
        '-t',
        '-A',
        '-c',
        `SELECT count(*) FROM education.messages WHERE body = '${liveMsgText}';`,
      ],
      { encoding: 'utf8' },
    ).trim();
    assert.equal(legacyCount, '0', 'New message was written to legacy education.messages!');

    console.log('PLATFORM_15_B_E2E_PASS');
  } finally {
    await teacherCtx?.context?.close().catch(() => undefined);
    await studentCtx?.context?.close().catch(() => undefined);
    await browser.close();
    await apiCtx.dispose();
    if (nonce) runFixture('cleanup', nonce);
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
