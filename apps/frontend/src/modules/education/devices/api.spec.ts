import { describe, expect, it, vi } from 'vitest';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { ApiResponseError } from '@/shared/api/errors';
import { canDevice, deviceApi } from './api';

vi.mock('@/shared/api/core/adminHttp', () => ({
  coreAdminHttp: {
    request: vi.fn(async () => ({})),
  },
}));

const id = '11111111-2222-3333-4444-555555555555';
const org = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
const device = {
  id,
  name: 'PC 1',
  number: '1',
  classroomId: null,
  classroomName: null,
  status: 'OFFLINE',
  enrollmentState: 'pending',
  lastSeenAt: null,
  createdAt: '2026-03-08T10:00:00.000000+00:00',
};

describe('device management api', () => {
  it('lists devices and validates responses', async () => {
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ items: [device], limit: 50, offset: 0, nextOffset: null });
    const page = await deviceApi.devices(org);
    expect(page.items[0]?.status).toBe('OFFLINE');
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith('/device-management/devices?offset=0', expect.objectContaining({ headers: { 'X-Education-Organization': org } }));
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ items: [{ ...device, status: 'NONSENSE' }], limit: 50, offset: 0, nextOffset: null });
    await expect(deviceApi.devices(org)).rejects.toBeInstanceOf(ApiResponseError);
  });
  it('uses POST operations for lifecycle actions', async () => {
    vi.mocked(coreAdminHttp.request).mockResolvedValue(device);
    await deviceApi.createDevice(org, { name: 'PC 2', number: '2' });
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith('/device-management/devices', expect.objectContaining({ method: 'POST', body: { name: 'PC 2', number: '2' } }));
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ id, deviceId: id, code: 'abc', expiresAt: '2026-03-08T10:15:00.000000+00:00' });
    await expect(deviceApi.enroll(org, id)).resolves.toMatchObject({ code: 'abc' });
    const session = { id, deviceId: id, studentId: id, studentPersonId: id, studentName: 'Student Test', status: 'AUTHORIZED', desiredStatus: 'AUTHORIZED', startedAt: '2026-03-08T10:00:00.000000+00:00' };
    vi.mocked(coreAdminHttp.request).mockResolvedValue(session);
    await deviceApi.startSession(org, id, id);
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith(`/device-management/devices/${id}/session`, expect.objectContaining({ method: 'POST', body: { studentId: id } }));
  });
  it('loads device detail with sessions', async () => {
    vi.mocked(coreAdminHttp.request).mockResolvedValue({
      ...device,
      stateVersion: 1, availableActions: ['end_session'], commands: [], progress: null, lastError: null, recovery: null,
      sessions: [{ id, deviceId: id, studentId: id, studentPersonId: id, studentName: 'Student Test', status: 'ACTIVE', desiredStatus: null, startedAt: '2026-03-08T10:00:00.000000+00:00' }],
    });
    const detail = await deviceApi.device(org, id);
    expect(detail.sessions[0]?.status).toBe('ACTIVE');
    expect(canDevice(detail, 'end_session')).toBe(true);
    expect(canDevice(detail, 'assign')).toBe(false);
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ ...device, sessions: 'broken' });
    await expect(deviceApi.device(org, id)).rejects.toBeInstanceOf(ApiResponseError);
  });
  it('supports classroom management and device updates', async () => {
    const classroom = { id, name: 'Room 101', createdAt: '2026-03-08T10:00:00.000000+00:00' };
    vi.mocked(coreAdminHttp.request).mockResolvedValue(classroom);
    await deviceApi.updateClassroom(org, id, 'Room 102');
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith(
      `/device-management/classrooms/${id}`,
      expect.objectContaining({ method: 'PUT', body: { name: 'Room 102' } }),
    );
    await deviceApi.archiveClassroom(org, id);
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith(
      `/device-management/classrooms/${id}/archive`,
      expect.objectContaining({ method: 'POST' }),
    );
    vi.mocked(coreAdminHttp.request).mockResolvedValue(device);
    await deviceApi.updateDevice(org, id, { name: 'PC 1 Renamed', number: '10' });
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith(
      `/device-management/devices/${id}`,
      expect.objectContaining({ method: 'PUT', body: { name: 'PC 1 Renamed', number: '10' } }),
    );
  });
  it('loads every classroom page without a hidden selection limit', async () => {
    const classroom = { id, name: 'Room', createdAt: '2026-03-08T10:00:00Z' };
    vi.mocked(coreAdminHttp.request).mockResolvedValueOnce({ items: [classroom], limit: 50, offset: 0, nextOffset: 50 }).mockResolvedValueOnce({ items: [{ ...classroom, id: org }], limit: 50, offset: 50, nextOffset: null });
    expect((await deviceApi.allClassrooms(org)).items).toHaveLength(2);
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith('/device-management/classrooms?offset=50', expect.anything());
  });
  it('rejects a detail without authoritative actions and malformed progress', async () => {
    const detail = { ...device, sessions: [], stateVersion: 1, availableActions: [], commands: [], progress: null, lastError: null, recovery: null };
    vi.mocked(coreAdminHttp.request).mockResolvedValue(detail);
    expect(canDevice(await deviceApi.device(org, id), 'assign')).toBe(false);
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ ...detail, progress: { stage: 'sync_in', completed: 5, total: 1 } });
    await expect(deviceApi.device(org, id)).rejects.toBeInstanceOf(ApiResponseError);
  });
  it('sends the observed state version when cancelling or retrying', async () => {
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ sessionId: id, cancelled: true });
    await deviceApi.cancelAssignment(org, id, id, 4);
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith(`/device-management/devices/${id}/session/cancel`, expect.objectContaining({ body: { sessionId: id, stateVersion: 4 } }));
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ sessionId: id, retrying: true });
    await deviceApi.retryRecovery(org, id, 5);
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith(`/device-management/devices/${id}/recovery/retry`, expect.objectContaining({ body: { stateVersion: 5 } }));
  });
  it('loads operator event and command pages and tolerates PHP empty JSON payloads', async () => {
    const event = { id, kind: 'boot', payload: [], createdAt: device.createdAt };
    const signal = new AbortController().signal;
    const envelope = { items: [event], limit: 50, offset: 50, nextOffset: null };
    vi.mocked(coreAdminHttp.request).mockResolvedValueOnce(envelope);
    await expect(deviceApi.events(org, id, 50, signal)).resolves.toEqual(envelope);
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith(
      `/device-management/devices/${id}/events?offset=50`,
      expect.objectContaining({ method: 'GET', signal, headers: { 'X-Education-Organization': org } }),
    );
    const command = { ...event, kind: 'start_session', status: 'ACKED', expiresAt: device.createdAt, acknowledgedAt: null };
    vi.mocked(coreAdminHttp.request).mockResolvedValueOnce({ ...envelope, items: [command] });
    await expect(deviceApi.commands(org, id, 50, signal)).resolves.toMatchObject({ items: [command] });
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith(
      `/device-management/devices/${id}/commands?offset=50`,
      expect.objectContaining({ method: 'GET', signal }),
    );
    vi.mocked(coreAdminHttp.request).mockResolvedValueOnce({ ...envelope, items: [{ ...event, payload: ['invalid'] }] });
    await expect(deviceApi.events(org, id)).rejects.toBeInstanceOf(ApiResponseError);
  });
});
