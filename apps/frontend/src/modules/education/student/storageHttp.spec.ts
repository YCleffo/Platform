import { afterEach, expect, it, vi } from 'vitest';
import { coreAdminSession } from '@/core/admin/session';
import { storageApi } from './storage';

afterEach(() => {
  vi.unstubAllGlobals();
  coreAdminSession.clear();
});

it('uses the real Core client to send one /api prefix and an object as the ticket JSON', async () => {
  const org = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
  const id = '11111111-2222-3333-4444-555555555555';
  const fetch = vi.fn<typeof globalThis.fetch>().mockResolvedValue(Response.json({
    status: 'success',
    data: { id, spaceId: id, purpose: 'upload', path: 'notes.txt', token: 'ticket', expiresAt: '2099-01-01T00:00:00Z' },
  }));
  vi.stubGlobal('fetch', fetch);
  coreAdminSession.set({ accessToken: 'core-access', refreshToken: 'refresh', accessExpiresAt: '', sessionExpiresAt: '', sessionId: id });

  await storageApi.ticket(org, 'upload', 'notes.txt', { expectedSize: 5 });

  const [url, options] = fetch.mock.calls[0]!;
  expect(new URL(String(url)).pathname).toBe('/api/storage/tickets');
  expect(JSON.parse(String(options?.body))).toEqual({ purpose: 'upload', path: 'notes.txt', expectedSize: 5 });
  expect(new Headers(options?.headers).get('X-Education-Organization')).toBe(org);
  expect(new Headers(options?.headers).get('Authorization')).toBe('Bearer core-access');
});
