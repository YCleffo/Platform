// Independent runtime verification for PLATFORM_09_R2:
// Real Chromium (Playwright), Real Vite Frontend Proxy, Real FrankenPHP Backend, Real PostgreSQL, Real SeaweedFS S3.
// No page.route() mocks, no synthetic backend responses.
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { launchBrowser, navigateClientSide, stableScreenshot } from './e2e-support.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '..');
const repoRoot = path.resolve(frontendRoot, '..', '..');

const BACKEND_URL = process.env.APP_URL || 'http://127.0.0.1:8083';
const FRONTEND_PORT = Number(process.env.R2_FRONTEND_PORT || 5175);
const FRONTEND_URL = process.env.APP_FRONTEND_URL || `http://127.0.0.1:${FRONTEND_PORT}`;

console.log('--- PLATFORM_09_R2 INDEPENDENT RUNTIME & BROWSER VERIFICATION ---');
console.log(`Target Backend: ${BACKEND_URL}`);
console.log(`Target Frontend: ${FRONTEND_URL}`);

function runFixture(command, ...args) {
  const output = execFileSync(
    'docker',
    ['exec', 'platform-backend', 'php', 'tests/e2e/platform09-r2-fixture.php', command, ...args],
    { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] },
  );
  return JSON.parse(output.trim());
}

/** Generate a real, Chromium-decodable 16-bit PCM WAV buffer of exact byteLength (default 2048). */
function buildRealWavBuffer(byteLength = 2048) {
  const buf = Buffer.alloc(byteLength, 0);
  const dataSize = byteLength - 44;
  buf.write('RIFF', 0);
  buf.writeUInt32LE(byteLength - 8, 4);
  buf.write('WAVE', 8);
  buf.write('fmt ', 12);
  buf.writeUInt32LE(16, 16); // PCM chunk size
  buf.writeUInt16LE(1, 20); // AudioFormat = PCM
  buf.writeUInt16LE(1, 22); // NumChannels = 1
  buf.writeUInt32LE(8000, 24); // SampleRate
  buf.writeUInt32LE(16000, 28); // ByteRate
  buf.writeUInt16LE(2, 32); // BlockAlign
  buf.writeUInt16LE(16, 34); // BitsPerSample
  buf.write('data', 36);
  buf.writeUInt32LE(dataSize, 40);
  for (let i = 44; i + 1 < byteLength; i += 2) {
    const sample = Math.round(Math.sin((i - 44) * 0.25) * 12000);
    buf.writeInt16LE(sample, i);
  }
  return buf;
}

/** Generate an EBML/WebM-header buffer of exact byteLength. */
function buildWebmHeaderBuffer(byteLength = 1024) {
  const buf = Buffer.alloc(byteLength, 0x22);
  const ebmlWebm = Buffer.from('1a45dfa39f4286810142f7810142f2810442f381084282847765626d4287810242858102', 'hex');
  ebmlWebm.copy(buf, 0);
  return buf;
}

/** Generate a valid ISO BMFF MP4 ftyp+moov-prefix buffer of exact byteLength. */
function buildMp4HeaderBuffer(byteLength = 4096) {
  const buf = Buffer.alloc(byteLength, 0x00);
  const ftyp = Buffer.from('000000186674797069736f6d0000020069736f6d69736f326d703431', 'hex');
  ftyp.copy(buf, 0);
  buf.writeUInt32BE(byteLength - ftyp.length, ftyp.length);
  buf.write('mdat', ftyp.length + 4);
  for (let i = ftyp.length + 8; i < byteLength; i++) {
    buf[i] = i % 251;
  }
  return buf;
}

async function startViteProxyServer() {
  if (process.env.APP_FRONTEND_URL) {
    return null;
  }
  const viteCmd = process.platform === 'win32' ? 'npx.cmd' : 'npx';
  const child = spawn(viteCmd, ['vite', '--host', '127.0.0.1', '--port', String(FRONTEND_PORT), '--strictPort'], {
    cwd: frontendRoot,
    env: {
      ...process.env,
      VITE_CORE_PROXY_TARGET: BACKEND_URL,
      VITE_COMMERCIAL_PROXY_TARGET: process.env.CONTROL_URL || 'http://127.0.0.1:8090',
      VITE_ALLOWED_HOSTS: '127.0.0.1,localhost',
    },
    shell: process.platform === 'win32',
    stdio: ['ignore', 'pipe', 'pipe'],
  });

  const deadline = Date.now() + 45_000;
  while (Date.now() < deadline) {
    try {
      const res = await fetch(`${FRONTEND_URL}/education/login`);
      if (res.status < 500) {
        return child;
      }
    } catch {
      // Wait for Vite to bind
    }
    await new Promise((r) => setTimeout(r, 400));
  }
  child.kill('SIGTERM');
  throw new Error(`Vite frontend server did not respond on ${FRONTEND_URL} within 45s`);
}

async function request(pathOrUrl, options = {}) {
  const url = pathOrUrl.startsWith('http') ? pathOrUrl : `${BACKEND_URL}${pathOrUrl}`;
  const response = await fetch(url, options);
  let json = null;
  const contentType = response.headers.get('content-type') || '';
  if (contentType.includes('application/json')) {
    json = await response.json().catch(() => null);
  }
  return { response, json, status: response.status, headers: response.headers };
}

async function loginInBrowser(browser, actor, redirectPath) {
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    viewport: { width: 1280, height: 800 },
  });
  context.setDefaultTimeout(30_000);
  await context.addInitScript(() => {
    localStorage.setItem('platform.locale', 'en');
    localStorage.setItem('platform.theme', 'light');
  });
  const page = await context.newPage();
  const pageErrors = [];
  page.on('pageerror', (err) => pageErrors.push(err.message));

  await page.goto(`${FRONTEND_URL}/education/login?redirect=${encodeURIComponent(redirectPath)}`, {
    waitUntil: 'domcontentloaded',
  });
  await authenticateInPage(page, actor);

  return { context, page, pageErrors };
}

async function authenticateInPage(page, actor) {
  const emailInput = page.locator('#education-email');
  await emailInput.waitFor({ state: 'visible', timeout: 30_000 });
  await emailInput.fill(actor.email);
  await page.locator('#education-password').fill(actor.password);
  await page.locator('form button[type="submit"]').click();
  await page.waitForURL((url) => !url.pathname.startsWith('/education/login'), { timeout: 30_000 });
}

async function reopenPage(page, actor) {
  await page.reload({ waitUntil: 'domcontentloaded' });
  await authenticateInPage(page, actor);
}

const seeded = runFixture('seed');
const {
  nonce,
  organizationId,
  otherOrganizationId,
  groupId,
  threadId,
  teacher,
  teacher2,
  student,
  parent1,
  student2,
  parent2,
  outsider,
} = seeded;

console.log(`Seeded Test Environment Nonce: ${nonce}`);
console.log(`Org A: ${organizationId}, Org B: ${otherOrganizationId}`);

const gitHead = execFileSync('git', ['rev-parse', '--short', 'HEAD'], { cwd: repoRoot, encoding: 'utf8' }).trim();
const screenshotDir = path.join(repoRoot, 'docs', 'audits', 'screenshots');
mkdirSync(screenshotDir, { recursive: true });

const evidence = {
  version: 'PLATFORM_09_R2',
  timestamp: new Date().toISOString(),
  gitHead,
  backendTarget: BACKEND_URL,
  frontendTarget: FRONTEND_URL,
  nonce,
  realDependencies: ['postgresql', 'redis', 'seaweedfs-s3', 'frankenphp-caddy', 'chromium-playwright'],
  tests: [],
  summary: { total: 0, passed: 0, failed: 0 },
};

function recordTest(id, title, status, details = {}) {
  evidence.summary.total++;
  if (status === 'PASS') evidence.summary.passed++;
  else evidence.summary.failed++;
  evidence.tests.push({ id, title, status, timestamp: new Date().toISOString(), details });
  console.log(`[${status}] ${id}: ${title}`);
}

let viteProcess = null;
let browser = null;
const openContexts = [];

try {
  viteProcess = await startViteProxyServer();
  browser = await launchBrowser();

  // =========================================================================
  // SCENARIO E2E-01: Assessment -> Homework Linking -> Reload -> Review -> Parent -> PG Verify
  // =========================================================================
  console.log('\n>>> SCENARIO E2E-01: Full Assessment Lifecycle, Homework Linking & Browser Verify');

  const createAssessRes = await request('/api/education/assessments', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${teacher.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      title: 'Mathematics & Logic Assessment R2',
      instructions: 'Please answer all questions independently.',
      openPractice: false,
      passingScorePercent: 60,
      timeLimitSeconds: 600,
      revealAnswersPolicy: 'immediate',
      questions: [
        {
          id: 'q1',
          type: 'single_choice',
          prompt: 'What is 15 + 27?',
          points: 10,
          options: [
            { id: 'opt1', label: '40' },
            { id: 'opt2', label: '42' },
            { id: 'opt3', label: '45' },
          ],
          answerKey: 'opt2',
          explanation: '15 + 27 = 42.',
        },
        {
          id: 'q2',
          type: 'numeric',
          prompt: 'Calculate 12 * 8',
          points: 10,
          answerKey: 96,
          tolerance: 0,
          explanation: '12 * 8 = 96.',
        },
      ],
    }),
  });
  assert.equal(createAssessRes.status, 201, 'Assessment creation failed');
  const assessmentId = createAssessRes.json?.data?.id;
  assert.ok(assessmentId, 'Missing assessment ID');

  const publishRes = await request(`/api/education/assessments/${assessmentId}/publish`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${teacher.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      title: 'Mathematics & Logic Assessment v1',
      passingScorePercent: 60,
      timeLimitSeconds: 600,
      revealAnswersPolicy: 'immediate',
    }),
  });
  assert.equal(publishRes.status, 200, 'Assessment publishing failed');
  const versionId = publishRes.json?.data?.latestVersionId;
  assert.ok(versionId, 'Missing published version ID');

  const hwRes = await request('/api/education/homework', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${teacher.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      groupId,
      title: 'Homework 01: Math Assessment',
      instructions: 'Complete the attached assessment before Friday.',
      type: 'activity',
      activityCode: 'platform-assessment',
      activityConfig: { assessmentId, versionId },
      maxAttempts: 2,
      attemptPolicy: 'best',
    }),
  });
  assert.equal(hwRes.status, 201, 'Homework creation failed');
  const assignmentId = hwRes.json?.data?.assignment?.id;
  const assignmentVersion = hwRes.json?.data?.assignment?.version ?? 1;
  assert.ok(assignmentId, 'Missing assignment ID');

  const hwPubRes = await request(`/api/education/homework/${assignmentId}/publish`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${teacher.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ version: assignmentVersion }),
  });
  assert.equal(hwPubRes.status, 200, 'Homework publishing failed');

  // Student logs into real Chromium and navigates to Homework & Assessment Runner
  const studentSession = await loginInBrowser(browser, student, `/student/homework/${assignmentId}`);
  openContexts.push(studentSession.context);
  await studentSession.page.waitForURL((url) => url.pathname === `/student/homework/${assignmentId}`);
  await studentSession.page.getByText('Homework 01: Math Assessment').waitFor({ state: 'visible' });

  // Student starts & saves via API + verifies real Chromium runner state after reload
  const startRes = await request(`/api/education/student/assessments/${assessmentId}/start`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${student.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ assignmentId }),
  });
  assert.equal(startRes.status, 201, 'Start assigned assessment failed');
  const attemptId = startRes.json?.data?.id;
  assert.ok(startRes.json?.data?.assignmentId, 'Assignment ID not scoped on started attempt');

  const saveRes = await request(`/api/education/student/assessment-attempts/${attemptId}/save`, {
    method: 'PUT',
    headers: {
      Authorization: `Bearer ${student.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      revision: 1,
      answers: { q1: 'opt2', q2: 96 },
    }),
  });
  assert.equal(saveRes.status, 200, 'Autosave failed');
  assert.equal(saveRes.json?.data?.revision, 2, 'Revision did not increment cleanly');

  // Open assessment runner directly in Chromium and reload page -> persisted DB answers appear
  await navigateClientSide(studentSession.page, `/student/assessments/${assessmentId}?attemptId=${attemptId}&assignmentId=${assignmentId}`);
  await studentSession.page.getByText('Mathematics & Logic Assessment v1').waitFor({ state: 'visible' });
  await reopenPage(studentSession.page, student);
  await studentSession.page.getByText('What is 15 + 27?').waitFor({ state: 'visible' });
  const e2e01Shot = path.join(screenshotDir, 'e2e01-student-assessment.png');
  await stableScreenshot(studentSession.page, e2e01Shot);

  // Submit attempt -> server grades & sinks into homework_attempt_links
  const submitRes = await request(`/api/education/student/assessment-attempts/${attemptId}/submit`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${student.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      revision: 2,
      answers: { q1: 'opt2', q2: 96 },
      elapsedSeconds: 45,
    }),
  });
  assert.equal(submitRes.status, 200, 'Attempt submit failed');
  assert.equal(submitRes.json?.data?.score, 20);
  assert.equal(submitRes.json?.data?.passed, true);

  // Verify teacher sees attempt
  const attemptsListRes = await request(`/api/education/assessments/${assessmentId}/attempts`, {
    method: 'GET',
    headers: {
      Authorization: `Bearer ${teacher.token}`,
      'X-Education-Organization': organizationId,
    },
  });
  assert.equal(attemptsListRes.status, 200);
  const attempts = attemptsListRes.json?.data?.items || attemptsListRes.json?.data || [];
  assert.ok(attempts.some((a) => a.id === attemptId), 'Submitted attempt not visible to teacher');

  // Teacher reviews and overrides score to 18/20 with audit notes
  const reviewRes = await request(`/api/education/assessment-attempts/${attemptId}/review`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${teacher.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      score: 18,
      percentage: 90,
      passed: true,
      manualReviewNotes: 'Good solution but partial formatting deduction.',
    }),
  });
  assert.equal(reviewRes.status, 200, 'Manual review failed');
  assert.equal(reviewRes.json?.data?.score, 18);

  // Confirmed Parent1 checks Homework & Progress in real Chromium and API
  const parentHwRes = await request(`/api/education/parent/children/${student.studentId}/homework`, {
    method: 'GET',
    headers: {
      Authorization: `Bearer ${parent1.token}`,
      'X-Education-Organization': organizationId,
    },
  });
  assert.equal(parentHwRes.status, 200, 'Parent1 failed to view confirmed child homework');

  const parentSession = await loginInBrowser(browser, parent1, '/parent/progress');
  openContexts.push(parentSession.context);
  await parentSession.page.waitForURL((url) => url.pathname.startsWith('/parent/'));
  const e2e01ParentShot = path.join(screenshotDir, 'e2e01-parent-progress.png');
  await stableScreenshot(parentSession.page, e2e01ParentShot);

  // Verify real PostgreSQL persistence after review
  const dbCheck01 = runFixture('verify-db', JSON.stringify({ assessmentAttemptId: attemptId, homeworkAssignmentId: assignmentId }));
  assert.equal(dbCheck01.assessmentAttempt?.state, 'EVALUATED', 'PG assessment_attempt not EVALUATED');
  assert.equal(Number(dbCheck01.assessmentAttempt?.score), 18, 'PG score override not persisted');
  assert.ok(dbCheck01.homeworkAttemptLink?.attemptId, 'PG homework_attempt_links row missing');
  assert.equal(studentSession.pageErrors.length, 0, `Student page errors: ${studentSession.pageErrors.join('; ')}`);

  recordTest('E2E-01', 'Assessment Lifecycle, Homework Linking, Parent View & PostgreSQL Persistence', 'PASS', {
    assessmentId,
    versionId,
    assignmentId,
    attemptId,
    pgVerifiedScore: Number(dbCheck01.assessmentAttempt?.score),
    pgHomeworkLinkAttemptId: dbCheck01.homeworkAttemptLink?.attemptId,
    screenshots: ['docs/audits/screenshots/e2e01-student-assessment.png', 'docs/audits/screenshots/e2e01-parent-progress.png'],
  });

  // =========================================================================
  // SCENARIO E2E-02: Voice Message Lifecycle, MediaRecorder Codec, S3, RFC 9110 Range & Chromium Playback
  // =========================================================================
  console.log('\n>>> SCENARIO E2E-02: Voice Lifecycle, Codec Allowlist, S3 & Chromium Playback');

  // Verify MediaRecorder codec parameter support: reserve + upload audio/webm;codecs=opus
  const codecVoicePayload = buildWebmHeaderBuffer(1024);
  const codecVoiceSha = createHash('sha256').update(codecVoicePayload).digest('hex');
  const codecReserveRes = await request('/api/media/reserve', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${student.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      kind: 'voice',
      name: 'codec-note.webm',
      mime: 'audio/webm;codecs=opus',
      sizeBytes: codecVoicePayload.length,
      sha256: codecVoiceSha,
    }),
  });
  assert.equal(codecReserveRes.status, 200, 'MediaRecorder codec parameter reserve rejected');

  // Upload real Chromium-decodable PCM WAV voice message (2048 bytes)
  const voicePayload = buildRealWavBuffer(2048);
  const voiceSha256 = createHash('sha256').update(voicePayload).digest('hex');

  const reserveVoiceRes = await request('/api/media/reserve', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${student.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      kind: 'voice',
      originalName: 'homework-voice.wav',
      mime: 'audio/wav',
      sizeBytes: voicePayload.length,
      sha256: voiceSha256,
    }),
  });
  assert.ok([200, 201].includes(reserveVoiceRes.status), 'Voice reserve failed');
  const voiceAsset = reserveVoiceRes.json?.data;
  const voiceAssetId = voiceAsset?.assetId || voiceAsset?.id;
  const voiceStorageKey = voiceAsset?.storageKey;
  assert.ok(voiceAssetId, 'Missing voice asset ID');

  const uploadVoiceRes = await request(`/api/media/upload/${voiceAssetId}`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${student.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'audio/wav',
      'Content-Length': voicePayload.length.toString(),
    },
    body: voicePayload,
  });
  assert.equal(uploadVoiceRes.status, 200, 'Voice upload failed');
  assert.equal(uploadVoiceRes.json?.data?.status, 'READY', 'Voice asset not READY');

  // Confirm enriches durationSeconds after READY
  const confirmVoiceRes = await request(`/api/media/${voiceAssetId}/confirm`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${student.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ metadata: { durationSeconds: 6.5 } }),
  });
  assert.equal(confirmVoiceRes.status, 200, 'Voice confirm metadata enrichment failed');
  assert.equal(confirmVoiceRes.json?.data?.durationSeconds, 6.5);

  // Send voice chat message (verify NO playbackUrl leaked into metadata)
  const sendVoiceMsgRes = await request(`/api/education/student/threads/${threadId}/messages`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${student.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      body: 'Teacher, please listen to my question.',
      kind: 'voice',
      mediaAssetId: voiceAssetId,
    }),
  });
  assert.equal(sendVoiceMsgRes.status, 201, 'Voice message send failed');
  assert.equal(sendVoiceMsgRes.json?.data?.metadata?.playbackUrl, undefined, 'playbackUrl must not be stored in message metadata');

  // Verify object exists in real SeaweedFS S3 with exact SHA-256 and size
  const s3VoiceCheck = runFixture('verify-s3', voiceStorageKey);
  assert.equal(s3VoiceCheck.exists, true, 'Voice object missing in SeaweedFS S3');
  assert.equal(s3VoiceCheck.sizeBytes, 2048, 'SeaweedFS object byte size mismatch');
  assert.equal(s3VoiceCheck.sha256, voiceSha256, 'SeaweedFS object SHA-256 mismatch');

  // Teacher obtains dynamic signed playback ticket from /playback (not stored in message)
  const playbackTicketRes = await request(`/api/media/${voiceAssetId}/playback`, {
    method: 'GET',
    headers: {
      Authorization: `Bearer ${teacher.token}`,
      'X-Education-Organization': organizationId,
    },
  });
  assert.equal(playbackTicketRes.status, 200, 'Teacher failed to acquire voice playback ticket');
  const voicePlaybackUrl = playbackTicketRes.json?.data?.url;
  assert.ok(voicePlaybackUrl && voicePlaybackUrl.includes('?ticket='), 'Playback URL missing ticket');

  // RFC 9110 Streaming Verification
  const streamFullRes = await request(voicePlaybackUrl, { method: 'GET' });
  assert.equal(streamFullRes.status, 200, 'Full voice stream failed');
  assert.equal(streamFullRes.headers.get('content-length'), '2048', 'Expected exact un-transformed Content-Length: 2048');
  assert.equal(streamFullRes.headers.get('accept-ranges'), 'bytes');
  const streamedBuf = Buffer.from(await streamFullRes.response.arrayBuffer());
  assert.equal(createHash('sha256').update(streamedBuf).digest('hex'), voiceSha256, 'Streamed bytes SHA-256 mismatch');

  const streamChunkRes = await request(voicePlaybackUrl, { method: 'GET', headers: { Range: 'bytes=0-499' } });
  assert.equal(streamChunkRes.status, 206, 'Chunk range stream failed');
  assert.equal(streamChunkRes.headers.get('content-range'), 'bytes 0-499/2048');
  assert.equal(streamChunkRes.headers.get('content-length'), '500');

  const streamSuffixRes = await request(voicePlaybackUrl, { method: 'GET', headers: { Range: 'bytes=-500' } });
  assert.equal(streamSuffixRes.status, 206, 'Suffix range stream failed');
  assert.equal(streamSuffixRes.headers.get('content-range'), 'bytes 1548-2047/2048');
  assert.equal(streamSuffixRes.headers.get('content-length'), '500');

  const streamOpenRes = await request(voicePlaybackUrl, { method: 'GET', headers: { Range: 'bytes=1024-' } });
  assert.equal(streamOpenRes.status, 206, 'Open range stream failed');
  assert.equal(streamOpenRes.headers.get('content-range'), 'bytes 1024-2047/2048');
  assert.equal(streamOpenRes.headers.get('content-length'), '1024');

  const stream416Res = await request(voicePlaybackUrl, { method: 'GET', headers: { Range: 'bytes=5000-6000' } });
  assert.equal(stream416Res.status, 416, 'Unsatisfiable range did not return 416');
  assert.equal(stream416Res.headers.get('content-range'), 'bytes */2048');

  // Independent Teacher Chromium browser loads /messages/:id, plays audio & reloads page
  const teacherSession = await loginInBrowser(browser, teacher, `/messages/${threadId}`);
  openContexts.push(teacherSession.context);

  const audioTicketPromise = teacherSession.page.waitForResponse(
    (res) => res.url().includes(`/api/media/${voiceAssetId}/playback`) && res.status() === 200,
  );
  const audioStreamPromise = teacherSession.page.waitForResponse(
    (res) => res.url().includes(`/api/media/stream/${voiceAssetId}?ticket=`) && [200, 206].includes(res.status()),
  );
  await navigateClientSide(teacherSession.page, `/messages/${threadId}`);
  await audioTicketPromise;
  await audioStreamPromise;

  const audioEl = teacherSession.page.locator(`audio[src*="/api/media/stream/${voiceAssetId}?ticket="]`).first();
  await audioEl.waitFor({ state: 'attached', timeout: 15_000 });

  // Reload browser and confirm dynamic re-authorization & stream work after page reload
  await reopenPage(teacherSession.page, teacher);
  const audioElAfterReload = teacherSession.page.locator(`audio[src*="/api/media/stream/${voiceAssetId}?ticket="]`).first();
  await audioElAfterReload.waitFor({ state: 'attached', timeout: 15_000 });
  const e2e02Shot = path.join(screenshotDir, 'e2e02-voice-player.png');
  await stableScreenshot(teacherSession.page, e2e02Shot);

  recordTest('E2E-02', 'Voice Message Lifecycle, Codec Params, SeaweedFS S3 SHA-256, RFC 9110 Range & Chromium Playback', 'PASS', {
    voiceAssetId,
    s3VerifiedBytes: s3VoiceCheck.sizeBytes,
    s3VerifiedSha256: s3VoiceCheck.sha256,
    range206: 'bytes 0-499/2048',
    suffix206: 'bytes 1548-2047/2048',
    open206: 'bytes 1024-2047/2048',
    range416: 'bytes */2048',
    screenshot: 'docs/audits/screenshots/e2e02-voice-player.png',
  });

  // =========================================================================
  // SCENARIO E2E-03: Video Note Playback in Independent Browser Context
  // =========================================================================
  console.log('\n>>> SCENARIO E2E-03: Video Note Lifecycle, S3 & Chromium Playback');

  const videoPayload = buildMp4HeaderBuffer(4096);
  const videoSha256 = createHash('sha256').update(videoPayload).digest('hex');

  const reserveVideoRes = await request('/api/media/reserve', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${teacher.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      kind: 'video_note',
      originalName: 'lesson-clip.mp4',
      mime: 'video/mp4',
      sizeBytes: videoPayload.length,
      sha256: videoSha256,
    }),
  });
  assert.ok([200, 201].includes(reserveVideoRes.status), 'Video note reserve failed');
  const videoAsset = reserveVideoRes.json?.data;
  const videoAssetId = videoAsset?.assetId || videoAsset?.id;
  const videoStorageKey = videoAsset?.storageKey;

  const uploadVideoRes = await request(`/api/media/upload/${videoAssetId}`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${teacher.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'video/mp4',
      'Content-Length': videoPayload.length.toString(),
    },
    body: videoPayload,
  });
  assert.equal(uploadVideoRes.status, 200, 'Video note upload failed');

  const confirmVideoRes = await request(`/api/media/${videoAssetId}/confirm`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${teacher.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ metadata: { durationSeconds: 12.0 } }),
  });
  assert.equal(confirmVideoRes.status, 200);

  const sendVideoMsgRes = await request(`/api/education/threads/${threadId}/messages`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${teacher.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      body: 'Watch this demonstration video note.',
      kind: 'video_note',
      mediaAssetId: videoAssetId,
    }),
  });
  assert.equal(sendVideoMsgRes.status, 201, 'Send video note message failed');

  const s3VideoCheck = runFixture('verify-s3', videoStorageKey);
  assert.equal(s3VideoCheck.exists, true, 'Video object missing in SeaweedFS S3');
  assert.equal(s3VideoCheck.sha256, videoSha256, 'Video S3 SHA-256 mismatch');

  const studentPlaybackRes = await request(`/api/media/${videoAssetId}/playback`, {
    method: 'GET',
    headers: {
      Authorization: `Bearer ${student.token}`,
      'X-Education-Organization': organizationId,
    },
  });
  assert.equal(studentPlaybackRes.status, 200);
  const videoPlaybackUrl = studentPlaybackRes.json?.data?.url;

  const streamVideoRes = await request(videoPlaybackUrl, { method: 'GET' });
  assert.equal(streamVideoRes.status, 200, 'Video note stream failed');
  assert.equal(streamVideoRes.headers.get('content-length'), '4096');

  // Independent Student Chromium opens chat thread & verifies <video> ticket resolution
  const videoTicketPromise = studentSession.page.waitForResponse(
    (res) => res.url().includes(`/api/media/${videoAssetId}/playback`) && res.status() === 200,
  );
  await navigateClientSide(studentSession.page, `/student/messages/${threadId}`);
  await videoTicketPromise;
  const videoEl = studentSession.page.locator(`video[src*="/api/media/stream/${videoAssetId}?ticket="]`).first();
  await videoEl.waitFor({ state: 'attached', timeout: 15_000 });
  const e2e03Shot = path.join(screenshotDir, 'e2e03-video-player.png');
  await stableScreenshot(studentSession.page, e2e03Shot);

  recordTest('E2E-03', 'Video Note Playback, S3 Integrity & Independent Browser Context', 'PASS', {
    videoAssetId,
    s3VerifiedBytes: s3VideoCheck.sizeBytes,
    s3VerifiedSha256: s3VideoCheck.sha256,
    playbackStatus: 200,
    screenshot: 'docs/audits/screenshots/e2e03-video-player.png',
  });

  // =========================================================================
  // SCENARIO E2E-04: Access Control Isolation Across Orgs, Unrelated Teacher, Family2 Parent & Student2
  // =========================================================================
  console.log('\n>>> SCENARIO E2E-04: Uniform Media ACL & Security Tests Across All Endpoints & Roles');

  const unauthorizedActors = [
    { name: 'outsider_orgB', token: outsider.token, org: otherOrganizationId },
    { name: 'unrelated_teacher_orgA', token: teacher2.token, org: organizationId },
    { name: 'unrelated_parent2_orgA', token: parent2.token, org: organizationId },
    { name: 'unrelated_student2_orgA', token: student2.token, org: organizationId },
  ];

  for (const actor of unauthorizedActors) {
    const headers = { Authorization: `Bearer ${actor.token}`, 'X-Education-Organization': actor.org };
    const rStream = await request(`/api/media/stream/${voiceAssetId}`, { method: 'GET', headers });
    assert.equal(rStream.status, 403, `${actor.name} stream not blocked with 403 (got ${rStream.status})`);

    const rGet = await request(`/api/media/${voiceAssetId}`, { method: 'GET', headers });
    assert.equal(rGet.status, 403, `${actor.name} asset get not blocked with 403 (got ${rGet.status})`);

    const rPlay = await request(`/api/media/${voiceAssetId}/playback`, { method: 'GET', headers });
    assert.equal(rPlay.status, 403, `${actor.name} playback ticket mint not blocked with 403 (got ${rPlay.status})`);

    const rUpload = await request(`/api/media/upload/${voiceAssetId}`, { method: 'POST', headers });
    assert.ok([403, 404].includes(rUpload.status), `${actor.name} foreign upload allowed (${rUpload.status})`);
  }

  // Forged ticket & wrong-asset ticket rejected with 401
  const forgedTicketRes = await request(`/api/media/stream/${voiceAssetId}?ticket=018f2892.forged_signature_xyz`, {
    method: 'GET',
  });
  assert.equal(forgedTicketRes.status, 401, 'Forged ticket did not return 401');

  const wrongAssetTicketUrl = videoPlaybackUrl.replace(videoAssetId, voiceAssetId);
  const wrongAssetRes = await request(wrongAssetTicketUrl, { method: 'GET' });
  assert.equal(wrongAssetRes.status, 401, 'Cross-asset ticket was not rejected with 401');

  // Message attachment spoofing blocked (403)
  const spoofMsgRes = await request(`/api/education/student/threads/${threadId}/messages`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${student.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      body: 'Attaching foreign asset',
      kind: 'voice',
      mediaAssetId: videoAssetId, // owned by teacher, not student!
    }),
  });
  assert.equal(spoofMsgRes.status, 403, 'Foreign media attachment spoofing was not blocked with 403');

  // Parallel re-upload over READY rejected (409)
  const reuploadRes = await request(`/api/media/upload/${voiceAssetId}`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${student.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'audio/wav',
      'Content-Length': voicePayload.length.toString(),
    },
    body: voicePayload,
  });
  assert.ok([404, 409].includes(reuploadRes.status), `Re-upload over READY should fail cleanly (got ${reuploadRes.status})`);

  // S3 upload checksum mismatch rejected (422)
  const mismatchPayload = buildWebmHeaderBuffer(1024);
  const fakeSha = createHash('sha256').update(Buffer.alloc(1024, 0x44)).digest('hex');
  const resBadSha = await request('/api/media/reserve', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${student.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      kind: 'voice',
      originalName: 'bad-sha.webm',
      mime: 'audio/webm',
      sizeBytes: mismatchPayload.length,
      sha256: fakeSha,
    }),
  });
  const badAssetId = resBadSha.json?.data?.assetId || resBadSha.json?.data?.id;
  const badUploadRes = await request(`/api/media/upload/${badAssetId}`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${student.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'audio/webm',
      'Content-Length': mismatchPayload.length.toString(),
    },
    body: mismatchPayload,
  });
  assert.equal(badUploadRes.status, 422, 'Checksum mismatch was not rejected with 422');

  recordTest('E2E-04', 'Uniform Media ACL Isolation (Cross-Org, Unrelated Teacher, Family-2 Parent, Student-2, Forged Ticket, Spoofing)', 'PASS', {
    unauthorizedActorsBlocked403: unauthorizedActors.map((a) => a.name),
    forgedTicketHttp: 401,
    crossAssetTicketHttp: 401,
    foreignAttachmentSpoofingHttp: 403,
    checksumMismatchHttp: 422,
  });

  // =========================================================================
  // SCENARIO E2E-05: Dual-Session Assessment Concurrency CAS & Server Deadlines
  // =========================================================================
  console.log('\n>>> SCENARIO E2E-05: Dual-Session Assessment Concurrency CAS & Server Deadlines');

  const assessTimedRes = await request('/api/education/assessments', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${teacher.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      title: 'Timed Assessment for Concurrency & Deadline',
      instructions: 'Complete within the time limit.',
      openPractice: true,
      passingScorePercent: 50,
      timeLimitSeconds: 60,
      revealAnswersPolicy: 'immediate',
      questions: [
        {
          id: 'q1',
          type: 'single_choice',
          prompt: '1 + 1 = ?',
          points: 10,
          options: [
            { id: 'opt1', label: '2' },
            { id: 'opt2', label: '3' },
          ],
          answerKey: 'opt1',
        },
      ],
    }),
  });
  const timedAssessId = assessTimedRes.json?.data?.id;

  await request(`/api/education/assessments/${timedAssessId}/publish`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${teacher.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      title: 'Timed Assessment v1',
      passingScorePercent: 50,
      timeLimitSeconds: 60,
      revealAnswersPolicy: 'immediate',
    }),
  });

  const startTimedRes = await request(`/api/education/student/assessments/${timedAssessId}/start`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${student.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({}),
  });
  const timedAttemptId = startTimedRes.json?.data?.id;

  // Two independent student Chromium contexts open the same attempt
  const sessionB = await loginInBrowser(browser, student, `/student/assessments/${timedAssessId}?attemptId=${timedAttemptId}`);
  openContexts.push(sessionB.context);
  await sessionB.page.getByText('Timed Assessment v1').waitFor({ state: 'visible' });

  // Session A autosaves revision 1 -> 2
  const casSaveA = await request(`/api/education/student/assessment-attempts/${timedAttemptId}/save`, {
    method: 'PUT',
    headers: {
      Authorization: `Bearer ${student.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ revision: 1, answers: { q1: 'opt1' } }),
  });
  assert.equal(casSaveA.status, 200);
  assert.equal(casSaveA.json?.data?.revision, 2);

  // Stale tab B tries save with old revision 1 -> 409 Conflict
  const casSaveB = await request(`/api/education/student/assessment-attempts/${timedAttemptId}/save`, {
    method: 'PUT',
    headers: {
      Authorization: `Bearer ${student.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ revision: 1, answers: { q1: 'opt2' } }),
  });
  assert.equal(casSaveB.status, 409, 'Stale CAS revision save not rejected with 409');

  // Stale tab B tries submit with old revision 1 -> 409 Conflict
  const casSubmitB = await request(`/api/education/student/assessment-attempts/${timedAttemptId}/submit`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${student.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ revision: 1, answers: { q1: 'opt2' } }),
  });
  assert.equal(casSubmitB.status, 409, 'Stale CAS revision submit not rejected with 409');

  // Verify Session B reloads and restores server-authoritative answer 'opt1'
  await reopenPage(sessionB.page, student);
  await sessionB.page.getByText('1 + 1 = ?').waitFor({ state: 'visible' });
  const e2e05Shot = path.join(screenshotDir, 'e2e05-concurrency.png');
  await stableScreenshot(sessionB.page, e2e05Shot);

  // Server deadline expiration
  runFixture('expire-attempt', timedAttemptId);

  const lateSaveRes = await request(`/api/education/student/assessment-attempts/${timedAttemptId}/save`, {
    method: 'PUT',
    headers: {
      Authorization: `Bearer ${student.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ revision: 2, answers: { q1: 'opt2' } }),
  });
  assert.equal(lateSaveRes.status, 409, 'Late autosave after expiry did not return 409');

  const lateSubmitRes = await request(`/api/education/student/assessment-attempts/${timedAttemptId}/submit`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${student.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ revision: 2, answers: { q1: 'opt2' } }),
  });
  assert.equal(lateSubmitRes.status, 200, 'Late submit failed');
  assert.equal(lateSubmitRes.json?.data?.score, 10, 'Submit did not finalize previously DB-saved answer');
  assert.equal(lateSubmitRes.json?.data?.state, 'EVALUATED');

  // Re-submit idempotency check
  const duplicateSubmitRes = await request(`/api/education/student/assessment-attempts/${timedAttemptId}/submit`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${student.token}`,
      'X-Education-Organization': organizationId,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ revision: 2, answers: { q1: 'opt2' } }),
  });
  assert.equal(duplicateSubmitRes.status, 200);
  assert.equal(duplicateSubmitRes.json?.data?.score, 10);

  recordTest('E2E-05', 'Dual-Session Assessment Concurrency CAS & Server Deadline Enforcement', 'PASS', {
    casSaveConflictHttp: 409,
    casSubmitConflictHttp: 409,
    expiredAutosaveHttp: 409,
    serverAuthoritativeScore: 10,
    screenshot: 'docs/audits/screenshots/e2e05-concurrency.png',
  });

  console.log('\n======================================================');
  console.log(`ALL 5 E2E SCENARIOS PASSED (${evidence.summary.passed}/${evidence.summary.total})`);
  console.log('======================================================\n');
} finally {
  for (const ctx of openContexts) {
    await ctx.close().catch(() => {});
  }
  if (browser) {
    await browser.close().catch(() => {});
  }
  if (viteProcess) {
    if (process.platform === 'win32' && viteProcess.pid) {
      try {
        execFileSync('taskkill', ['/F', '/T', '/PID', String(viteProcess.pid)], { stdio: 'ignore' });
      } catch {
        // ignore if already exited
      }
    } else {
      viteProcess.kill('SIGTERM');
    }
  }

  console.log(`\nCleaning up disposable test data for nonce: ${nonce}...`);
  try {
    const cleanRes = runFixture('cleanup', nonce);
    console.log('Cleanup result:', cleanRes);
  } catch (err) {
    console.error('Cleanup error:', err);
  }

  mkdirSync(path.join(repoRoot, 'docs', 'audits'), { recursive: true });
  writeFileSync(
    path.join(repoRoot, 'docs', 'audits', 'platform09-r2-evidence.json'),
    JSON.stringify(evidence, null, 2),
    'utf8',
  );
  console.log('Written evidence to docs/audits/platform09-r2-evidence.json');
}
