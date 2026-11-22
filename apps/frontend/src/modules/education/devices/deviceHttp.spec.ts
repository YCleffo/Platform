import { afterEach, expect, it, vi } from 'vitest';
import { coreAdminSession } from '@/core/admin/session';
import { deviceApi } from './api';

afterEach(() => {
  vi.unstubAllGlobals();
  coreAdminSession.clear();
});

it('sends one /api prefix and serializes device mutation objects once through the Core client', async () => {
  const org = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
  const id = '11111111-2222-3333-4444-555555555555';
  const device = {
    id, name: 'Classroom PC', number: '5', status: 'OFFLINE', enrollmentState: 'pending',
    lastSeenAt: null, classroomId: null, classroomName: null, createdAt: '2026-03-08T10:00:00Z',
  };
  const fetch = vi.fn<typeof globalThis.fetch>().mockImplementation(async () => Response.json({ status: 'success', data: device }));
  vi.stubGlobal('fetch', fetch);
  coreAdminSession.set({ accessToken: 'core-access', refreshToken: 'refresh', accessExpiresAt: '', sessionExpiresAt: '', sessionId: id });

  const payload = { name: device.name, number: device.number };
  await expect(deviceApi.createDevice(org, payload)).resolves.toMatchObject({ id });
  await expect(deviceApi.updateDevice(org, id, payload)).resolves.toMatchObject({ id });

  const [create, update] = fetch.mock.calls;
  expect(new URL(String(create?.[0])).pathname).toBe('/api/device-management/devices');
  expect(new URL(String(update?.[0])).pathname).toBe(`/api/device-management/devices/${id}`);
  expect([create?.[1]?.method, update?.[1]?.method]).toEqual(['POST', 'PUT']);
  for (const [, options] of fetch.mock.calls) {
    expect(JSON.parse(String(options?.body))).toEqual(payload);
    expect(new Headers(options?.headers).get('X-Education-Organization')).toBe(org);
    expect(new Headers(options?.headers).get('Authorization')).toBe('Bearer core-access');
  }
});
