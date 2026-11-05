import { beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { ApiResponseError } from '@/shared/api/errors';
import { educationApi } from './api';

vi.mock('@/shared/api/core/adminHttp', () => ({ coreAdminHttp: { request: vi.fn(), binary: vi.fn() } }));
const id = '01900000-0000-7000-8000-000000000001';
const header = { displayName: 'Teacher', avatarFileId: null, personId: id, date: '2026-11-06', now: '2026-11-06T12:00:00Z', greeting: 'afternoon', locale: 'ru-RU', timezone: 'Asia/Yekaterinburg', unreadCount: 0 };

describe('Education response boundary', () => {
  beforeEach(() => vi.mocked(coreAdminHttp.request).mockReset());
  it('accepts genuine empty data and carries the explicit organization scope', async () => {
    vi.mocked(coreAdminHttp.request).mockResolvedValue(null);
    await expect(educationApi.next(id)).resolves.toBeNull();
    expect(coreAdminHttp.request).toHaveBeenCalledWith('/education/lessons/next', expect.objectContaining({ headers: { 'X-Education-Organization': id } }));
  });
  it('rejects a different date or range instead of showing it as the selected day', async () => {
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ date: '2026-11-05', timezone: 'UTC', items: [] });
    await expect(educationApi.timeline(id, '2026-11-06')).rejects.toBeInstanceOf(ApiResponseError);
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ from: '2026-11-01', to: '2026-11-07', timezone: 'UTC', lessons: [], events: [] });
    await expect(educationApi.schedule(id, '2026-11-02', '2026-11-08')).rejects.toBeInstanceOf(ApiResponseError);
  });
  it.each([{ date: '2026-02-30' }, { timezone: 'not/a-zone' }, { locale: 'invalid locale' }, { unreadCount: -1 }])('rejects malformed header fields %j', async patch => {
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ ...header, ...patch });
    await expect(educationApi.header(id)).rejects.toBeInstanceOf(ApiResponseError);
  });
  it('rejects path injection before issuing a request', () => {
    expect(() => educationApi.lesson(id, '../../auth/sessions')).toThrow();
    expect(coreAdminHttp.request).not.toHaveBeenCalled();
  });
});
