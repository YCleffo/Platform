import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync } from 'node:fs';
import { setTimeout as delay } from 'node:timers/promises';
import { navigateClientSide, selectDropdown } from './e2e-support.mjs';

const resource = 'storage';
const signed = (url) => [...url.searchParams.keys()].some((key) => key.toLowerCase() === 'x-amz-signature');
const digest = (bytes) => createHash('sha256').update(bytes).digest('hex');
const completeRoute = '**/api/storage/transfers/*/complete';

async function holdTransfer(page, method) {
  const entered = Promise.withResolvers();
  const released = Promise.withResolvers();
  const handler = async (route) => {
    if (route.request().method() !== method) return route.fallback();
    entered.resolve();
    await released.promise;
    await route.abort('failed').catch(() => {});
  };
  await page.route(signed, handler);
  return {
    async wait() {
      let timer;
      try {
        await Promise.race([
          entered.promise,
          new Promise((_, reject) => { timer = setTimeout(() => reject(new Error(`Signed S3 ${method} did not start`)), 15000); }),
        ]);
      } finally {
        clearTimeout(timer);
      }
    },
    async release() {
      released.resolve();
      await page.unroute(signed, handler);
    },
  };
}

async function withObjectStoreOffline(check) {
  const docker = (...args) => execFileSync('docker', args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], timeout: 15000 }).trim();
  const labels = (container) => JSON.parse(docker('inspect', '--type', 'container', '--format', '{{json .Config.Labels}}', container));
  const core = process.env.CORE_TEST_CONTAINER;
  const storage = process.env.E2E_STORAGE_TEST_CONTAINER;
  assert.ok(core && storage && !storage.startsWith('-'), 'The isolated SeaweedFS test container must be specified');
  const coreLabels = labels(core);
  const storageLabels = labels(storage);
  const project = coreLabels['com.docker.compose.project'];
  assert.ok(project && storageLabels['com.docker.compose.project'] === project);
  assert.equal(coreLabels['com.docker.compose.service'], 'backend-test');
  assert.equal(storageLabels['com.docker.compose.service'], 'seaweedfs-test');
  const health = () => docker('inspect', '--type', 'container', '--format', '{{.State.Health.Status}}', storage);
  assert.equal(health(), 'healthy');
  try {
    docker('stop', '--time', '3', storage);
    return await check();
  } finally {
    docker('start', storage);
    const deadline = Date.now() + 45000;
    while (Date.now() < deadline && health() !== 'healthy') await delay(500);
    assert.equal(health(), 'healthy', 'SeaweedFS test service did not recover');
  }
}

function signatureDeadline(url) {
  const query = new URL(url).searchParams;
  const date = /^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z$/.exec(query.get('X-Amz-Date') ?? '');
  assert.ok(date, 'S3 test request has no signed timestamp');
  const seconds = Number(query.get('X-Amz-Expires'));
  assert.ok(Number.isSafeInteger(seconds) && seconds > 0 && seconds <= 120, 'S3 expiry test requires a TTL no longer than 120 seconds');
  const [, year, month, day, hour, minute, second] = date;
  return Date.parse(`${year}-${month}-${day}T${hour}:${minute}:${second}Z`) + seconds * 1000;
}

export async function verifyStorageRecovery({ student, organizationId, otherOrganizationId, switchOrganizationId, openStudent, setQuota }) {
  const { page, api } = student;
  await navigateClientSide(page, '/student/documents');
  const versions = async (name) => (await api(`/files/versions?path=${encodeURIComponent(name)}`, { resource })).items;
  const files = async () => (await api('/files?offset=0', { resource })).items;
  const row = (name, view = page) => view.locator('.edu-list__item').filter({ hasText: name });
  const retry = (name) => page.getByRole('button', { name: `Повторить загрузку ${name}`, exact: true });
  const chooseFile = async (name, buffer, view = page) => {
    await view.locator('input[type="file"]').setInputFiles({ name, buffer, mimeType: 'application/octet-stream' });
  };
  const downloadButton = (name) => row(name).getByRole('button', { name: 'Скачать', exact: true });
  async function selectOrganization(view, id) {
    const select = view.locator('#education-student-organization');
    await select.waitFor();
    await selectDropdown(view, 'education-student-organization', id);
    await view.locator('input[type="file"]').waitFor();
  }
  async function download(name, expected) {
    const [downloaded] = await Promise.all([page.waitForEvent('download'), downloadButton(name).click()]);
    const bytes = readFileSync(await downloaded.path());
    assert.deepEqual(bytes, expected);
    assert.equal(digest(bytes), digest(expected));
  }
  const name = 'frontend07-recovery.bin';
  const original = Buffer.from([0, 1, 127, 255, 9, 0]);
  const replacement = Buffer.from('confirmed replacement');
  const stale = Buffer.from('stale writing client');

  await selectOrganization(page, organizationId);
  await chooseFile(name, original);
  try {
    await row(name).waitFor();
  } catch (error) {
    const message = await page.locator('main .edu-error[role="alert"]').first().textContent({ timeout: 1500 }).catch(() => '');
    throw new Error(`Initial signed upload did not publish${message ? ` (${message})` : ''}.`, { cause: error });
  }
  const first = (await versions(name))[0];
  assert.equal(first.sha256, digest(original));
  await download(name, original);
  for (const width of [393, 768, 1024, 1440]) {
    await page.setViewportSize({ width, height: 852 });
    await downloadButton(name).waitFor();
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), `Document view overflows at ${width}px`);
  }
  await page.setViewportSize({ width: 393, height: 852 });

  const canceledName = 'cancelled-upload.bin';
  const heldUpload = await holdTransfer(page, 'PUT');
  try {
    await chooseFile(canceledName, replacement);
    await heldUpload.wait();
    const completedAbort = page.waitForResponse((response) => /^\/api\/storage\/transfers\/[^/]+\/abort$/.test(new URL(response.url()).pathname));
    await page.getByRole('button', { name: 'Отменить передачу', exact: true }).click();
    assert.ok((await completedAbort).ok(), 'Core did not acknowledge upload cancellation');
    assert.ok(!(await files()).some((file) => file.path === canceledName));
  } finally {
    await heldUpload.release();
  }

  const heldDownload = await holdTransfer(page, 'GET');
  let prematureDownload = false;
  const recordDownload = () => { prematureDownload = true; };
  page.on('download', recordDownload);
  try {
    await downloadButton(name).click();
    await heldDownload.wait();
    await page.locator('#document-download-progress').waitFor();
    await page.getByRole('button', { name: 'Отменить передачу', exact: true }).click();
    await page.locator('#document-download-progress').waitFor({ state: 'hidden' });
  } finally {
    await heldDownload.release();
    page.off('download', recordDownload);
  }
  assert.equal(prematureDownload, false);
  await download(name, original);

  const networkName = 'network-retry.bin';
  const networkFailure = async (route) => route.request().method() === 'PUT' ? route.abort('internetdisconnected') : route.fallback();
  await page.route(signed, networkFailure);
  try {
    await chooseFile(networkName, original);
    await retry(networkName).waitFor();
    assert.ok(!(await files()).some((file) => file.path === networkName));
  } finally {
    await page.unroute(signed, networkFailure);
  }
  await retry(networkName).click();
  await row(networkName).waitFor();
  assert.equal((await versions(networkName))[0].sha256, digest(original));
  console.log('Storage A–D verified against Core and SeaweedFS, including all four viewports.');

  const outageName = 'object-store-outage.bin';
  await withObjectStoreOffline(async () => {
    await chooseFile(outageName, replacement);
    await retry(outageName).waitFor();
    assert.ok(!(await files()).some((file) => file.path === outageName));
    assert.equal((await files()).find((file) => file.path === name).sha256, digest(original));
  });
  await retry(outageName).click();
  await row(outageName).waitFor();
  await download(outageName, replacement);

  const space = await api('/space', { resource });
  assert.equal(setQuota(space.totalBytes).updated, true);
  try {
    const quotaDenied = page.waitForResponse((response) => response.status() === 409
      && response.request().method() === 'POST'
      && new URL(response.url()).pathname.startsWith('/api/storage/'));
    await chooseFile('quota-rejected.bin', replacement);
    assert.equal((await (await quotaDenied).json()).error.meta.storageCode, 'quota_exceeded');
    await retry('quota-rejected.bin').waitFor();
    assert.ok(!(await files()).some((file) => file.path === 'quota-rejected.bin'));
  } finally {
    assert.equal(setQuota(space.maxBytes).updated, true);
  }

  const expiry = Promise.withResolvers();
  let waitingOnExpiry = false;
  const delayedSignedGet = async (route) => {
    if (waitingOnExpiry || route.request().method() !== 'GET') return route.fallback();
    waitingOnExpiry = true;
    let deadline;
    try {
      deadline = signatureDeadline(route.request().url());
    } catch (error) {
      expiry.reject(error);
      await route.abort('failed');
      return;
    }
    try {
      await delay(Math.max(0, deadline - Date.now() + 1400));
      const expired = await route.fetch({ timeout: 15000, maxRedirects: 0 });
      assert.equal(expired.status(), 403, 'SeaweedFS accepted an expired signature');
      await route.fulfill({ response: expired });
      expiry.resolve();
    } catch {
      expiry.reject(new Error('Could not deliver the expired signed GET to SeaweedFS'));
      await route.abort('failed').catch(() => {});
    }
  };
  await page.route(signed, delayedSignedGet);
  const renewed = page.waitForResponse((response) => response.ok() && /^\/api\/storage\/transfers\/[^/]+\/refresh$/.test(new URL(response.url()).pathname), { timeout: 140000 });
  try {
    const [renewedDownload] = await Promise.all([
      page.waitForEvent('download', { timeout: 140000 }), expiry.promise, renewed,
      downloadButton(name).click(),
    ]);
    assert.deepEqual(readFileSync(await renewedDownload.path()), original);
  } finally {
    await page.unroute(signed, delayedSignedGet);
  }
  console.log('Storage E–G verified against an unavailable service, exhausted quota and real signed-URL expiry.');

  let transferPath;
  const reconciled = page.waitForResponse((response) => transferPath && response.ok()
    && response.request().method() === 'GET'
    && new URL(response.url()).pathname === `/api/storage${transferPath.replace('/complete', '')}`);
  await page.route(completeRoute, async (route) => {
    const response = await route.fetch();
    if (response.ok()) transferPath = new URL(route.request().url()).pathname.replace('/api/storage', '');
    await route.abort('failed');
  }, { times: 1 });
  page.once('dialog', (dialog) => dialog.accept());
  await chooseFile(name, replacement);
  assert.equal((await (await reconciled).json()).data.status, 'confirmed');
  assert.ok(transferPath, 'A real successful complete is required before dropping its response');
  const intact = await versions(name);
  assert.equal(intact.length, 2);
  assert.equal(intact[0].sha256, digest(replacement));
  await downloadButton(name).waitFor({ state: 'visible' });
  await download(name, replacement);
  const repeated = await api(transferPath, { method: 'POST', resource, body: {} });
  assert.equal(repeated.status, 'confirmed');
  assert.deepEqual(await versions(name), intact);

  const peer = await openStudent();
  await selectOrganization(peer.page, organizationId);
  const confirmConflict = Promise.withResolvers();
  const releaseConflict = Promise.withResolvers();
  const delayedComplete = async (route) => {
    confirmConflict.resolve();
    await releaseConflict.promise;
    await route.continue().catch(() => {});
  };
  await page.route(completeRoute, delayedComplete, { times: 1 });
  try {
    page.once('dialog', (dialog) => dialog.accept());
    await chooseFile(name, stale);
    await Promise.race([confirmConflict.promise, retry(name).waitFor().then(() => assert.fail('Stale upload failed before reaching completion'))]);
    await row(name, peer.page).getByRole('button', { name: 'История версий' }).click();
    const version = peer.page.getByRole('dialog', { name: 'История версий' })
      .locator('li').filter({ has: peer.page.getByText(`Версия ${first.version}`, { exact: true }) });
    peer.page.once('dialog', (dialog) => dialog.accept());
    const restored = peer.page.waitForResponse((response) => new URL(response.url()).pathname === '/api/storage/files/restore');
    await version.getByRole('button', { name: 'Восстановить версию' }).click();
    assert.ok((await restored).ok());
    const conflict = page.waitForResponse((response) => response.status() === 409 && new URL(response.url()).pathname.endsWith('/complete'));
    releaseConflict.resolve();
    await conflict;
    await retry(name).waitFor();
    assert.equal((await files()).find((file) => file.path === name).sha256, digest(original));
    assert.ok(!(await versions(name)).some((version) => version.sha256 === digest(stale)));
    await download(name, original);
  } finally {
    releaseConflict.resolve();
    await peer.page.context().close();
  }

  const switchName = 'scope-switch.bin';
  const switching = await holdTransfer(page, 'PUT');
  try {
    await chooseFile(switchName, replacement);
    await switching.wait();
    await selectOrganization(page, switchOrganizationId);
    await row(name).waitFor({ state: 'hidden' });
    assert.deepEqual((await api('/files?offset=0', { resource, organizationId: switchOrganizationId })).items, []);
    assert.equal(await retry(switchName).count(), 0);
  } finally {
    await switching.release();
  }
  await selectOrganization(page, organizationId);
  await row(name).waitFor();
  assert.ok(!(await files()).some((file) => file.path === switchName));

  const logoutName = 'interrupted-by-logout.bin';
  const loggingOut = await holdTransfer(page, 'PUT');
  try {
    await chooseFile(logoutName, replacement);
    await loggingOut.wait();
    const logout = page.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/auth/logout');
    await page.locator('.edu-topbar').getByRole('button', { name: 'Выйти', exact: true }).click();
    assert.ok((await logout).ok(), 'Core did not acknowledge logout');
    await row(name).waitFor({ state: 'hidden' });
    await page.locator('#document-transfer-progress').waitFor({ state: 'hidden' });
  } finally {
    await loggingOut.release();
  }
  await student.signIn('/student/documents');
  await selectOrganization(page, organizationId);
  assert.ok(!(await files()).some((file) => file.path === logoutName));
  await api('/files?offset=0', { resource, organizationId: otherOrganizationId, status: [403, 404] });

  const reloadName = 'interrupted-by-reload.bin';
  const interrupted = await holdTransfer(page, 'PUT');
  try {
    await chooseFile(reloadName, replacement);
    await interrupted.wait();
    await student.reopen();
    await selectOrganization(page, organizationId);
    assert.ok(!(await files()).some((file) => file.path === reloadName));
  } finally {
    await interrupted.release();
  }
  await chooseFile(reloadName, replacement);
  await row(reloadName).waitFor();
  await download(reloadName, replacement);
  mkdirSync('screenshots/education-real', { recursive: true });
  await page.screenshot({ path: 'screenshots/education-real/storage-recovery.png', animations: 'disabled' });
  console.log('Verified signed S3 bytes, upload/download cancellation, network retry, quota, ambiguous and repeated completion, restore, stale conflict, interrupted reload and foreign scope denial.');
}
