import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdirSync } from 'node:fs';
import { request as playwrightRequest } from 'playwright';

const resource = 'device-management';
const saved = { sessionEnded: true, storageConfirmed: true };

export async function verifyClassroomLifecycle({
  teacher, dialog, card, device, deviceDetails, credential, next, first, coreUrl, otherOrganizationId,
}) {
  const { page, api } = teacher;
  const client = await playwrightRequest.newContext();
  async function agent(path, body, status = 200, key = credential) {
    const response = await client.fetch(`${coreUrl}/api/device-agent/${path}`, {
      method: body === undefined ? 'GET' : 'POST',
      headers: { Authorization: `Bearer ${key}` },
      ...(body === undefined ? {} : { data: body }),
    });
    const result = await response.json();
    assert.ok([status].flat().includes(response.status()), `Agent ${path}: HTTP ${response.status()} (${result.error?.meta?.deviceCode ?? result.error?.meta?.storageCode ?? ''})`);
    return result.data;
  }

  const state = (session, status, evidence = {}, expected = 200, details = {}) =>
    agent(`session/${session.id}/state`, { status, evidence, ...details }, expected);
  async function refresh(unfinished = false) {
    await agent('heartbeat', { state: { version: 'frontend07-test-adapter', machineReady: true, hasUnfinishedDocuments: unfinished } });
    const [response] = await Promise.all([
      page.waitForResponse((reply) => reply.request().method() === 'GET' && new URL(reply.url()).pathname === `/api/device-management/devices/${device.id}`),
      dialog.getByRole('button', { name: 'Обновить', exact: true }).click(),
    ]);
    assert.ok(response.ok(), 'Classroom detail refresh did not reach Core');
  }
  async function confirmAction(name, suffix) {
    await dialog.getByRole('button', { name, exact: true }).click();
    const [response] = await Promise.all([
      page.waitForResponse((reply) => reply.request().method() === 'POST' && new URL(reply.url()).pathname === `/api/device-management/devices/${device.id}${suffix}`),
      page.getByRole('dialog', { name, exact: true }).getByRole('button', { name, exact: true }).click(),
    ]);
    assert.ok(response.ok(), `${name} returned HTTP ${response.status()}`);
  }
  async function acknowledge(session, kind) {
    const commands = (await agent('commands')).items.filter((command) => command.kind === kind && command.payload.sessionId === session.id);
    assert.equal(commands.length, 1, `Expected one pending ${kind} command`);
    const path = `commands/${commands[0].id}/ack`;
    await agent(path, {});
    await agent(path, {});
  }
  async function activate(session) {
    await acknowledge(session, 'start_session');
    await state(session, 'PREPARING');
    await state(session, 'WINDOWS_PROFILE_READY', { profilePrepared: true });
    await state(session, 'SYNCING_IN');
    await state(session, 'ACTIVE', { profilePrepared: true }, 409);
    await state(session, 'ACTIVE', { profilePrepared: true, testAdapter: true });
    const details = await deviceDetails();
    assert.equal(details.sessions[0].status, 'ACTIVE');
    assert.equal(details.testAdapter, true);
  }
  async function close(session) {
    await state(session, 'STORAGE_CONFIRMED', saved);
    await state(session, 'CLEANUP', saved);
    await state(session, 'CLOSED', { ...saved, cleanupDone: true });
    await state(session, 'CLOSED', { ...saved, cleanupDone: true });
    const details = await deviceDetails();
    assert.equal(details.sessions.filter((item) => item.id === session.id).length, 1);
    assert.equal(details.sessions.find((item) => item.id === session.id).status, 'CLOSED');
  }

  try {
    await api(`/devices/${device.id}`, { resource, organizationId: otherOrganizationId, status: [403, 404] });
    await agent('commands', undefined, [401, 403], 'unrecognized-disposable-credential');
    await activate(first);
    await refresh();
    await dialog.getByText('Ученик работает', { exact: true }).first().waitFor();
    await state(first, 'ENDING', {}, 409);
    await confirmAction('Завершить сессию', '/session/end');
    await acknowledge(first, 'end_session');
    await state(first, 'ENDING');
    await state(first, 'SYNCING_OUT', { sessionEnded: true }, 200, { progress: { stage: 'upload', completed: 0, total: 1 } });
    await refresh(true);
    await dialog.getByText('Сохраняются документы', { exact: true }).first().waitFor();
    await state(first, 'STORAGE_CONFIRMED', saved, 409);

    const bytes = Buffer.from([0, 17, 127, 255, 42]);
    const snapshot = { path: 'classroom-recovery.bin', size: bytes.length, sha256: createHash('sha256').update(bytes).digest('hex') };
    await agent('storage/manifest', { version: 1, entries: [{ ...snapshot, op: 'add' }] });
    const ticket = await agent('storage/ticket', { purpose: 'upload', path: snapshot.path, expectedSize: snapshot.size, expectedSha256: snapshot.sha256 });
    const transfer = await agent('storage/transfers', { ticketId: ticket.id, token: ticket.token });
    const requestUrl = new URL(transfer.request.url);
    assert.ok(['127.0.0.1', 'localhost', 'e2e.platform.test'].includes(requestUrl.hostname));
    const put = await client.fetch(requestUrl.href, { method: 'PUT', headers: transfer.request.headers, data: bytes })
      .catch(() => { throw new Error('Signed Classroom S3 upload did not reach the isolated service'); });
    assert.ok(put.ok(), 'Signed Classroom S3 upload failed');
    await agent('storage/sync', { version: 1, entries: [snapshot] }, 409);
    await state(first, 'SYNC_PENDING', {}, 200, { error: { code: 'network_unavailable', message: 'Temporary test connection loss' } });
    await refresh(true);
    await dialog.getByText('Ожидается сохранение', { exact: true }).first().waitFor();
    assert.ok(!(await deviceDetails()).availableActions.includes('assign'));
    assert.equal(await dialog.getByRole('button', { name: 'Назначить ученика' }).count(), 0);
    await api(`/devices/${device.id}/session`, { resource, method: 'POST', body: { studentId: next.id }, status: 409 });
    await confirmAction('Повторить восстановление', '/recovery/retry');
    await acknowledge(first, 'end_session');
    await state(first, 'SYNCING_OUT', { sessionEnded: true });
    const confirmedTransfer = await agent(`storage/transfers/${transfer.id}/complete`, {});
    assert.equal(confirmedTransfer.status, 'confirmed');
    await agent('storage/sync', { version: 1, entries: [snapshot] });
    await agent('storage/sync', { version: 1, entries: [snapshot] });
    const manifest = await agent('storage/manifest');
    assert.equal(manifest.entries.find((entry) => entry.path === snapshot.path).sha256, snapshot.sha256);
    const downloadTicket = await agent('storage/ticket', { purpose: 'download', path: snapshot.path, expectedSize: snapshot.size, expectedSha256: snapshot.sha256 });
    const download = await agent('storage/transfers', { ticketId: downloadTicket.id, token: downloadTicket.token });
    assert.equal(new URL(download.request.url).origin, requestUrl.origin);
    const stored = await client.get(download.request.url, { headers: download.request.headers })
      .catch(() => { throw new Error('Signed Classroom S3 download did not reach the isolated service'); });
    assert.ok(stored.ok());
    assert.deepEqual(await stored.body(), bytes);
    await close(first);

    await refresh();
    await dialog.getByRole('button', { name: 'Назначить ученика' }).click();
    await page.locator('.edu-roster-item').filter({ hasText: 'Илья Гилёв' }).click();
    await page.getByRole('button', { name: 'Начать подготовку' }).click();
    await card.getByText('зарезервирован', { exact: true }).waitFor();
    const second = (await deviceDetails()).sessions[0];
    assert.equal(second.studentId, next.id);
    assert.notEqual(second.id, first.id);
    if (!(await dialog.isVisible())) await card.getByRole('button', { name: 'Подробнее', exact: true }).click();
    await activate(second);
    await refresh();
    await confirmAction('Завершить сессию', '/session/end');
    await acknowledge(second, 'end_session');
    await state(second, 'ENDING');
    await state(second, 'SYNCING_OUT', { sessionEnded: true });
    await state(second, 'STORAGE_CONFIRMED', saved, 409);
    await agent('storage/sync', { version: 0, entries: [] });
    await close(second);
    await teacher.reopen();
    await card.getByRole('button', { name: 'Журнал компьютера' }).click();
    const history = page.getByRole('dialog', { name: 'Журнал компьютера' });
    await history.getByText('Илья Гилёв', { exact: false }).first().waitFor();
    await history.getByText('Мира', { exact: false }).first().waitFor();
    mkdirSync('screenshots/education-real', { recursive: true });
    await page.screenshot({ path: 'screenshots/education-real/classroom-lifecycle.png', animations: 'disabled' });
    console.log('Verified Classroom lifecycle, persistent recovery, repeat delivery, real S3 bytes, reassignment and history using an isolated synthetic Agent.');
  } finally {
    await client.dispose();
  }
}
