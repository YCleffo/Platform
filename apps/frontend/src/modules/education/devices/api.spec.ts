import { describe, expect, it, vi } from 'vitest';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { ApiResponseError } from '@/shared/api/errors';
import { deviceApi } from './api';

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
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith('/api/device-management/devices?offset=0', expect.objectContaining({ headers: { 'X-Education-Organization': org } }));
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ items: [{ ...device, status: 'NONSENSE' }], limit: 50, offset: 0, nextOffset: null });
    await expect(deviceApi.devices(org)).rejects.toBeInstanceOf(ApiResponseError);
  });
  it('uses POST operations for lifecycle actions', async () => {
    vi.mocked(coreAdminHttp.request).mockResolvedValue(device);
    await deviceApi.createDevice(org, { name: 'PC 2', number: '2' });
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith('/api/device-management/devices', expect.objectContaining({ method: 'POST', body: JSON.stringify({ name: 'PC 2', number: '2' }) }));
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ id, deviceId: id, code: 'abc', expiresAt: '2026-03-08T10:15:00.000000+00:00' });
    await expect(deviceApi.enroll(org, id)).resolves.toMatchObject({ code: 'abc' });
    const session = { id, deviceId: id, studentId: id, studentPersonId: id, studentName: 'Student Test', status: 'AUTHORIZED', desiredStatus: 'AUTHORIZED', startedAt: '2026-03-08T10:00:00.000000+00:00' };
    vi.mocked(coreAdminHttp.request).mockResolvedValue(session);
    await deviceApi.startSession(org, id, id);
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith(`/api/device-management/devices/${id}/session`, expect.objectContaining({ method: 'POST', body: JSON.stringify({ studentId: id }) }));
  });
  it('loads device detail with sessions', async () => {
    vi.mocked(coreAdminHttp.request).mockResolvedValue({
      ...device,
      sessions: [{ id, deviceId: id, studentId: id, studentPersonId: id, studentName: 'Student Test', status: 'ACTIVE', desiredStatus: null, startedAt: '2026-03-08T10:00:00.000000+00:00' }],
    });
    const detail = await deviceApi.device(org, id);
    expect(detail.sessions[0]?.status).toBe('ACTIVE');
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ ...device, sessions: 'broken' });
    await expect(deviceApi.device(org, id)).rejects.toBeInstanceOf(ApiResponseError);
  });
});
